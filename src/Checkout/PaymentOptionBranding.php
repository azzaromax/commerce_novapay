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
  private array $logoGateways = [];

  /**
   * Constructs the payment option branding service.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Brands NovaPay payment options and the selected review summary.
   *
   * @phpstan-param array<array-key, mixed> $form
   */
  public function alter(
    array &$form,
    ?PaymentGatewayInterface $selected_gateway = NULL,
  ): void {
    $this->alterElement($form);

    if (
      ($form['#step_id'] ?? NULL) === 'review'
      && $selected_gateway instanceof PaymentGatewayInterface
      && $this->shouldDisplayLogo((string) $selected_gateway->id())
    ) {
      $this->brandSelectedPaymentSummary($form, $selected_gateway);
    }
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

    $variables['title'] = $this->buildLogo(
      $logo['alt'],
      'commerce-novapay-payment-option-logo',
    );
  }

  /**
   * Replaces the selected NovaPay gateway label in the review pane.
   *
   * @phpstan-param array<array-key, mixed> $form
   */
  private function brandSelectedPaymentSummary(
    array &$form,
    PaymentGatewayInterface $gateway,
  ): void {
    $summary = $form['review']['payment_information']['summary'] ?? NULL;
    if (
      !is_array($summary)
      || !isset($summary['payment_gateway'])
      || !is_array($summary['payment_gateway'])
      || !array_key_exists('#markup', $summary['payment_gateway'])
    ) {
      return;
    }

    $form['review']['payment_information']['summary']['payment_gateway'] =
      $this->buildLogo(
        (string) $gateway->getPlugin()->getDisplayLabel(),
        'commerce-novapay-payment-summary-logo',
      );
  }

  /**
   * Builds an accessible render array for the packaged NovaPay logo.
   *
   * @return array<string, mixed>
   *   The image render array.
   */
  private function buildLogo(string $alt, string $class): array {
    return [
      '#theme' => 'image',
      '#uri' => $this->logoUri(),
      '#alt' => $alt,
      '#width' => 124,
      '#height' => 25,
      '#attributes' => [
        'class' => [$class],
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
        || !$this->shouldDisplayLogo($payment_option->getPaymentGatewayId())
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
   * Checks whether a NovaPay gateway is configured to display its logo.
   */
  private function shouldDisplayLogo(string $gateway_id): bool {
    if (array_key_exists($gateway_id, $this->logoGateways)) {
      return $this->logoGateways[$gateway_id];
    }

    $gateway = $this->entityTypeManager
      ->getStorage('commerce_payment_gateway')
      ->load($gateway_id);
    if (
      !$gateway instanceof PaymentGatewayInterface
      || $gateway->getPluginId() !== self::GATEWAY_PLUGIN_ID
    ) {
      $this->logoGateways[$gateway_id] = FALSE;

      return FALSE;
    }

    $configuration = $gateway->getPluginConfiguration();
    $display_logo = !array_key_exists('display_logo', $configuration)
      || (bool) $configuration['display_logo'];
    $this->logoGateways[$gateway_id] = $display_logo;

    return $display_logo;
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
