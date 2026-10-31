<?php
namespace Drupal\commerce_paymento;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Site\Settings;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_price\Price;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/** Durable Paymento transport and native Commerce payment processor. */
final class PaymentoManager {
  public function __construct(private Connection $db, private EntityTypeManagerInterface $entities, private ClientInterface $http, private QueueFactory $queues) {}

  public function state(int $order): string {
    return $this->db->select('commerce_paymento_order', 'p')->fields('p', ['state'])->condition('order_id', $order)->execute()->fetchField() ?: 'pending';
  }
  private function credentials(PaymentGatewayInterface $gateway): array {
    $ref = $gateway->getPlugin()->getConfiguration()['credential_reference'] ?? '';
    $credentials = Settings::get('commerce_paymento_credentials', [])[$ref] ?? [];
    if (empty($credentials['api_key']) || empty($credentials['ipn_secret'])) { throw new \RuntimeException('credentials_missing'); }
    return $credentials;
  }
  private function bases(PaymentGatewayInterface $gateway): array {
    $api = 'https://api.paymento.io';
    $checkout = 'https://app.paymento.io/gateway';
    if (Settings::get('commerce_paymento_test_mode', FALSE)) {
      $api = Settings::get('commerce_paymento_api_base', $api);
      $checkout = Settings::get('commerce_paymento_checkout_base', $checkout);
    }
    return ['api' => rtrim($api, '/'), 'checkout' => $checkout];
  }
  private function api(PaymentGatewayInterface $gateway, string $method, string $path, ?array $body = NULL, ?string $base = NULL): array {
    $credentials = $this->credentials($gateway);
    $options = ['headers' => ['Api-Key' => $credentials['api_key'], 'Accept' => 'application/json'], 'timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => FALSE, 'http_errors' => FALSE];
    if ($body !== NULL) { $options['json'] = $body; }
    $response = $this->http->request($method, ($base ?? $this->bases($gateway)['api']) . $path, $options);
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) { throw new \RuntimeException('api_unavailable'); }
    $result = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
    if (!is_array($result) || !isset($result['success']) || !is_bool($result['success'])) { throw new \RuntimeException('api_invalid'); }
    return $result;
  }
  private function lock(int $order): string {
    $name = 'pdrupal_' . substr(hash('sha256', Settings::get('hash_salt') . ':' . $order), 0, 48);
    if ((string) $this->db->query('SELECT GET_LOCK(:name,0)', [':name' => $name])->fetchField() !== '1') { throw new \RuntimeException('order_busy'); }
    return $name;
  }
  private function unlock(string $name): void { $this->db->query('SELECT RELEASE_LOCK(:name)', [':name' => $name]); }
  private function assertSupportedOrder(OrderInterface $order): void {
    // Shipping/custom fulfillment adapters require their own immutable recipient snapshot.
    if (\Drupal::moduleHandler()->moduleExists('commerce_shipping') || $order->hasField('shipments')) { throw new \RuntimeException('unsupported_shipping'); }
  }
  private function fingerprint(OrderInterface $order): string {
    $items = [];
    foreach ($order->getItems() as $item) {
      $items[] = [$item->getPurchasedEntityId(), $item->getQuantity(), $item->getUnitPrice()?->getNumber(), $item->getUnitPrice()?->getCurrencyCode()];
    }
    sort($items);
    $profile = $order->getBillingProfile();
    $address = $profile && $profile->hasField('address') ? $profile->get('address')->getValue() : [];
    return hash('sha256', json_encode([$order->getStoreId(), $order->getCustomerId(), $order->getEmail(), $order->getTotalPrice()?->getNumber(), $order->getTotalPrice()?->getCurrencyCode(), $items, $address], JSON_THROW_ON_ERROR));
  }
  public static function decimal(mixed $value): string {
    if (!is_scalar($value) || !preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', (string) $value)) { throw new \RuntimeException('invalid_amount'); }
    $parts = explode('.', (string) $value, 2);
    $fraction = rtrim($parts[1] ?? '', '0');
    return (ltrim($parts[0], '0') ?: '0') . ($fraction !== '' ? '.' . $fraction : '');
  }

  /** Initiated by the actual Commerce offsite form after native checkout access. */
  public function initiate(PaymentInterface $payment, string $returnUrl, bool $forceNew = FALSE): array {
    $gateway = $payment->getPaymentGateway();
    if (!$gateway || !$gateway->status() || $gateway->getPluginId() !== 'paymento') { throw new \RuntimeException('gateway_unavailable'); }
    $this->credentials($gateway);
    $orderId = (int) $payment->getOrderId();
    $lock = $this->lock($orderId);
    $storage = $this->entities->getStorage('commerce_order');
    try {
      $order = $storage->loadForUpdate($orderId);
      if ($order) {
        $this->entities->getStorage('commerce_payment')->resetCache();
        (new \Drupal\commerce_payment\PaymentOrderUpdater($this->entities))->updateOrder($order, FALSE);
        $this->assertSupportedOrder($order);
      }
      if (!$order || !$order->getBalance() || !$order->getBalance()->isPositive() || !$order->getTotalPrice() || $order->get('payment_gateway')->target_id !== $gateway->id()) { throw new \RuntimeException('balance_unavailable'); }
      if ($order->getState()->getId() !== 'draft' || !$order->getStore()->isPublished()) { throw new \RuntimeException('order_unavailable'); }
      $fingerprint = $this->fingerprint($order);
      $parent = $this->db->select('commerce_paymento_order', 'p')->fields('p')->condition('order_id', $orderId)->execute()->fetchAssoc();
      if (!$parent) {
        $this->db->insert('commerce_paymento_order')->fields(['order_id' => $orderId, 'gateway' => $gateway->id(), 'state' => 'pending', 'created' => time()])->execute();
      }
      elseif ($parent['gateway'] !== $gateway->id() || $parent['winning_attempt'] || $parent['refunded']) { throw new \RuntimeException('order_terminal'); }
      $previous = $this->db->select('commerce_paymento_attempt', 'a')->fields('a')->condition('order_id', $orderId)->isNotNull('token')->condition('refunded', 0)->orderBy('id', 'DESC')->range(0, 1)->execute()->fetchAssoc();
      $previousPayment = $previous ? $this->entities->getStorage('commerce_payment')->loadUnchanged($previous['payment_id']) : NULL;
      $previousTerminal = $previousPayment && in_array($previousPayment->getRemoteState(), ['4', '5', '9', '10'], TRUE);
      $currentConfig = $gateway->getPlugin()->getConfiguration();
      if (!$forceNew && $previous && !$previousTerminal && $previous['environment'] === $currentConfig['environment'] && $previous['credential_reference'] === $currentConfig['credential_reference'] && hash_equals($previous['fingerprint'], $fingerprint) && self::decimal($previous['amount']) === self::decimal($order->getBalance()->getNumber())) { return $previous; }
      $payment->setAmount($order->getBalance());
      $payment->setState('new');
      $payment->save();
      $config = $gateway->getPlugin()->getConfiguration();
      $bases = $this->bases($gateway);
      $external = bin2hex(random_bytes(16));
      $attempt = ['order_id' => $orderId, 'payment_id' => (int) $payment->id(), 'gateway' => $gateway->id(), 'external_id' => $external, 'credential_reference' => $config['credential_reference'], 'environment' => $config['environment'], 'amount' => self::decimal($order->getBalance()->getNumber()), 'currency' => $order->getBalance()->getCurrencyCode(), 'order_total' => $order->getTotalPrice()->getNumber(), 'fingerprint' => $fingerprint, 'api_base' => $bases['api'], 'checkout_base' => $bases['checkout'], 'created' => time()];
      $id = (int) $this->db->insert('commerce_paymento_attempt')->fields($attempt)->execute();
      $result = $this->api($gateway, 'POST', '/v1/payment/request', ['fiatAmount' => $attempt['amount'], 'fiatCurrency' => $attempt['currency'], 'orderId' => $external, 'returnUrl' => $returnUrl, 'emailAddress' => $order->getEmail()], $bases['api']);
      $token = $result['body'] ?? NULL;
      if ($result['success'] !== TRUE || !is_string($token) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $token)) { throw new \RuntimeException('request_refused'); }
      $this->db->update('commerce_paymento_attempt')->fields(['token' => $token])->condition('id', $id)->execute();
      $payment->setRemoteId($token); $payment->setRemoteState('0'); $payment->save();
      return $attempt + ['id' => $id, 'token' => $token];
    }
    finally { $storage->releaseLock($orderId); $this->unlock($lock); }
  }

