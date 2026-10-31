<?php
namespace Drupal\commerce_paymento\PluginForm;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\Core\Form\FormStateInterface;
final class OffsiteForm extends PaymentOffsiteForm {
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    try { $attempt = \Drupal::service('commerce_paymento.manager')->initiate($this->entity, $form['#return_url']); }
    catch (\Throwable $error) { throw PaymentGatewayException::createForPayment($this->entity, 'Unable to initialize crypto checkout. Contact the merchant or try again.'); }
    return $this->buildRedirectForm($form, $form_state, $attempt['checkout_base'], ['token' => $attempt['token']], self::REDIRECT_GET);
  }
}
