<?php
namespace Drupal\commerce_paymento\Controller;
use Drupal\Core\Controller\ControllerBase;
use Drupal\commerce_order\Entity\OrderInterface;
final class StatusController extends ControllerBase {
  public function view(OrderInterface $commerce_order): array {
    $state = \Drupal::service('commerce_paymento.manager')->state((int) $commerce_order->id());
    return ['message' => ['#plain_text' => $this->t('Paymento payment status: @state. Confirmation may take time. Contact the merchant if payment remains pending.', ['@state' => $state])], '#cache' => ['max-age' => 0]];
  }
}
