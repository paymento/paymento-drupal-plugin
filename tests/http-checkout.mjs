import assert from 'node:assert/strict';
import {createHmac} from 'node:crypto';
import {spawnSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
const origin='http://localhost:18100', mock='http://localhost:18101';
const cwd=fileURLToPath(new URL('../',import.meta.url));
let checks=0;
function check(value,label){assert.ok(value,label);checks++;console.log(`PASS ${label}`);}
function fixture(action='metadata',extra={}){
 const args=['compose','exec','-T','-e',`PAYMENTO_HTTP_ACTION=${action}`];
 for(const[k,v]of Object.entries(extra))args.push('-e',`PAYMENTO_HTTP_${k}=${v}`);
 args.push('drupal','vendor/bin/drush','php:script','/paymento-tests/http-fixture.php');
 const result=spawnSync('docker',args,{cwd,encoding:'utf8',timeout:60000});
 assert.equal(result.status,0,result.stderr||result.stdout);
 const line=result.stdout.split(/\r?\n/).find(x=>x.startsWith('HTTP_JSON '));
 assert.ok(line,result.stdout);return JSON.parse(line.slice(10));
}
class Jar{
 cookies=new Map();
 async request(path,options={}){
  const r=await fetch(new URL(path,origin),{...options,redirect:'manual',headers:{...options.headers,cookie:[...this.cookies].map(([k,v])=>`${k}=${v}`).join('; ')}});
  for(const c of r.headers.getSetCookie()){const[k,...v]=c.split(';')[0].split('=');this.cookies.set(k,v.join('='));}
  return{status:r.status,location:r.headers.get('location'),html:await r.text()};
 }
 async follow(r){for(let n=0;r.location&&n<8;n++){assert.equal(new URL(r.location,origin).origin,origin,'Do not follow merchant-external redirect');r=await this.request(r.location);}return r;}
}
function attrs(s){return Object.fromEntries([...s.matchAll(/([\w:-]+)\s*=\s*"([^"]*)"/g)].map(x=>[x[1],x[2].replaceAll('&amp;','&')]));}
function forms(h){
 // Drupal streams authenticated lazy forms as BigPipe JSON insert commands.
 for(const script of [...h.matchAll(/<script\b[^>]*type="application\/vnd.drupal-ajax"[^>]*>([\s\S]*?)<\/script>/gi)]){
  if(script[1].trim())for(const command of JSON.parse(script[1]))if(command.command==='insert'&&typeof command.data==='string')h+='\n'+command.data;
 }
 return [...h.matchAll(/<form\b([^>]*)>([\s\S]*?)<\/form>/gi)].map(x=>({attrs:attrs(x[1]),body:x[2],fields:[...x[2].matchAll(/<input\b([^>]*)>/gi)].map(i=>attrs(i[1]))}));
}
function form(r,match){const f=forms(r.html).find(x=>match(x));assert.ok(f,'Expected native form');return f;}
function checkout(r){return form(r,x=>x.attrs.id==='commerce-checkout-flow-multistep-default');}
function data(f,values={}){const d=new URLSearchParams(f.fields.filter(x=>x.name&&x.value!==undefined&&!['submit','button','password','text','email'].includes(x.type)).map(x=>[x.name,x.value]));for(const[k,v]of Object.entries(values))d.set(k,v);return d;}
async function submit(j,f,values){return j.request(f.attrs.action,{method:'POST',headers:{'content-type':'application/x-www-form-urlencoded'},body:data(f,values)});}
async function login(j,name,password){const r=await j.request('/user/login');const f=form(r,x=>x.attrs.id==='user-login-form');const result=await submit(j,f,{name,pass:password,op:'Log in'});check([302,303].includes(result.status),'native authenticated login accepts synthetic account');await j.follow(result);}
const customerName=`paymento-http-customer-${Date.now()}-${Math.random().toString(36).slice(2,8)}`;
const metadata=fixture();fixture('customer',{CUSTOMER:customerName});
async function begin(j,authenticated=false){
 let r=await j.request(metadata.product_url);const f=form(r,x=>x.attrs.id?.includes('add-to-cart')||x.fields.some(v=>v.name==='form_id'&&v.value?.startsWith('commerce_order_item_add_to_cart_form')));
 check(Boolean(f.fields.find(x=>x.name==='form_build_id')),'native product form has session-bound form build ID');
 r=await submit(j,f,{op:'Add to cart'});check(r.status===303,'native product POST adds cart item');
 r=await j.request('/cart');r=await submit(j,form(r,x=>x.attrs.id?.startsWith('views-form-commerce-cart-form')),{op:'Checkout'});
 const id=Number(r.location.match(/checkout\/(\d+)/)[1]);r=await j.follow(r);
 if(!authenticated)r=await j.follow(await submit(j,checkout(r),{op:'Continue as Guest'}));
 r=await j.follow(await submit(j,checkout(r),{'contact_information[email]':authenticated?'http-customer@example.invalid':'guest-http@example.invalid',op:'Continue to review'}));
 check(r.status===200&&r.html.includes('Crypto with Paymento')&&r.html.includes('$12.34'),'native review displays Paymento and trusted product total');
 return{id,r};
}
async function pay(j,r){r=await submit(j,checkout(r),{op:'Pay and complete purchase'});check(r.status===303&&r.location.endsWith('/payment'),'review POST advances to native payment step');r=await j.request(r.location);const target=new URL(r.location);check(r.status===302&&target.origin===mock&&target.pathname==='/gateway'&&Boolean(target.searchParams.get('token')),'native offsite form redirects to hosted checkout with saved token');return target.searchParams.get('token');}
async function callback(a,status,method='POST',bad=false){
 await fetch(`${mock}/__control`,{method:'POST',headers:{'content-type':'application/json'},body:JSON.stringify({token:a.token,status})});
 const raw=JSON.stringify({Token:a.token,OrderId:a.external_id,OrderStatus:status});
 return fetch(new URL(metadata.notify_url,origin),{method,headers:{'content-type':'application/json','x-hmac-sha256-signature':bad?'bad':createHmac('sha256','synthetic-ipn-secret').update(raw).digest('hex').toUpperCase()},body:raw});
}
const guest=new Jar();const g=await begin(guest);const token=await pay(guest,g.r);let state=fixture('inspect',{TOKEN:token});
check(!state.order.paid&&state.order.state==='draft'&&state.payment.state==='new'&&Number(state.payment.amount)===12.34,'real HTTP initiation persists unpaid native order and new native payment');
let r=await guest.request(`/checkout/${g.id}/payment/return?status=8&token=${token}`);
check([302,303].includes(r.status)&&new URL(r.location,origin).pathname===`/paymento/status/${g.id}`,'forged paid browser return redirects to pending status');
check(!fixture('inspect',{TOKEN:token}).order.paid,'browser return cannot pay native order');
r=await new Jar().request(`/paymento/status/${g.id}`);check(r.status===403,'foreign anonymous session cannot read buyer status');
let response=await callback(state.attempt,7,'POST',true);check(response.status===401,'HTTP callback rejects invalid raw-byte signature');
check(fixture('inspect',{TOKEN:token}).pending===0,'invalid signature creates no durable work');
response=await callback(state.attempt,3);check(response.status===200,'signed POST callback accepted');state=fixture('inspect',{TOKEN:token});check(state.pending===1&&!state.order.paid,'callback queues durable receipt before worker and leaves order unpaid');
state=fixture('process',{TOKEN:token});check(!state.order.paid&&state.payment.state==='new','verified confirming status retains unpaid native payment');
response=await callback(state.attempt,7,'PUT');check(response.status===200,'signed PUT callback accepted');state=fixture('inspect',{TOKEN:token});check(!state.order.paid&&state.pending===1,'paid receipt alone does not mutate native payment');
state=fixture('process',{TOKEN:token});check(state.order.paid&&state.order.state!=='draft'&&state.payment.state==='completed'&&Number(state.order.total_paid)===12.34,'worker completes native payment and places native order without browser return');
await callback(state.attempt,8);state=fixture('process',{TOKEN:token});check(state.payment_count===1&&Number(state.order.total_paid)===12.34,'duplicate successful HTTP receipt does not create another native payment');
const customer=new Jar();await login(customer,customerName,'synthetic-http-customer-password');const c=await begin(customer,true);const cf=checkout(c.r);
check(Boolean(cf.fields.find(x=>x.name==='form_token')),'authenticated checkout form includes Drupal CSRF token');
r=await submit(customer,cf,{op:'Pay and complete purchase',form_token:'forged-token'});
check(r.status===200&&!r.location&&/outdated|invalid|try again/i.test(r.html),'tampered authenticated review CSRF token blocks payment progression');
check(fixture('order',{ORDER:c.id}).attempts===0,'CSRF rejection creates no remote attempt');
r=await customer.request(`/checkout/${c.id}/review`);const customerToken=await pay(customer,r);state=fixture('inspect',{TOKEN:customerToken});check(state.order.uid>1&&!state.order.paid,'authenticated native checkout persists customer-owned unpaid order');
const adminPath='/admin/commerce/config/payment-gateways/manage/paymento_fixture';
r=await new Jar().request(adminPath);check(r.status===403,'anonymous user cannot access gateway configuration');
r=await customer.request(adminPath);check(r.status===403,'ordinary authenticated customer cannot access gateway configuration');
const admin=new Jar();await login(admin,'paymento-test-admin','synthetic-local-admin-password');r=await admin.request(adminPath);
check(r.status===200&&r.html.includes('credential_reference')&&r.html.includes('environment'),'native administrator can edit gateway environment and credential reference');
check(!r.html.includes('synthetic-api-key')&&!r.html.includes('synthetic-ipn-secret'),'gateway configuration HTML contains no configured secret values');
check(Boolean(forms(r.html).some(f=>f.fields.some(x=>x.name==='form_token'))),'native administrator configuration form has CSRF protection');
const callbackPath='/admin/commerce/config/payment-gateways/paymento_fixture/paymento-callback';
r=await customer.request(callbackPath);check(r.status===403,'ordinary customer cannot access callback configuration action');
r=await admin.request(callbackPath);check(r.status===200&&Boolean(forms(r.html).some(f=>f.fields.some(x=>x.name==='form_token'))),'administrator callback configuration action requires native CSRF form');
check(!r.html.includes('synthetic-api-key')&&!r.html.includes('synthetic-ipn-secret'),'callback configuration action HTML contains no configured secret values');
console.log(JSON.stringify({checks,result:'passed',guest_order:g.id,customer_order:c.id,scope:'native HTTP + real Drupal Commerce database + Paymento mock; live deferred'}));
