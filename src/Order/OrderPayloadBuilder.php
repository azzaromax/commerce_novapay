<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Order;

use Drupal\commerce_novapay\Api\Dto\Request\AddPaymentRequest;
use Drupal\commerce_novapay\Api\Dto\Request\CreateSessionRequest;
use Drupal\commerce_novapay\Exception\OrderPayloadException;
use Drupal\commerce_novapay\Phone\OrderPhoneResolverInterface;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_price\Price;

/**
 * Converts Commerce order data to NovaPay acquiring request DTOs.
 */
final class OrderPayloadBuilder implements OrderPayloadBuilderInterface {

  private const CURRENCY_CODE = 'UAH';

  private const MAX_PRODUCT_COUNT = '2147483647';

  /**
   * Constructs the order payload builder.
   */
  public function __construct(
    private readonly OrderPhoneResolverInterface $phone_resolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function buildSessionRequest(
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
    string $callback_url,
    string $success_url,
    string $fail_url,
    int $success_redirect_timeout = RuntimeProfile::DEFAULT_SUCCESS_REDIRECT_TIMEOUT,
  ): CreateSessionRequest {
    $this->getPayableBalance($order);
    $order_id = $this->getOrderId($order);
    $order_uuid = $this->getOrderUuid($order);
    $gateway_id = $this->getGatewayId($gateway);
    $gateway_uuid = $this->getGatewayUuid($gateway);
    [$first_name, $last_name, $patronymic] = $this->getBillingName($order);
    $email = $this->getOptionalString($order->getEmail());
    $metadata = [
      'source' => 'drupal_commerce',
      'commerce_order_id' => $order_id,
      'commerce_order_uuid' => $order_uuid,
    ];
    $order_number = $this->getOptionalString($order->getOrderNumber());
    if ($order_number !== NULL) {
      $metadata['commerce_order_number'] = $order_number;
    }
    $metadata += [
      'commerce_payment_gateway_id' => $gateway_id,
      'commerce_payment_gateway_uuid' => $gateway_uuid,
    ];

    return new CreateSessionRequest(
      client_phone: $this->getPhone($order),
      client_first_name: $first_name,
      client_last_name: $last_name,
      client_patronymic: $patronymic,
      client_email: $email,
      metadata: $metadata,
      callback_url: $callback_url,
      success_url: $success_url,
      fail_url: $fail_url,
      success_redirect_timeout: $success_redirect_timeout === 0
        ? NULL
        : $success_redirect_timeout,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildPaymentRequest(
    OrderInterface $order,
    string $session_id,
    TransactionMode $transaction_mode,
    string $recipient_identifier = '',
  ): AddPaymentRequest {
    $balance = $this->getPayableBalance($order);
    $order_reference = $this->getOrderReference($order);

    return new AddPaymentRequest(
      session_id: $session_id,
      amount: $balance->getNumber(),
      use_hold: $transaction_mode === TransactionMode::Hold,
      external_id: $order_reference,
      identifier: $this->getOptionalString($recipient_identifier),
      products: $this->buildProducts($order, $balance, $order_reference),
    );
  }

  /**
   * Gets and validates the current payable order balance.
   */
  private function getPayableBalance(OrderInterface $order): Price {
    $balance = $order->getBalance();
    if (!$balance instanceof Price) {
      throw OrderPayloadException::missingBalance();
    }
    if ($balance->getCurrencyCode() !== self::CURRENCY_CODE) {
      throw OrderPayloadException::unsupportedCurrency();
    }
    if (!$balance->isPositive()) {
      throw OrderPayloadException::nonPositiveBalance();
    }

    return $balance;
  }

  /**
   * Builds exact detailed products or one safe aggregate product.
   *
   * @return list<array{count: int, price: string, description: string}>
   *   NovaPay product values.
   */
  private function buildProducts(
    OrderInterface $order,
    Price $balance,
    string $order_number,
  ): array {
    $products = [];
    $products_total = new Price('0', self::CURRENCY_CODE);

    foreach ($order->getItems() as $index => $order_item) {
      $count = $this->getProductCount($order_item->getQuantity());
      if ($count === NULL) {
        return $this->buildAggregateProduct($balance, $order_number);
      }

      $unit_price = $order_item->getAdjustedUnitPrice();
      if (
        !$unit_price instanceof Price
        || $unit_price->getCurrencyCode() !== self::CURRENCY_CODE
        || !$unit_price->isPositive()
      ) {
        return $this->buildAggregateProduct($balance, $order_number);
      }

      $line_total = $unit_price->multiply((string) $count);
      $products_total = $products_total->add($line_total);
      $description = trim($order_item->getTitle());
      if ($description === '') {
        $description = 'Order item ' . ((int) $index + 1);
      }
      $products[] = [
        'count' => $count,
        'price' => $unit_price->getNumber(),
        'description' => $description,
      ];
    }

    if ($products === [] || !$products_total->equals($balance)) {
      return $this->buildAggregateProduct($balance, $order_number);
    }

    return $products;
  }

  /**
   * Builds one product guaranteed to equal the payable balance.
   *
   * @return list<array{count: int, price: string, description: string}>
   *   The aggregate NovaPay product.
   */
  private function buildAggregateProduct(
    Price $balance,
    string $order_number,
  ): array {
    return [[
      'count' => 1,
      'price' => $balance->getNumber(),
      'description' => 'Order ' . $order_number,
    ]];
  }

  /**
   * Converts a positive whole-number Commerce quantity to int32.
   */
  private function getProductCount(string $quantity): ?int {
    if (preg_match('/^([0-9]+)(?:\.0+)?$/D', $quantity, $matches) !== 1) {
      return NULL;
    }

    $normalized = ltrim($matches[1], '0');
    if ($normalized === '') {
      return NULL;
    }
    if (
      strlen($normalized) > strlen(self::MAX_PRODUCT_COUNT)
      || (
        strlen($normalized) === strlen(self::MAX_PRODUCT_COUNT)
        && strcmp($normalized, self::MAX_PRODUCT_COUNT) > 0
      )
    ) {
      return NULL;
    }

    return (int) $normalized;
  }

  /**
   * Gets the required order phone field.
   */
  private function getPhone(OrderInterface $order): string {
    $phone = $this->phone_resolver->resolve($order);
    if ($phone === NULL) {
      throw OrderPayloadException::missingPhone();
    }

    return $phone;
  }

  /**
   * Gets optional billing name parts from the address field.
   *
   * @return array{?string, ?string, ?string}
   *   First name, last name, and patronymic.
   */
  private function getBillingName(OrderInterface $order): array {
    $profile = $order->getBillingProfile();
    if ($profile === NULL || !$profile->hasField('address')) {
      return [NULL, NULL, NULL];
    }

    $values = $profile->get('address')->getValue();
    $address = is_array($values[0] ?? NULL) ? $values[0] : [];

    return [
      $this->getOptionalString($address['given_name'] ?? NULL),
      $this->getOptionalString($address['family_name'] ?? NULL),
      $this->getOptionalString($address['additional_name'] ?? NULL),
    ];
  }

  /**
   * Gets a stable external order reference before or after order placement.
   */
  private function getOrderReference(OrderInterface $order): string {
    $order_number = $this->getOptionalString($order->getOrderNumber());
    if ($order_number !== NULL) {
      return $order_number;
    }

    return $this->getOrderId($order);
  }

  /**
   * Gets the required Commerce order entity ID.
   */
  private function getOrderId(OrderInterface $order): string {
    return $this->requireIdentifier(
      $order->id(),
      OrderPayloadException::invalidOrderIdentifier(...),
    );
  }

  /**
   * Gets the required Commerce order UUID.
   */
  private function getOrderUuid(OrderInterface $order): string {
    return $this->requireIdentifier(
      $order->uuid(),
      OrderPayloadException::invalidOrderIdentifier(...),
    );
  }

  /**
   * Gets the required Commerce payment gateway config ID.
   */
  private function getGatewayId(PaymentGatewayInterface $gateway): string {
    return $this->requireIdentifier(
      $gateway->id(),
      OrderPayloadException::invalidGatewayIdentifier(...),
    );
  }

  /**
   * Gets the required Commerce payment gateway UUID.
   */
  private function getGatewayUuid(PaymentGatewayInterface $gateway): string {
    return $this->requireIdentifier(
      $gateway->uuid(),
      OrderPayloadException::invalidGatewayIdentifier(...),
    );
  }

  /**
   * Converts an entity identifier to a required string.
   *
   * @param mixed $value
   *   Entity identifier value.
   * @param callable(): \Drupal\commerce_novapay\Exception\OrderPayloadException $exception_factory
   *   Safe exception factory for the identifier type.
   */
  private function requireIdentifier(
    mixed $value,
    callable $exception_factory,
  ): string {
    if (!is_int($value) && !is_string($value)) {
      throw $exception_factory();
    }

    $identifier = trim((string) $value);
    if ($identifier === '') {
      throw $exception_factory();
    }

    return $identifier;
  }

  /**
   * Trims an optional scalar string value.
   */
  private function getOptionalString(mixed $value): ?string {
    if (!is_string($value)) {
      return NULL;
    }

    $value = trim($value);
    return $value !== '' ? $value : NULL;
  }

}
