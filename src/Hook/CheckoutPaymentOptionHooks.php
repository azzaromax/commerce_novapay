<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\commerce_checkout\Plugin\Commerce\CheckoutFlow\CheckoutFlowInterface;
use Drupal\commerce_novapay\Checkout\PaymentOptionBranding;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

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
    $selected_gateway = NULL;
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof CheckoutFlowInterface) {
      $order = $form_object->getOrder();
      $gateway = $order->get('payment_gateway')->entity;
      if ($gateway instanceof PaymentGatewayInterface) {
        $selected_gateway = $gateway;
      }
    }

    $this->branding->alter($form, $selected_gateway);
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
