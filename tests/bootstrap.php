<?php
use Drupal\commerce_price\Price;
use Drupal\commerce_price\Entity\Currency;
use Drupal\commerce_store\Entity\Store;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\user\Entity\Role;
use Drupal\Core\Site\Settings;

if (!Settings::get('commerce_paymento_test_mode', FALSE)) { throw new RuntimeException('Isolated fixture only.'); }
global $store, $variation, $gateway, $product;
if (!Currency::load('USD')) { \Drupal::service('commerce_price.currency_importer')->import('USD'); }
$stores = \Drupal::entityTypeManager()->getStorage('commerce_store')->loadByProperties(['name' => 'Paymento Fixture']);
$store = $stores ? reset($stores) : Store::create(['type' => 'online', 'uid' => 1, 'name' => 'Paymento Fixture', 'mail' => 'merchant@example.invalid', 'default_currency' => 'USD', 'timezone' => 'America/Toronto', 'is_default' => TRUE, 'status' => TRUE, 'address' => ['country_code' => 'US', 'administrative_area' => 'NY', 'locality' => 'New York', 'postal_code' => '10001', 'address_line1' => '123 Synthetic Street']]);
if ($store->isNew()) { $store->save(); }
$gateway = PaymentGateway::load('paymento_fixture');
if (!$gateway) {
  $gateway = PaymentGateway::create(['id' => 'paymento_fixture', 'label' => 'Paymento Fixture', 'status' => TRUE, 'plugin' => 'paymento', 'configuration' => ['display_label' => 'Crypto with Paymento', 'mode' => 'test', 'environment' => 'production', 'credential_reference' => 'fixture', 'payment_method_types' => ['paymento_crypto'], 'collect_billing_information' => FALSE], 'conditions' => []]);
  $gateway->save();
}
$vars = \Drupal::entityTypeManager()->getStorage('commerce_product_variation')->loadByProperties(['sku' => 'PAYMENTO-FIXTURE']);
$variation = $vars ? reset($vars) : ProductVariation::create(['type' => 'default', 'sku' => 'PAYMENTO-FIXTURE', 'title' => 'Paymento fixture', 'status' => TRUE, 'price' => new Price('12.34', 'USD')]);
if ($variation->isNew()) {
  $variation->save();
  $product = Product::create(['type' => 'default', 'title' => 'Paymento synthetic product', 'stores' => [$store], 'variations' => [$variation], 'status' => TRUE, 'uid' => 1]);
  $product->save();
}
else { $product = $variation->getProduct(); }
$flow = \Drupal::entityTypeManager()->getStorage('commerce_checkout_flow')->load('default');
$configuration = $flow->getPlugin()->getConfiguration();
$configuration['panes']['contact_information']['double_entry'] = FALSE;
$configuration['panes']['billing_information']['step'] = '_disabled';
$configuration['panes']['payment_information']['step'] = 'order_information';
$configuration['panes']['payment_information']['weight'] = 2;
$configuration['panes']['payment_process']['step'] = 'payment';
$flow->set('configuration', $configuration); $flow->save();
foreach (['anonymous', 'authenticated'] as $rid) {
  $role = Role::load($rid); foreach (['access content', 'access checkout', 'view commerce_product'] as $permission) { $role->grantPermission($permission); } $role->save();
}
\Drupal::configFactory()->getEditable('system.performance')->set('css.preprocess', FALSE)->set('js.preprocess', FALSE)->save();
\Drupal::configFactory()->getEditable('system.cron')->set('threshold.autorun', 0)->save();

function fixture_order(string $price = '12.34') {
  global $store, $variation;
  $order = \Drupal\commerce_order\Entity\Order::create(['type' => 'default', 'store_id' => $store->id(), 'uid' => 0, 'cart' => TRUE, 'state' => 'draft']);
  $order->save();
  $order->setEmail('buyer@example.invalid');
  $item = \Drupal::service('commerce_cart.cart_manager')->addEntity($order, $variation, '1', FALSE);
  $item->setUnitPrice(new Price($price, 'USD'), TRUE); $item->save();
  $order->set('payment_gateway', 'paymento_fixture'); $order->set('checkout_flow', 'default');
  $order->setRefreshState(\Drupal\commerce_order\Entity\OrderInterface::REFRESH_SKIP); $order->save();
  return $order;
}
function fixture_attempt($order, bool $force = FALSE): array {
  $payment = \Drupal::entityTypeManager()->getStorage('commerce_payment')->create(['state' => 'new', 'amount' => $order->getBalance(), 'payment_gateway' => 'paymento_fixture', 'order_id' => $order->id()]);
  return \Drupal::service('commerce_paymento.manager')->initiate($payment, 'http://localhost:18100/checkout/' . $order->id() . '/payment/return', $force);
}
function fixture_fresh_order(int $id) { return \Drupal::entityTypeManager()->getStorage('commerce_order')->loadUnchanged($id); }
function fixture_payment(array $a) { $s = \Drupal::entityTypeManager()->getStorage('commerce_payment'); $s->resetCache([$a['payment_id']]); return $s->load($a['payment_id']); }
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === 'bootstrap.php') {
  echo json_encode(['drupal' => \Drupal::VERSION, 'commerce' => \Drupal::service('extension.list.module')->getExtensionInfo('commerce')['version'], 'php' => PHP_VERSION, 'product_id' => $product->id(), 'variation_id' => $variation->id(), 'store_id' => $store->id(), 'database' => \Drupal::database()->query('SELECT VERSION()')->fetchField()]) . PHP_EOL;
}
