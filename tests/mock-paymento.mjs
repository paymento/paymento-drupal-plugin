import {createServer} from 'node:http';
import {randomBytes} from 'node:crypto';
const payments=new Map();let mode='normal',verifies=0,settings={ipN_URL:'',ipN_Method:1};
const json=(r,s,b)=>{r.writeHead(s,{'content-type':'application/json'});r.end(JSON.stringify(b));};
createServer(async(req,res)=>{try{
 const chunks=[];for await(const c of req)chunks.push(c);const raw=Buffer.concat(chunks).toString();const b=raw?JSON.parse(raw):{};
 if(req.url==='/health')return json(res,200,{ready:true});
 if(req.url==='/__control'){
  if(b.reset){payments.clear();mode='normal';verifies=0;}if(b.mode)mode=b.mode;
  if(b.token){const p=payments.get(b.token);if(!p)return json(res,404,{error:'unknown'});for(const k of ['status','verifyToken','verifyOrderId','verifyAmount','missingAmount','missingSettlement'])if(k in b)p[k]=b[k];}
  return json(res,200,{ok:true});
 }
 if(req.url==='/__state')return json(res,200,{mode,verifies,payments:[...payments.values()]});
 if(req.url?.startsWith('/gateway')){res.writeHead(200,{'content-type':'text/html'});return res.end('<h1>Synthetic Paymento gateway</h1>');}
 if(req.headers['api-key']!=='synthetic-api-key')return json(res,401,{success:false});
 if(mode==='outage')return json(res,503,{success:false});
 if(req.url==='/v1/payment/request'){
  if(mode==='refuse')return json(res,200,{success:false,body:''});
  const token=randomBytes(16).toString('hex');payments.set(token,{token,orderId:b.orderId,amount:b.fiatAmount,currency:b.fiatCurrency,status:0});
  return json(res,200,{success:true,body:token});
 }
 if(req.url==='/v1/payment/verify'){
  verifies++;const p=payments.get(b.token);if(!p)return json(res,200,{success:false,body:{token:'',orderId:'',orderStatus:0}});
  if(p.status===7)p.status=8;
  return json(res,200,{success:p.status===8,body:{token:p.verifyToken??p.token,orderId:p.verifyOrderId??p.orderId,orderStatus:p.status,settlement:p.missingSettlement?undefined:[8,10].includes(p.status)?{requestedFiatAmount:p.missingAmount?null:Number(p.verifyAmount??p.amount)}:null}});
 }
 if(req.url==='/v1/payment/settings'){if(req.method==='POST')settings=b;return json(res,200,{success:true,body:settings});}
 json(res,404,{error:'not found'});
}catch{json(res,400,{error:'invalid request'});}}).listen(3000,'0.0.0.0');
