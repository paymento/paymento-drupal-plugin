<?php
namespace Drupal\commerce_paymento\Form;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
final class CallbackForm extends FormBase {
  public function getFormId() { return 'commerce_paymento_callback'; }
  public function buildForm(array $form, FormStateInterface $form_state, ?PaymentGatewayInterface $commerce_payment_gateway = NULL) {
    if (!$commerce_payment_gateway || $commerce_payment_gateway->getPluginId() !== 'paymento') { throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException(); }
    $form_state->set('gateway_id', $commerce_payment_gateway->id());
    $form['confirm'] = ['#type' => 'checkbox', '#title' => $this->t('Configure this dedicated Paymento store callback. A different existing callback will be refused.'), '#required' => TRUE];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Configure callback')];
    return $form;
  }
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try { \Drupal::service('commerce_paymento.manager')->configureCallback((string) $form_state->get('gateway_id')); $this->messenger()->addStatus($this->t('Paymento callback configured.')); }
    catch (\Throwable $error) { $this->messenger()->addError($this->t('Callback configuration refused. A public HTTPS endpoint and a dedicated Paymento store are required.')); }
  }
}
