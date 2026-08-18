<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\commerce_novapay\Checkout\PaymentOptionBranding;

/**
 * Hook implementations for NovaPay checkout payment-option branding.
 */
final class CheckoutPaymentOptionHooks {

  /**
   * Constructs the checkout payment-option hooks.
   */
  public function __construct(
    private readonly PaymentOptionBranding $branding,
  ) {}

  /**
   * Implements hook_form_alter().
   *
   * @phpstan-param array<array-key, mixed> $form
   */
  #[Hook('form_alter')]
  public function formAlter(
    array &$form,
    FormStateInterface $form_state,
    string $form_id,
  ): void {
    $this->branding->alter($form);
  }

  /**
   * Implements hook_preprocess_form_element().
   *
   * @phpstan-param array<array-key, mixed> $variables
   */
  #[Hook('preprocess_form_element')]
  public function preprocessFormElement(array &$variables): void {
    $this->branding->preprocessFormElement($variables);
  }

  /**
   * Implements hook_preprocess_form_element_label().
   *
   * @phpstan-param array<array-key, mixed> $variables
   */
  #[Hook('preprocess_form_element_label')]
  public function preprocessFormElementLabel(array &$variables): void {
    $this->branding->preprocessFormElementLabel($variables);
  }

}
