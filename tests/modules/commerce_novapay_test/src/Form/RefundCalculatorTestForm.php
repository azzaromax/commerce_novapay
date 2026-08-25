<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay_test\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides deterministic markup for the production refund calculator JS.
 */
final class RefundCalculatorTestForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'commerce_novapay_refund_calculator_test';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The test form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   *
   * @return array<array-key, mixed>
   *   The test form with refund calculator markup.
   */
  public function buildForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $form['items'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['commerce-novapay-refund-items']],
    ];
    foreach (['11' => '10.25', '12' => '3.40'] as $id => $unit_price) {
      $form['items'][$id]['quantity'] = [
        '#type' => 'number',
        '#title' => $this->t('Quantity @id', ['@id' => $id]),
        '#attributes' => [
          'class' => ['commerce-novapay-refund-quantity'],
          'data-unit-price' => $unit_price,
          'data-currency' => 'UAH',
        ],
      ];
      $form['items'][$id]['amount'] = [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => '0 UAH',
        '#attributes' => [
          'class' => ['commerce-novapay-refund-line-total'],
          'data-test-item' => $id,
        ],
      ];
    }
    $form['total'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => '0 UAH',
      '#attributes' => ['class' => ['commerce-novapay-refund-total']],
    ];
    $form['#attached']['library'][] = 'commerce_novapay/refund_form';

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The submitted test form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The submitted form state.
   */
  public function submitForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {}

}
