<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_payment\PluginForm\PaymentCaptureForm;

/**
 * Provides the NovaPay authorization capture form.
 */
final class NovaPayCaptureForm extends PaymentCaptureForm {

  /**
   * {@inheritdoc}
   *
   * @param array<array-key, mixed> $form
   *   The capture form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   *
   * @return array<array-key, mixed>
   *   The capture form awaiting postback confirmation.
   */
  public function buildConfigurationForm(
    array $form,
    FormStateInterface $form_state,
  ): array {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['#success_message'] = $this->t(
      'Capture submitted to NovaPay. Payment state will update after postback confirmation.',
    );
    return $form;
  }

}
