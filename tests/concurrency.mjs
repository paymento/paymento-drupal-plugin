import {spawn} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const cwd=fileURLToPath(new URL('../',import.meta.url));
let checks=0;
const active=new Set();
const assert=(value,label)=>{if(!value)throw new Error(label);checks++;console.log('PASS '+label);};
function start(args){
 const child=spawn('docker',['compose',...args],{cwd,windowsHide:true,stdio:['ignore','pipe','pipe']});
 const handle={child,pid:null};active.add(handle);
 let output='',errors='',barrier;
 let resolveBarrier,rejectBarrier;
 const ready=new Promise((resolve,reject)=>{resolveBarrier=resolve;rejectBarrier=reject;});ready.catch(()=>{});
 child.stdout.on('data',chunk=>{output+=chunk.toString();for(const line of output.split(/\r?\n/)){if(line.startsWith('CONCURRENCY_STARTED ')){try{handle.pid=JSON.parse(line.slice(20)).pid;}catch{}}if(line.startsWith('CONCURRENCY_BARRIER ')){try{barrier=JSON.parse(line.slice(20));resolveBarrier(barrier);}catch{}}}});
 child.stderr.on('data',chunk=>errors+=chunk.toString());
 const done=new Promise((resolve,reject)=>{child.on('error',reject);child.on('close',code=>{active.delete(handle);if(!barrier)rejectBarrier(new Error('Worker exited before barrier: '+output+errors));resolve({code,output,errors});});});
 return {child,ready,done};
}
const envArgs=env=>Object.entries(env).flatMap(([key,value])=>['-e',key+'='+value]);
const drush=(file,env={})=>start(['exec','-T',...envArgs(env),'drupal','vendor/bin/drush','php:script','/paymento-tests/'+file]);
async function run(file,env={}){const result=await drush(file,env).done;if(result.code!==0)throw new Error(result.output+result.errors);return result.output;}
const json=(output,prefix='CONCURRENCY_JSON ')=>(JSON.parse(output.split(/\r?\n/).filter(line=>line.startsWith(prefix)).at(-1)?.slice(prefix.length)??'null'));
const inspect=a=>run('concurrency-fixture.php',{PAYMENTO_CONCURRENCY_ORDER:a.order_id}).then(json);
async function barrierWithin(w){let timer;try{return await Promise.race([w.ready,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Native payment barrier not reached within 45s')),45000);})]);}finally{clearTimeout(timer);}}
const worker=(a,row=a.row,pause=false)=>drush('concurrency-worker.php',{PAYMENTO_CONCURRENCY_ORDER:a.order_id,PAYMENTO_CONCURRENCY_ROW:row,PAYMENTO_CONCURRENCY_PAUSE:pause?'1':'0'});
const result=async w=>{const r=await w.done;if(r.code!==0)throw new Error(r.output+r.errors);return json(r.output,'CONCURRENCY_RESULT ');};
async function release(marker){const r=await start(['exec','-T','drupal','touch',marker.release]).done;if(r.code!==0)throw new Error(r.errors);}
function singleGrant(state,label){
 assert(state.order.paid&&state.order.state!=='draft'&&Number(state.order.placed)>0&&!!state.order.number&&state.order.paid_event===true,label+' native notify-only paid placement');
 assert(state.payments.filter(p=>p.completed).length===1&&state.events.paid===1&&state.events.placed===1&&state.events.payment_completed===1,label+' one native payment, paid event and placement');
 assert(state.rows.every(r=>r.processed!==null),label+' durable inbox drained');
}
try {
const data=json(await run('concurrency-fixture.php',{PAYMENTO_CONCURRENCY_ACTION:'setup'}));
// Same receipt, independent OS processes. The first is paused after SQL Payment update.
let first=worker(data.same,data.same.row,true);let marker=await barrierWithin(first);
let busy=await result(worker(data.same));
assert(busy.ok===false&&busy.error==='order_busy'&&busy.pid!==marker.pid,'same-inbox independent worker contends on real DB advisory lock');
await release(marker);assert((await result(first)).ok,'first same-inbox worker commits');
assert((await result(worker(data.same))).ok,'same-inbox replay is harmless');
assert((await result(worker(data.same,data.same.duplicate_row))).ok,'independent duplicate receipt drains');
singleGrant(await inspect(data.same),'same-inbox');
// Two tokens for one order contend on the same parent lock, then loser stays reviewable.
first=worker(data.first,data.first.row,true);marker=await barrierWithin(first);
busy=await result(worker(data.second));
assert(!busy.ok&&busy.error==='order_busy'&&busy.pid!==marker.pid,'different-token independent worker contends on same parent lock');
await release(marker);assert((await result(first)).ok,'winning token commits');
const loser=await result(worker(data.second));
assert(!loser.ok&&loser.error==='duplicate_paid_review','second paid token remains explicit manual-review work: '+JSON.stringify(loser));
let state=await inspect(data.first);
assert(state.rows.some(r=>r.processed===null&&r.last_error==='duplicate_paid_review'),'duplicate review persisted in durable inbox');
await run('concurrency-fixture.php',{PAYMENTO_CONCURRENCY_ACTION:'refund-loser',PAYMENTO_CONCURRENCY_ORDER:data.first.order_id,PAYMENTO_CONCURRENCY_ATTEMPT:data.second.id});
assert((await result(worker(data.second))).ok,'refunded losing-token cached approval drains without grant');
singleGrant(await inspect(data.first),'two-token');
// Kill the PHP process after native SQL mutation but before the module transaction commits.
first=worker(data.crash,data.crash.row,true);marker=await barrierWithin(first);
const killed=await start(['exec','-T','drupal','sh','-c','kill -KILL "$1"','paymento-concurrency',String(marker.pid)]).done;
assert(killed.code===0,'real OS SIGKILL delivered inside native payment transaction');
const exit=await first.done;assert(exit.code!==0,'killed Drush process did not exit successfully');
state=await inspect(data.crash);
assert(!state.order.paid&&state.payments.every(p=>!p.completed)&&Object.keys(state.events).length===0,'SIGKILL rolls back native payment and test event SQL');
assert(Number(state.parent.winning_attempt)===Number(data.crash.id)&&state.rows.some(r=>r.verified!==null&&r.processed===null),'canonical verified proof and reserved winner survive real process death');
// Drupal semaphore ownership is lease-based; do not delete or force another worker lock.
if(state.native_lock_expire){const wait=Math.max(0,state.native_lock_expire*1000-Date.now()+250);if(wait>35000)throw new Error('Unexpected native semaphore expiry');if(wait)await new Promise(resolve=>setTimeout(resolve,wait));}
assert((await result(worker(data.crash))).ok,'fresh worker recovers after native semaphore lease expires');
singleGrant(await inspect(data.crash),'SIGKILL recovery');
console.log(JSON.stringify({concurrency_passed:checks,independent_process_cases:3,real_sigkill_cases:1}));
} catch(error) {
 console.error(error.stack??String(error));process.exitCode=1;
} finally {
 // Kill only this orchestrator's known PHP workers, then close its Docker clients.
 // In particular, do not force-release or delete any Drupal semaphore row.
 for(const handle of [...active]){
  if(!active.has(handle))continue;
  if(handle.pid){const stop=start(['exec','-T','drupal','sh','-c','kill -KILL "$1"','paymento-concurrency',String(handle.pid)]);let timer;try{await Promise.race([stop.done,new Promise(resolve=>{timer=setTimeout(resolve,5000);})]);}finally{clearTimeout(timer);stop.child.kill();}}
  handle.child.kill();
 }
}
