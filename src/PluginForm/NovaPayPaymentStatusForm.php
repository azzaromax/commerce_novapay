<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novapay\Payment\PaymentStatusCheckResult;
use Drupal\commerce_novapay\Payment\SupportsPaymentStatusChecksInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PluginForm\PaymentGatewayFormBase;

/**
 * Provides a manual read-only NovaPay payment status check.
 */
final class NovaPayPaymentStatusForm extends PaymentGatewayFormBase {

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The payment status confirmation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<array-key, mixed>
   *   The completed payment status confirmation form.
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $form['notice'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        'This checks the current NovaPay payment status. It does not submit a payment, capture, void, or refund request.',
      ),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The payment status confirmation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {}

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The payment status confirmation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $payment = $this->getPayment();
    $plugin = $this->getStatusCheckPlugin();
    $result = $plugin->checkPaymentStatus($payment);
    $form['#success_message'] = $result === PaymentStatusCheckResult::Reconciled
      ? $this->t('NovaPay payment status was reconciled.')
      : $this->t('NovaPay has no conclusive status update for this payment.');
  }

  /**
   * Gets the payment bound to this status operation.
   */
  private function getPayment(): PaymentInterface {
    if (!$this->entity instanceof PaymentInterface) {
      throw new \LogicException(
        'The NovaPay payment status form requires a payment.',
      );
    }
    return $this->entity;
  }

  /**
   * Gets the gateway plugin that provides status reconciliation.
   */
  private function getStatusCheckPlugin(): SupportsPaymentStatusChecksInterface {
    if (!$this->plugin instanceof SupportsPaymentStatusChecksInterface) {
      throw new \LogicException('The NovaPay gateway cannot check payment status.');
    }
    return $this->plugin;
  }

}