  public function receive(PaymentGatewayInterface $gateway, Request $request): JsonResponse {
    if (!in_array($request->getMethod(), ['POST', 'PUT'], TRUE)) { return new JsonResponse(['error' => 'method'], 405); }
    $raw = $request->getContent();
    if (strlen($raw) > 65536) { return new JsonResponse(['error' => 'size'], 413); }
    try { $secret = $this->credentials($gateway)['ipn_secret']; }
    catch (\Throwable $error) { return new JsonResponse(['error' => 'unavailable'], 503); }
    $signature = $request->headers->get('X-HMAC-SHA256-Signature', '');
    if (!preg_match('/^[a-fA-F0-9]{64}$/D', $signature) || !hash_equals(strtoupper(hash_hmac('sha256', $raw, $secret)), strtoupper($signature))) { return new JsonResponse(['error' => 'signature'], 401); }
    $payload = json_decode($raw, TRUE);
    if (!is_array($payload) || !is_string($payload['Token'] ?? NULL) || !is_string($payload['OrderId'] ?? NULL)) { return new JsonResponse(['error' => 'payload'], 400); }
    try {
      $attempt = $this->db->select('commerce_paymento_attempt', 'a')->fields('a')->condition('token', $payload['Token'])->condition('gateway', $gateway->id())->execute()->fetchAssoc();
      if (!$attempt || !hash_equals($attempt['token'], $payload['Token']) || !hash_equals($attempt['external_id'], $payload['OrderId'])) { return new JsonResponse(['error' => 'unknown'], 404); }
      $id = (int) $this->db->insert('commerce_paymento_inbox')->fields(['attempt_id' => $attempt['id'], 'payload' => $raw, 'received' => time(), 'next_attempt' => time()])->execute();
    }
    catch (\Throwable $error) { return new JsonResponse(['error' => 'storage'], 503); }
    try {
      $this->queues->get('commerce_paymento')->createItem($id);
      $this->db->update('commerce_paymento_inbox')->fields(['queued_until' => time() + 300])->condition('id', $id)->execute();
    }
    catch (\Throwable $error) { /* Cron repairs enqueue from the committed inbox. */ }
    return new JsonResponse(['accepted' => TRUE], 200);
  }

