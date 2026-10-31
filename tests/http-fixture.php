<?php
use Drupal\Core\Site\Settings;
if (!Settings::get('commerce_paymento_test_mode', FALSE)) { throw new RuntimeException('Isolated HTTP fixture only.'); }
$action=getenv('PAYMENTO_HTTP_ACTION') ?: 'metadata';
$db=\Drupal::database(); $entities=\Drupal::entityTypeManager();
if($action==='customer'){
 $name=getenv('PAYMENTO_HTTP_CUSTOMER') ?: 'paymento-http-customer';
 if(!preg_match('/^paymento-http-customer-[a-z0-9-]+$/',$name)){throw new RuntimeException('Isolated HTTP customer name required.');}
 $users=$entities->getStorage('user')->loadByProperties(['name'=>$name]); $user=reset($users);
 if(!$user){$user=\Drupal\user\Entity\User::create(['name'=>$name,'mail'=>$name.'@example.invalid','status'=>1]);$user->setPassword('synthetic-http-customer-password');$user->save();}
 echo 'HTTP_JSON '.json_encode(['uid'=>(int)$user->id()]).PHP_EOL;return;
}
if($action==='metadata'){
 $products=$entities->getStorage('commerce_product')->loadByProperties(['title'=>'Paymento synthetic product']);
 $product=reset($products);
 $gateway=$entities->getStorage('commerce_payment_gateway')->load('paymento_fixture');
 echo 'HTTP_JSON '.json_encode(['product_id'=>(int)$product->id(),'product_url'=>$product->toUrl()->toString(),'notify_url'=>parse_url($gateway->getPlugin()->getNotifyUrl()->toString(),PHP_URL_PATH)]).PHP_EOL; return;
}
$token=getenv('PAYMENTO_HTTP_TOKEN') ?: '';
if($action==='configuration'){
 $g=$entities->getStorage('commerce_payment_gateway')->load('paymento_fixture');
 echo 'HTTP_JSON '.json_encode($g->getPluginConfiguration()).PHP_EOL;return;
}
if($action==='order'){
 $count=(int)$db->select('commerce_paymento_attempt','a')->condition('order_id',(int)getenv('PAYMENTO_HTTP_ORDER'))->countQuery()->execute()->fetchField();
 echo 'HTTP_JSON '.json_encode(['attempts'=>$count]).PHP_EOL;return;
}
$attempt=$db->select('commerce_paymento_attempt','a')->fields('a')->condition('token',$token)->execute()->fetchAssoc();
if(!$attempt){throw new RuntimeException('Unknown isolated attempt.');}
if($action==='process'){
 $rows=$db->select('commerce_paymento_inbox','i')->fields('i',['id'])->condition('attempt_id',$attempt['id'])->isNull('processed')->execute()->fetchCol();
 foreach($rows as $id){\Drupal::service('commerce_paymento.manager')->process((int)$id);}
}
$order=$entities->getStorage('commerce_order')->loadUnchanged($attempt['order_id']);
$payment=$entities->getStorage('commerce_payment')->loadUnchanged($attempt['payment_id']);
$pending=(int)$db->select('commerce_paymento_inbox','i')->condition('attempt_id',$attempt['id'])->isNull('processed')->countQuery()->execute()->fetchField();
$payments=$entities->getStorage('commerce_payment')->loadByProperties(['order_id'=>$order->id()]);
echo 'HTTP_JSON '.json_encode(['attempt'=>$attempt,'order'=>['id'=>(int)$order->id(),'state'=>$order->getState()->getId(),'paid'=>$order->isPaid(),'total'=>$order->getTotalPrice()->getNumber(),'total_paid'=>$order->getTotalPaid()->getNumber(),'email'=>$order->getEmail(),'uid'=>(int)$order->getCustomerId()],'payment'=>['id'=>(int)$payment->id(),'state'=>$payment->getState()->getId(),'amount'=>$payment->getAmount()->getNumber()],'payment_count'=>count($payments),'pending'=>$pending]).PHP_EOL;

