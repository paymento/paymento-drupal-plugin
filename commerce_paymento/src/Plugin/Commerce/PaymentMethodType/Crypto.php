<?php
namespace Drupal\commerce_paymento\Plugin\Commerce\PaymentMethodType;
use Drupal\commerce_payment\Attribute\CommercePaymentMethodType;
use Drupal\commerce_payment\Plugin\Commerce\PaymentMethodType\PaymentMethodTypeBase;
use Drupal\commerce_payment\Entity\PaymentMethodInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
#[CommercePaymentMethodType(id: 'paymento_crypto', label: new TranslatableMarkup('Crypto with Paymento'))]
final class Crypto extends PaymentMethodTypeBase {
  public function buildLabel(PaymentMethodInterface $payment_method) { return $this->t('Crypto with Paymento'); }
}