  private function inbox(int $id): ?array {
    $query = $this->db->select('commerce_paymento_inbox', 'i')->fields('i');
    $query->join('commerce_paymento_attempt', 'a', 'a.id=i.attempt_id');
    $query->fields('a', ['order_id', 'payment_id', 'gateway', 'token', 'external_id', 'amount', 'currency', 'fingerprint', 'api_base', 'credential_reference', 'environment']);
    $query->addField('a', 'refunded', 'attempt_refunded');
    return $query->condition('i.id', $id)->execute()->fetchAssoc() ?: NULL;
  }
  private function validateVerified(array $body, array $row): void {
    if (!is_string($body['token'] ?? NULL) || !hash_equals($row['token'], $body['token']) || !is_string($body['orderId'] ?? NULL) || !hash_equals($row['external_id'], $body['orderId']) || !is_int($body['orderStatus'] ?? NULL) || !in_array($body['orderStatus'], [0, 1, 2, 3, 4, 5, 7, 8, 9, 10], TRUE)) { throw new \RuntimeException('verification_identity'); }
    if (in_array($body['orderStatus'], [7, 8], TRUE)) {
      if (isset($body['settlement']['requestedFiatAmount']) && self::decimal($body['settlement']['requestedFiatAmount']) !== self::decimal($row['amount'])) { throw new \RuntimeException('verification_amount'); }
    }
  }

