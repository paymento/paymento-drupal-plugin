<?php
namespace Drupal\commerce_paymento\Plugin\Commerce\PaymentGateway;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\commerce_paymento\PluginForm\OffsiteForm;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce\Response\NeedsRedirectException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
#[CommercePaymentGateway(id: 'paymento', label: new TranslatableMarkup('Paymento'), display_label: new TranslatableMarkup('Crypto with Paymento'), forms: ['offsite-payment' => OffsiteForm::class], payment_method_types: ['paymento_crypto'], requires_billing_information: FALSE)]
final class Paymento extends OffsitePaymentGatewayBase {
  public function defaultConfiguration() { return ['environment' => 'production', 'credential_reference' => ''] + parent::defaultConfiguration(); }
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['credential_reference'] = ['#type' => 'textfield', '#title' => $this->t('Server-side credential reference'), '#default_value' => $this->configuration['credential_reference'], '#required' => TRUE, '#description' => $this->t('Reference a settings.php credential entry. Never enter an API key or secret here.')];
    return $form;
  }
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $values = $form_state->getValue($form['#parents']);
    $this->configuration['environment'] = 'production';
    $this->configuration['credential_reference'] = trim((string) ($values['credential_reference'] ?? ''));
  }
  public function getNotifyUrl() { return Url::fromRoute('commerce_paymento.notify', ['commerce_payment_gateway' => $this->parentEntity->id()], ['absolute' => TRUE]); }
  public function onNotify(Request $request) { return \Drupal::service('commerce_paymento.manager')->receive($this->parentEntity, $request); }
  public function onReturn(OrderInterface $order, Request $request) {
    if (!$order->isPaid()) { throw new NeedsRedirectException(Url::fromRoute('commerce_paymento.status', ['commerce_order' => $order->id()])->toString()); }
  }
  public function onCancel(OrderInterface $order, Request $request) {
    throw new NeedsRedirectException(Url::fromRoute('commerce_paymento.status', ['commerce_order' => $order->id()])->toString());
  }
}
