<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novapay\Payment\RefundStatusCheckResult;
use Drupal\commerce_novapay\Payment\SupportsItemRefundsInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PluginForm\PaymentGatewayFormBase;

/**
 * Provides a manual pending-refund status check.
 */
final class NovaPayRefundStatusForm extends PaymentGatewayFormBase {

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The refund status confirmation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<array-key, mixed>
   *   The refund status confirmation form.
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $form['notice'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        'This checks the pending refund at NovaPay. It does not submit another refund request.',
      ),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The refund status confirmation form.
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
   *   The refund status confirmation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $payment = $this->getPayment();
    $plugin = $this->getRefundPlugin();
    $result = $plugin->checkRefundStatus($payment);
    $form['#success_message'] = $result === RefundStatusCheckResult::Confirmed
      ? $this->t('NovaPay confirmed the refund. Local payment totals were updated.')
      : $this->t('NovaPay has not confirmed the refund yet. The refund remains pending.');
  }

  /**
   * Gets the payment bound to this status operation.
   */
  private function getPayment(): PaymentInterface {
    if (!$this->entity instanceof PaymentInterface) {
      throw new \LogicException(
        'The NovaPay refund status form requires a payment.',
      );
    }
    return $this->entity;
  }

  /**
   * Gets the item-refund-capable gateway plugin.
   */
  private function getRefundPlugin(): SupportsItemRefundsInterface {
    if (!$this->plugin instanceof SupportsItemRefundsInterface) {
      throw new \LogicException(
        'The NovaPay gateway does not support refund status checks.',
      );
    }
    return $this->plugin;
  }

}