  public function process(int $id): void {
    $row = $this->inbox($id);
    if (!$row || $row['processed'] !== NULL) { return; }
    $lock = $this->lock((int) $row['order_id']);
    $orderStorage = $this->entities->getStorage('commerce_order');
    $paymentStorage = $this->entities->getStorage('commerce_payment');
    $transaction = NULL;
    try {
      $row = $this->inbox($id);
      if (!$row || $row['processed'] !== NULL) { return; }
      $this->db->update('commerce_paymento_inbox')->expression('retries', 'retries+1')->condition('id', $id)->execute();
      $gateway = $this->entities->getStorage('commerce_payment_gateway')->load($row['gateway']);
      if (!$gateway || $gateway->getPluginId() !== 'paymento') { throw new \RuntimeException('gateway_unavailable'); }
      $config = $gateway->getPlugin()->getConfiguration();
      if ($config['credential_reference'] !== $row['credential_reference'] || $config['environment'] !== $row['environment']) { throw new \RuntimeException('gateway_changed'); }
      $order = $orderStorage->loadForUpdate((int) $row['order_id']);
      if (!$order) { throw new \RuntimeException('order_unavailable'); }
      if ($row['verified'] === NULL) {
        $result = $this->api($gateway, 'POST', '/v1/payment/verify', ['token' => $row['token']], $row['api_base']);
        $body = $result['body'] ?? NULL;
        if (!is_array($body)) { throw new \RuntimeException('verification_identity'); }
        $this->validateVerified($body, $row);
        $this->db->update('commerce_paymento_inbox')->fields(['verified' => json_encode($body, JSON_THROW_ON_ERROR)])->condition('id', $id)->execute();
      }
      else { $body = json_decode($row['verified'], TRUE, 512, JSON_THROW_ON_ERROR); $this->validateVerified($body, $row); }
      $this->fault('after-verify', $id);
      $parent = $this->db->select('commerce_paymento_order', 'p')->fields('p')->condition('order_id', $row['order_id'])->execute()->fetchAssoc();
      if (!$parent || $parent['gateway'] !== $row['gateway']) { throw new \RuntimeException('binding_changed'); }
      $paymentStorage->resetCache([(int) $row['payment_id']]);
      $payment = $paymentStorage->load($row['payment_id']);
      if (!$payment || (int) $payment->getOrderId() !== (int) $row['order_id'] || $payment->getPaymentGatewayId() !== $row['gateway'] || $payment->getRemoteId() !== $row['token']) { throw new \RuntimeException('binding_changed'); }
      if (!$payment->getAmount() || $payment->getAmount()->getCurrencyCode() !== $row['currency'] || self::decimal($payment->getAmount()->getNumber()) !== self::decimal($row['amount'])) { throw new \RuntimeException('binding_changed'); }
      $status = $body['orderStatus'];
      $paymentStorage->resetCache();
      (new \Drupal\commerce_payment\PaymentOrderUpdater($this->entities))->updateOrder($order, FALSE);
      // Native manual refund terminality is authoritative for replay safety.
      $nativeRefunded = $payment->getState()->getId() === 'refunded' || ($payment->getRefundedAmount()?->greaterThanOrEqual($payment->getAmount()) ?? FALSE);
      if ($nativeRefunded) {
        $this->db->update('commerce_paymento_attempt')->fields(['refunded' => 1])->condition('id', $row['attempt_id'])->execute(); $row['attempt_refunded'] = 1;
        if ((int) $parent['winning_attempt'] === (int) $row['attempt_id']) {
          $this->db->update('commerce_paymento_order')->fields(['refunded' => 1, 'state' => 'refunded'])->condition('order_id', $row['order_id'])->execute(); $parent['refunded'] = 1;
        }
      }
      $paid = in_array($status, [7, 8], TRUE) && !$row['attempt_refunded'] && !$parent['refunded'];
      if ($paid && $parent['winning_attempt'] && (int) $parent['winning_attempt'] !== (int) $row['attempt_id']) { throw new \RuntimeException('duplicate_paid_review'); }
      if ($paid && !$payment->isCompleted()) { $this->assertSupportedOrder($order); }
      if ($paid && !$payment->isCompleted() && $order->getState()->getId() !== 'draft') { throw new \RuntimeException('order_changed_or_paid'); }
      if (in_array($status, [7, 8], TRUE) && !$row['attempt_refunded'] && $parent['refunded'] && (int) $parent['winning_attempt'] !== (int) $row['attempt_id']) { throw new \RuntimeException('duplicate_paid_review'); }
      if ($paid && !$parent['winning_attempt']) {
        if ($order->isPaid() || !hash_equals($row['fingerprint'], $this->fingerprint($order)) || $order->get('payment_gateway')->target_id !== $row['gateway'] || $order->getBalance()?->getCurrencyCode() !== $row['currency'] || self::decimal($order->getBalance()->getNumber()) !== self::decimal($row['amount'])) { throw new \RuntimeException('order_changed_or_paid'); }
        // Winner reservation commits before any native completion, surviving worker failure.
        $this->db->update('commerce_paymento_order')->fields(['winning_attempt' => (int) $row['attempt_id']])->condition('order_id', $row['order_id'])->isNull('winning_attempt')->execute();
        $parent['winning_attempt'] = (int) $row['attempt_id'];
      }
      if ($paid && (int) $parent['winning_attempt'] !== (int) $row['attempt_id']) { throw new \RuntimeException('duplicate_paid_review'); }
      $transaction = $this->db->startTransaction();
      // Actual row lock remains held even when native OrderStorage::save releases its semaphore.
      $this->db->query('SELECT order_id FROM {commerce_order} WHERE order_id=:id FOR UPDATE', [':id' => $row['order_id']])->fetchField();
      $this->db->query('SELECT payment_id FROM {commerce_payment} WHERE payment_id=:id FOR UPDATE', [':id' => $row['payment_id']])->fetchField();
      $order = $orderStorage->loadUnchanged($row['order_id']);
      if (!$order || $order->get('payment_gateway')->target_id !== $row['gateway']) { throw new \RuntimeException('order_changed_or_paid'); }
      $paymentStorage->resetCache();
      (new \Drupal\commerce_payment\PaymentOrderUpdater($this->entities))->updateOrder($order, FALSE);
      $payment = $paymentStorage->loadUnchanged($row['payment_id']);
      if (!$payment || (int) $payment->getOrderId() !== (int) $row['order_id'] || $payment->getPaymentGatewayId() !== $row['gateway'] || $payment->getRemoteId() !== $row['token'] || $payment->getAmount()->getCurrencyCode() !== $row['currency'] || self::decimal($payment->getAmount()->getNumber()) !== self::decimal($row['amount'])) { throw new \RuntimeException('binding_changed'); }
      $nativeRefunded = $payment->getState()->getId() === 'refunded' || $payment->getRefundedAmount()->greaterThanOrEqual($payment->getAmount());
      if ($nativeRefunded) {
        $paid = FALSE;
        $this->db->update('commerce_paymento_attempt')->fields(['refunded' => 1])->condition('id', $row['attempt_id'])->execute();
        if ((int) $parent['winning_attempt'] === (int) $row['attempt_id']) { $this->db->update('commerce_paymento_order')->fields(['refunded' => 1, 'state' => 'refunded'])->condition('order_id', $row['order_id'])->execute(); }
      }
      if ($paid) {
        if (!$payment->isCompleted()) { $this->assertSupportedOrder($order); }
        if (!$payment->isCompleted() && $order->getState()->getId() !== 'draft') { throw new \RuntimeException('order_changed_or_paid'); }
        if ($payment->isCompleted() && $order->getTotalPaid()->greaterThan($order->getTotalPrice())) { throw new \RuntimeException('duplicate_paid_review'); }
        if (!$payment->isCompleted()) {
          if (!hash_equals($row['fingerprint'], $this->fingerprint($order)) || $order->getBalance()?->getCurrencyCode() !== $row['currency'] || self::decimal($order->getBalance()->getNumber()) !== self::decimal($row['amount'])) { throw new \RuntimeException('order_changed_or_paid'); }
          $payment->setState('completed'); $payment->setRemoteState((string) $status); $payment->save();
        }
        if (!$this->db->select('commerce_paymento_outbox', 'o')->fields('o', ['order_id'])->condition('order_id', $row['order_id'])->execute()->fetchField()) {
          $this->db->insert('commerce_paymento_outbox')->fields(['order_id' => (int) $row['order_id'], 'created' => time(), 'kind' => 'paid'])->execute();
        }
        $this->db->update('commerce_paymento_order')->fields(['state' => 'paid'])->condition('order_id', $row['order_id'])->execute();
      }
      elseif ($status === 10) {
        $this->db->update('commerce_paymento_attempt')->fields(['refunded' => 1])->condition('id', $row['attempt_id'])->execute();
        $payment->setRefundedAmount($payment->getAmount()); $payment->setState('refunded'); $payment->setRemoteState('10'); $payment->save();
        if ((int) $parent['winning_attempt'] === (int) $row['attempt_id']) {
          $this->db->update('commerce_paymento_order')->fields(['refunded' => 1, 'state' => 'refunded'])->condition('order_id', $row['order_id'])->execute();
        }
      }
      elseif (!$payment->isCompleted() && !$nativeRefunded && !in_array($payment->getRemoteState(), ['4', '5', '9'], TRUE)) { $payment->setRemoteState((string) $status); $payment->save(); }
      // The native service normally defers this until destruction; complete ledger inside our transaction.
      $order = $orderStorage->loadUnchanged($row['order_id']);
      \Drupal::service('commerce_payment.order_updater')->updateOrder($order, TRUE);
      $this->fault('after-native', $id);
      $this->db->update('commerce_paymento_inbox')->fields(['processed' => time(), 'last_error' => NULL])->condition('id', $id)->execute();
      unset($transaction);
    }
    catch (\Throwable $error) {
      if ($transaction) { $transaction->rollBack(); $transaction = NULL; }
      $orderStorage->resetCache([(int) $row['order_id']]); $paymentStorage->resetCache([(int) $row['payment_id']]);
      $known = ['credentials_missing', 'api_unavailable', 'api_invalid', 'gateway_changed', 'gateway_unavailable', 'order_unavailable', 'verification_identity', 'verification_amount', 'binding_changed', 'order_changed_or_paid', 'duplicate_paid_review', 'synthetic_crash'];
      $message = in_array($error->getMessage(), $known, TRUE) ? $error->getMessage() : 'processing_failed';
      $this->db->update('commerce_paymento_inbox')->fields(['last_error' => $message, 'next_attempt' => time() + 60])->condition('id', $id)->execute();
      throw $error;
    }
    finally {
      $orderStorage->releaseLock((int) $row['order_id']);
      // A rollback can restore the semaphore deleted by native OrderStorage::save.
      // The backend releases only this request's lock ID, never a foreign lease.
      \Drupal::lock()->release('commerce_order_update:' . $row['order_id']);
      $this->unlock($lock);
    }
  }
  private function fault(string $phase, int $id): void {
    if (Settings::get('commerce_paymento_test_mode', FALSE) && file_exists('/tmp/paymento-' . $phase . '-' . $id)) { throw new \RuntimeException('synthetic_crash'); }
  }
  public function enqueueDue(): int {
    $ids = $this->db->select('commerce_paymento_inbox', 'i')->fields('i', ['id'])->isNull('processed')->condition('next_attempt', time(), '<=')->condition('queued_until', time(), '<=')->orderBy('retries')->orderBy('id')->range(0, 100)->execute()->fetchCol();
    foreach ($ids as $id) {
      $this->queues->get('commerce_paymento')->createItem((int) $id);
      $this->db->update('commerce_paymento_inbox')->fields(['queued_until' => time() + 300])->condition('id', $id)->execute();
    }
    return count($ids);
  }
  public function retry(bool $force = FALSE): int {
    $query = $this->db->select('commerce_paymento_inbox', 'i')->fields('i', ['id'])->isNull('processed')->orderBy('retries')->orderBy('id')->range(0, 100);
    if (!$force) { $query->condition('next_attempt', time(), '<='); }
    $done = 0;
    $start = microtime(TRUE);
    foreach ($query->execute()->fetchCol() as $id) { if (microtime(TRUE) - $start > 20) { break; } try { $this->process((int) $id); $done++; } catch (\Throwable $error) {} }
    return $done;
  }
  public function configureCallback(string $id): void {
    $gateway = $this->entities->getStorage('commerce_payment_gateway')->load($id);
    if (!$gateway || $gateway->getPluginId() !== 'paymento') { throw new \RuntimeException('gateway_unavailable'); }
    $url = $gateway->getPlugin()->getNotifyUrl()->toString();
    if (parse_url($url, PHP_URL_SCHEME) !== 'https') { throw new \RuntimeException('https_required'); }
    $current = $this->api($gateway, 'GET', '/v1/payment/settings');
    if (!$current['success'] || !is_array($current['body'] ?? NULL) || (!empty($current['body']['ipN_URL']) && $current['body']['ipN_URL'] !== $url)) { throw new \RuntimeException('callback_conflict'); }
    $saved = $this->api($gateway, 'POST', '/v1/payment/settings', ['ipN_URL' => $url, 'ipN_Method' => 1]);
    if (!$saved['success']) { throw new \RuntimeException('callback_refused'); }
  }
}
