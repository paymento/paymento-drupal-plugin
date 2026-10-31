<?php
namespace Drupal\commerce_paymento\Controller;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
final class NotifyController {
  public function notify(PaymentGatewayInterface $commerce_payment_gateway, Request $request): JsonResponse {
    if ($commerce_payment_gateway->getPluginId() !== 'paymento') { return new JsonResponse(['error' => 'gateway'], 404); }
    return $commerce_payment_gateway->getPlugin()->onNotify($request);
  }
}
