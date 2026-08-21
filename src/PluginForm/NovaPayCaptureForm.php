<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\PluginForm;

use Drupal\Core\Form\FormStateInterface;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
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

    if (!$this->hasRecipientIdentifier()) {
      $form['recipient_identifier_notice'] = [
        '#type' => 'container',
        '#weight' => -10,
        '#attributes' => [
          'class' => ['messages', 'messages--warning'],
          'role' => 'status',
        ],
        '#markup' => $this->t(
          'Partial capture is unavailable because Recipient identifier is not configured for this NovaPay payment gateway. The full authorized amount will be captured. Configure Recipient identifier in the payment gateway settings to enable partial capture.',
        ),
      ];
      $form['amount']['#disabled'] = TRUE;
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Enforces a full capture server-side when partial capture is unavailable.
   *
   * @param array<array-key, mixed> $form
   *   The capture form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The complete form state.
   */
  public function submitConfigurationForm(
    array &$form,
    FormStateInterface $form_state,
  ): void {
    if ($this->hasRecipientIdentifier()) {
      parent::submitConfigurationForm($form, $form_state);
      return;
    }

    if (
      !$this->entity instanceof PaymentInterface
      || !$this->plugin instanceof SupportsAuthorizationsInterface
    ) {
      throw new \LogicException(
        'The NovaPay capture form requires an authorization payment gateway and payment.',
      );
    }

    $this->plugin->capturePayment($this->entity, $this->entity->getAmount());
  }

  /**
   * Returns whether partial capture recipient data is configured.
   */
  private function hasRecipientIdentifier(): bool {
    if (!$this->plugin instanceof RuntimeConfigurationProviderInterface) {
      throw new \LogicException(
        'The NovaPay capture form requires runtime configuration.',
      );
    }

    return $this->plugin
      ->getRuntimeConfiguration()
      ->getProfile()
      ->getRecipientIdentifier() !== '';
  }

}
