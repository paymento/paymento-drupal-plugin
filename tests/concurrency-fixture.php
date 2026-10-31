<?php
use Drupal\Core\Site\Settings;
use Symfony\Component\HttpFoundation\Request;
if (!Settings::get('commerce_paymento_test_mode', FALSE)) { throw new RuntimeException('Isolated concurrency fixture only.'); }
$db = \Drupal::database();
$action = getenv('PAYMENTO_CONCURRENCY_ACTION') ?: 'inspect';
function concurrency_receipt(array $a, int $status): int {
  $response = \Drupal::httpClient()->post('http://mock:3000/__control', ['json' => ['token' => $a['token'], 'status' => $status]]);
  if ($response->getStatusCode() !== 200) { throw new RuntimeException('Synthetic token update failed.'); }
  $gateway = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway')->load('paymento_fixture');
  $raw = json_encode(['Token' => $a['token'], 'OrderId' => $a['external_id'], 'OrderStatus' => $status]);
  $request = Request::create('/', 'POST', [], [], [], ['HTTP_X_HMAC_SHA256_SIGNATURE' => strtoupper(hash_hmac('sha256', $raw, 'synthetic-ipn-secret'))], $raw);
  if (\Drupal::service('commerce_paymento.manager')->receive($gateway, $request)->getStatusCode() !== 200) { throw new RuntimeException('Synthetic receipt was not accepted.'); }
  return (int) \Drupal::database()->select('commerce_paymento_inbox', 'i')->fields('i', ['id'])->condition('attempt_id', $a['id'])->orderBy('id', 'DESC')->range(0, 1)->execute()->fetchField();
}
if ($action === 'setup') {
  require '/paymento-tests/bootstrap.php';
  if (!$db->schema()->tableExists('paymento_concurrency_events')) {
    $db->schema()->createTable('paymento_concurrency_events', ['fields' => ['id' => ['type' => 'serial', 'not null' => TRUE], 'order_id' => ['type' => 'int', 'not null' => TRUE], 'kind' => ['type' => 'varchar_ascii', 'length' => 32, 'not null' => TRUE]], 'primary key' => ['id']]);
  }
  $same = fixture_attempt(fixture_order());
  $same['row'] = concurrency_receipt($same, 7);
  $same['duplicate_row'] = concurrency_receipt($same, 7);
  $order = fixture_order();
  $first = fixture_attempt($order);
  $second = fixture_attempt($order, TRUE);
  $first['row'] = concurrency_receipt($first, 7);
  $second['row'] = concurrency_receipt($second, 7);
  $crash = fixture_attempt(fixture_order());
  $crash['row'] = concurrency_receipt($crash, 7);
  echo 'CONCURRENCY_JSON ' . json_encode(compact('same', 'first', 'second', 'crash')) . PHP_EOL;
  return;
}
$orderId = (int) getenv('PAYMENTO_CONCURRENCY_ORDER');
if ($action === 'refund-loser') {
  $a = $db->select('commerce_paymento_attempt', 'a')->fields('a')->condition('id', (int) getenv('PAYMENTO_CONCURRENCY_ATTEMPT'))->execute()->fetchAssoc();
  $row = concurrency_receipt($a, 10);
  \Drupal::service('commerce_paymento.manager')->process($row);
}
$order = \Drupal::entityTypeManager()->getStorage('commerce_order')->loadUnchanged($orderId);
$payments = \Drupal::entityTypeManager()->getStorage('commerce_payment')->loadByProperties(['order_id' => $orderId]);
$states = [];
foreach ($payments as $payment) { $states[] = ['id' => (int) $payment->id(), 'state' => $payment->getState()->getId(), 'completed' => $payment->isCompleted()]; }
$parent = $db->select('commerce_paymento_order', 'p')->fields('p')->condition('order_id', $orderId)->execute()->fetchAssoc();
$query = $db->select('commerce_paymento_inbox', 'i')->fields('i', ['id', 'verified', 'processed', 'last_error']);
$query->join('commerce_paymento_attempt', 'a', 'a.id=i.attempt_id');
$rows = $query->condition('a.order_id', $orderId)->execute()->fetchAll(\PDO::FETCH_ASSOC);
$events = $db->select('paymento_concurrency_events', 'e')->fields('e', ['kind'])->condition('order_id', $orderId)->execute()->fetchCol();
$lock = $db->select('semaphore', 's')->fields('s', ['expire'])->condition('name', 'commerce_order_update:' . $orderId)->execute()->fetchField();
echo 'CONCURRENCY_JSON ' . json_encode(['order' => ['id' => $orderId, 'paid' => $order->isPaid(), 'state' => $order->getState()->getId(), 'placed' => $order->getPlacedTime(), 'number' => $order->getOrderNumber(), 'paid_event' => $order->getData('paid_event_dispatched'), 'total_paid' => $order->getTotalPaid()->getNumber()], 'payments' => $states, 'parent' => $parent, 'rows' => $rows, 'events' => array_count_values($events), 'native_lock_expire' => $lock === FALSE ? NULL : (float) $lock]) . PHP_EOL;
