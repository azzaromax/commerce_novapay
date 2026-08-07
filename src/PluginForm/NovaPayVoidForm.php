<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\PluginForm\PaymentVoidForm;

/**
 * Provides the NovaPay authorization void form.
 */
final class NovaPayVoidForm extends PaymentVoidForm {

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The void confirmation form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<array-key, mixed>
   *   The void form awaiting postback confirmation.
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['#success_message'] = $this->t(
      'Void submitted to NovaPay. Payment state will update after postback confirmation.',
    );
    return $form;
  }

}
