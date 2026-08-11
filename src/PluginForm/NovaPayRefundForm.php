<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novapay\Payment\SupportsItemRefundsInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PluginForm\PaymentGatewayFormBase;
use Drupal\commerce_price\Calculator;

/**
 * Provides exact item quantities for partial or empty-selection full refunds.
 */
final class NovaPayRefundForm extends PaymentGatewayFormBase {

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The refund operation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<array-key, mixed>
   *   The item-level refund form.
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $payment = $this->getPayment();
    $plugin = $this->getRefundPlugin();
    $items = $plugin->getRefundableItems($payment);
    $currency = $payment->getAmount()?->getCurrencyCode() ?? '';

    $form['#success_message'] = $this->t(
      'Refund submitted to NovaPay. Payment and item totals will update after postback confirmation.',
    );
    $form['notice'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        'A partial refund is possible when the order payment was credited to the NovaPay account.',
      ),
    ];
    $form['items'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Order item'),
        $this->t('Paid quantity'),
        $this->t('Already refunded'),
        $this->t('Available'),
        $this->t('Unit price'),
        $this->t('Quantity to refund'),
        $this->t('Refund amount'),
      ],
      '#tree' => TRUE,
      '#attributes' => ['class' => ['commerce-novapay-refund-items']],
    ];
    foreach ($items as $item) {
      $id = $item->getOrderItemId();
      $form['items'][$id]['title'] = [
        '#plain_text' => $item->getTitle(),
      ];
      $form['items'][$id]['ordered'] = [
        '#plain_text' => $item->getOrderedQuantity(),
      ];
      $form['items'][$id]['refunded'] = [
        '#plain_text' => $item->getRefundedQuantity(),
      ];
      $form['items'][$id]['available'] = [
        '#plain_text' => $item->getAvailableQuantity(),
      ];
      $form['items'][$id]['unit_price'] = [
        '#plain_text' => (string) $item->getUnitPrice(),
      ];
      $form['items'][$id]['quantity'] = [
        '#type' => 'number',
        '#title' => $this->t(
          'Quantity to refund for @item',
          ['@item' => $item->getTitle()],
        ),
        '#title_display' => 'invisible',
        '#default_value' => '',
        '#min' => 0,
        '#max' => $item->getAvailableQuantity(),
        '#step' => '0.000001',
        '#attributes' => [
          'class' => ['commerce-novapay-refund-quantity'],
          'data-unit-price' => $item->getUnitPrice()->getNumber(),
          'data-currency' => $currency,
        ],
      ];
      $form['items'][$id]['amount'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '0 ' . $currency,
        '#attributes' => [
          'class' => ['commerce-novapay-refund-line-total'],
        ],
      ];
    }
    $form['total'] = [
      '#type' => 'item',
      '#title' => $this->t('Total refund amount'),
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '0 ' . $currency,
        '#attributes' => ['class' => ['commerce-novapay-refund-total']],
      ],
    ];
    $form['empty_selection'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        'Leave every quantity empty or zero to request a full refund.',
      ),
    ];
    $form['#attached']['library'][] = 'commerce_novapay/refund_form';

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The refund operation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function validateConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $available = [];
    foreach ($this->getRefundPlugin()->getRefundableItems(
      $this->getPayment(),
    ) as $item) {
      $available[$item->getOrderItemId()] = $item;
    }

    foreach ($this->getSubmittedQuantities($form, $form_state) as $id => $quantity) {
      $quantity = trim($quantity);
      if ($quantity === '') {
        continue;
      }
      if (
        preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $quantity) !== 1
        || !isset($available[$id])
        || Calculator::compare(
          $quantity,
          $available[$id]->getAvailableQuantity(),
        ) > 0
      ) {
        $form_state->setError(
          $form['items'][$id]['quantity'],
          $this->t('Enter a quantity no greater than the available paid quantity.'),
        );
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The refund operation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    $this->getRefundPlugin()->refundItems(
      $this->getPayment(),
      $this->getSubmittedQuantities($form, $form_state),
    );
  }

  /**
   * Gets the payment bound to this operation form.
   */
  private function getPayment(): PaymentInterface {
    if (!$this->entity instanceof PaymentInterface) {
      throw new \LogicException('The NovaPay refund form requires a payment.');
    }

    return $this->entity;
  }

  /**
   * Gets the item-refund-capable gateway plugin.
   */
  private function getRefundPlugin(): SupportsItemRefundsInterface {
    if (!$this->plugin instanceof SupportsItemRefundsInterface) {
      throw new \LogicException(
        'The NovaPay gateway does not support item refunds.',
      );
    }

    return $this->plugin;
  }

  /**
   * Extracts bounded quantity strings from the nested plugin form.
   *
   * @param array<array-key, mixed> $form
   *   The refund operation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<int, string>
   *   Submitted quantities keyed by order item ID.
   */
  private function getSubmittedQuantities(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $values = $form_state->getValue($form['#parents']);
    $rows = is_array($values) && is_array($values['items'] ?? NULL)
      ? $values['items']
      : [];
    $quantities = [];
    foreach ($rows as $id => $row) {
      if (
        is_array($row)
        && is_string($row['quantity'] ?? NULL)
        && preg_match('/^[1-9][0-9]*$/D', (string) $id) === 1
      ) {
        $quantities[(int) $id] = $row['quantity'];
      }
    }

    return $quantities;
  }

}
