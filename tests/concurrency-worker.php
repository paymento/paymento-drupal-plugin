<?php
use Drupal\Core\Site\Settings;
use Drupal\commerce_payment\Event\PaymentEvents;
use Drupal\commerce_order\Event\OrderEvents;
if (!Settings::get('commerce_paymento_test_mode', FALSE)) { throw new RuntimeException('Isolated worker only.'); }
$row = (int) getenv('PAYMENTO_CONCURRENCY_ROW');
$orderId = (int) getenv('PAYMENTO_CONCURRENCY_ORDER');
$pause = getenv('PAYMENTO_CONCURRENCY_PAUSE') === '1';
$barrier = '/tmp/paymento-concurrency-release-' . $row;
echo 'CONCURRENCY_STARTED ' . json_encode(['pid' => getmypid()]) . PHP_EOL;
fflush(STDOUT);
$record = static function (string $kind) use ($orderId): void {
  \Drupal::database()->insert('paymento_concurrency_events')->fields(['order_id' => $orderId, 'kind' => $kind])->execute();
};
$dispatcher = \Drupal::service('event_dispatcher');
$dispatcher->addListener(OrderEvents::ORDER_PAID, static function ($event) use ($orderId, $record): void {
  if ((int) $event->getOrder()->id() === $orderId) { $record('paid'); }
}, -100);
$dispatcher->addListener('commerce_order.place.post_transition', static function ($event) use ($orderId, $record): void {
  if ((int) $event->getEntity()->id() === $orderId) { $record('placed'); }
}, -100);
$dispatcher->addListener(PaymentEvents::PAYMENT_UPDATE, static function ($event) use ($orderId, $record, &$pause, $barrier): void {
  $payment = $event->getPayment();
  if ((int) $payment->getOrderId() !== $orderId || $payment->getState()->getId() !== 'completed') { return; }
  $record('payment_completed');
  if ($pause) {
    $pause = FALSE;
    echo 'CONCURRENCY_BARRIER ' . json_encode(['pid' => getmypid(), 'order' => $orderId, 'release' => $barrier]) . PHP_EOL;
    fflush(STDOUT);
    $deadline = microtime(TRUE) + 55;
    while (!file_exists($barrier)) {
      if (microtime(TRUE) > $deadline) { throw new RuntimeException('Test barrier timeout.'); }
      usleep(100000);
    }
    unlink($barrier);
  }
});
try {
  \Drupal::service('commerce_paymento.manager')->process($row);
  echo 'CONCURRENCY_RESULT ' . json_encode(['ok' => TRUE, 'pid' => getmypid()]) . PHP_EOL;
}
catch (Throwable $error) {
  echo 'CONCURRENCY_RESULT ' . json_encode(['ok' => FALSE, 'error' => $error->getMessage(), 'pid' => getmypid()]) . PHP_EOL;
}
