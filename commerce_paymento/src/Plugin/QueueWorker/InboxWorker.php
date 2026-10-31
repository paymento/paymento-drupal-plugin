<?php
namespace Drupal\commerce_paymento\Plugin\QueueWorker;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
#[QueueWorker(id: 'commerce_paymento', title: new TranslatableMarkup('Paymento inbox'), cron: ['time' => 30])]
final class InboxWorker extends QueueWorkerBase {
  public function processItem($data) {
    try { \Drupal::service('commerce_paymento.manager')->process((int) $data); }
    catch (\Throwable $error) { throw new DelayedRequeueException(60, 'Paymento processing remains retryable.'); }
  }
}
