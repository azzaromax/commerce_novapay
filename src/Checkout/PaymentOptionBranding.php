<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Checkout;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\PaymentOption;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Adds NovaPay branding metadata to its checkout payment option.
 */
final class PaymentOptionBranding {

  private const GATEWAY_PLUGIN_ID = 'novapay';

  private const LOGO_MARKER = '#commerce_novapay_logo';

  /**
   * Resolved gateway plugin checks keyed by gateway entity ID.
   *
   * @var array<string, bool>
   */
  private array $novaPayGateways = [];

  /**
   * Constructs the payment option branding service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Marks every NovaPay payment option in a checkout form.
   *
   * @phpstan-param array<array-key, mixed> $form
   */
  public function alter(array &$form): void {
    $this->alterElement($form);
  }

  /**
   * Passes NovaPay logo metadata from a radio to its label render element.
   *
   * Core deliberately copies only standard radio properties to the label, so
   * the module marker must be forwarded during form-element preprocessing.
   *
   * @phpstan-param array<array-key, mixed> $variables
   */
  public function preprocessFormElement(array &$variables): void {
    $element = $variables['element'] ?? NULL;
    if (!is_array($element) || !isset($element[self::LOGO_MARKER])) {
      return;
    }

    $label = $variables['label'] ?? NULL;
    if (!is_array($label)) {
      return;
    }

    $label[self::LOGO_MARKER] = $element[self::LOGO_MARKER];
    $variables['label'] = $label;
  }

  /**
   * Replaces a marked payment-option label title with the official logo.
   *
   * @phpstan-param array<array-key, mixed> $variables
   */
  public function preprocessFormElementLabel(array &$variables): void {
    $element = $variables['element'] ?? NULL;
    if (!is_array($element)) {
      return;
    }

    $logo = $element[self::LOGO_MARKER] ?? NULL;
    if (
      !is_array($logo)
      || !isset($logo['uri'], $logo['alt'])
      || !is_string($logo['uri'])
      || !is_string($logo['alt'])
    ) {
      return;
    }

    $variables['title'] = [
      '#theme' => 'image',
      '#uri' => $logo['uri'],
      '#alt' => $logo['alt'],
      '#width' => 124,
      '#height' => 25,
      '#attributes' => [
        'class' => ['commerce-novapay-payment-option-logo'],
      ],
    ];
  }

  /**
   * Recursively finds Commerce payment-information pane elements.
   *
   * @phpstan-param array<array-key, mixed> $element
   */
  private function alterElement(array &$element): void {
    $this->brandPaymentOptions($element);

    foreach ($element as $key => &$child) {
      if (
        !is_array($child)
        || (is_string($key) && str_starts_with($key, '#'))
      ) {
        continue;
      }
      $this->alterElement($child);
    }
    unset($child);
  }

  /**
   * Marks NovaPay radio elements in a payment-information pane.
   *
   * @phpstan-param array<array-key, mixed> $element
   */
  private function brandPaymentOptions(array &$element): void {
    if (
      !isset($element['#payment_options'])
      || !is_array($element['#payment_options'])
      || !isset($element['payment_method'])
      || !is_array($element['payment_method'])
      || ($element['payment_method']['#type'] ?? NULL) !== 'radios'
    ) {
      return;
    }

    foreach ($element['#payment_options'] as $payment_option) {
      if (
        !$payment_option instanceof PaymentOption
        || !$this->isNovaPayGateway($payment_option->getPaymentGatewayId())
      ) {
        continue;
      }

      $option_id = $payment_option->getId();
      if (!isset($element['payment_method'][$option_id])) {
        $element['payment_method'][$option_id] = [];
      }
      if (!is_array($element['payment_method'][$option_id])) {
        continue;
      }

      $radio = &$element['payment_method'][$option_id];
      $this->addClass(
        $radio,
        '#attributes',
        'commerce-novapay-payment-option',
      );
      $this->addClass(
        $radio,
        '#wrapper_attributes',
        'commerce-novapay-payment-option-wrapper',
      );
      $attributes = $radio['#attributes'];
      assert(is_array($attributes));
      $attributes['data-payment-gateway'] = 'novapay';
      $radio['#attributes'] = $attributes;
      $radio[self::LOGO_MARKER] = [
        'uri' => $this->logoUri(),
        'alt' => (string) $payment_option->getLabel(),
      ];
      unset($radio);
    }
  }

  /**
   * Checks whether a gateway entity uses the NovaPay plugin.
   */
  private function isNovaPayGateway(string $gateway_id): bool {
    if (array_key_exists($gateway_id, $this->novaPayGateways)) {
      return $this->novaPayGateways[$gateway_id];
    }

    $gateway = $this->entityTypeManager
      ->getStorage('commerce_payment_gateway')
      ->load($gateway_id);
    $is_novapay = $gateway instanceof PaymentGatewayInterface
      && $gateway->getPluginId() === self::GATEWAY_PLUGIN_ID;
    $this->novaPayGateways[$gateway_id] = $is_novapay;

    return $is_novapay;
  }

  /**
   * Returns a base-path-aware URL for the packaged logo.
   */
  private function logoUri(): string {
    $base_path = $this->requestStack->getCurrentRequest()?->getBasePath() ?? '';

    return $base_path . '/'
      . $this->moduleExtensionList->getPath('commerce_novapay')
      . '/assets/images/logo.svg';
  }

  /**
   * Adds a unique class to an element attribute collection.
   *
   * @phpstan-param array<array-key, mixed> $element
   */
  private function addClass(
    array &$element,
    string $attribute_key,
    string $class,
  ): void {
    $attributes = $element[$attribute_key] ?? [];
    if (!is_array($attributes)) {
      $attributes = [];
    }
    $classes = $attributes['class'] ?? [];
    if (!is_array($classes)) {
      $classes = [];
    }
    if (!in_array($class, $classes, TRUE)) {
      $classes[] = $class;
    }
    $attributes['class'] = $classes;
    $element[$attribute_key] = $attributes;
  }

}
