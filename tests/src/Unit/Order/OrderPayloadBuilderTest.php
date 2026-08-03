<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Order;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\commerce_novapay\Exception\OrderPayloadException;
use Drupal\commerce_novapay\Order\OrderPayloadBuilder;
use Drupal\commerce_novapay\Phone\OrderPhoneResolverInterface;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_price\Price;
use Drupal\profile\Entity\ProfileInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests Commerce order mapping to NovaPay request DTOs.
 */
#[CoversClass(OrderPayloadBuilder::class)]
#[CoversClass(OrderPayloadException::class)]
#[Group('commerce_novapay')]
final class OrderPayloadBuilderTest extends TestCase {

  /**
   * The builder under test.
   */
  private OrderPayloadBuilder $builder;

  /**
   * The order-context phone resolver.
   */
  private OrderPhoneResolverInterface&MockObject $phoneResolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->phoneResolver = $this->createMock(
      OrderPhoneResolverInterface::class,
    );
    $this->phoneResolver->method('resolve')->willReturn('+380501234567');
    $this->builder = new OrderPayloadBuilder($this->phoneResolver);
  }

  /**
   * Tests customer fields and unambiguous order/gateway metadata.
   */
  public function testBuildsSessionRequestFromOrderAndGateway(): void {
    $order = $this->createOrder(new Price('1250.00', 'UAH'));
    $order->method('getEmail')->willReturn(' buyer@example.com ');
    $order->method('getBillingProfile')
      ->willReturn($this->createBillingProfile());
    $gateway = $this->createGateway();

    $request = $this->builder->buildSessionRequest(
      $order,
      $gateway,
      'https://merchant.example/payment/notify/novapay_test',
      'https://merchant.example/checkout/return',
      'https://merchant.example/checkout/cancel',
    );

    self::assertSame(
      [
        'client_phone' => '+380501234567',
        'client_first_name' => 'Іван',
        'client_last_name' => 'Петренко',
        'client_patronymic' => 'Олександрович',
        'client_email' => 'buyer@example.com',
        'metadata' => [
          'source' => 'drupal_commerce',
          'commerce_order_id' => '42',
          'commerce_order_uuid' => 'dd3a9ee5-e090-44d1-924c-505b9272f85e',
          'commerce_order_number' => 'ORDER-1001',
          'commerce_payment_gateway_id' => 'novapay_test',
          'commerce_payment_gateway_uuid' => 'e46d8426-d757-4469-b50e-58ea3d50aa4a',
        ],
        'callback_url' => 'https://merchant.example/payment/notify/novapay_test',
        'success_url' => 'https://merchant.example/checkout/return',
        'fail_url' => 'https://merchant.example/checkout/cancel',
      ],
      $request->toArray(),
    );
  }

  /**
   * Tests exact decimal products and hold options without float arithmetic.
   */
  public function testBuildsExactDetailedPaymentProducts(): void {
    $items = [
      $this->createOrderItem('1', '0.10', 'Small item'),
      $this->createOrderItem('2.000000', '0.10', 'Two items'),
    ];
    $order = $this->createOrder(new Price('0.30', 'UAH'), $items);

    $request = $this->builder->buildPaymentRequest(
      $order,
      'session-123',
      TransactionMode::Hold,
      '31316718',
    );

    self::assertSame(
      [
        'session_id' => 'session-123',
        'amount' => '0.30',
        'use_hold' => TRUE,
        'external_id' => 'ORDER-1001',
        'identifier' => '31316718',
        'products' => [
          [
            'count' => 1,
            'price' => '0.10',
            'description' => 'Small item',
          ],
          [
            'count' => 2,
            'price' => '0.10',
            'description' => 'Two items',
          ],
        ],
      ],
      $request->toArray(),
    );
  }

  /**
   * Tests aggregate fallback when item totals differ from the order balance.
   */
  public function testAggregatesProductsForOrderLevelAdjustment(): void {
    $items = [$this->createOrderItem('1', '10.00', 'Adjusted item')];
    $order = $this->createOrder(new Price('9.99', 'UAH'), $items);

    $request = $this->builder->buildPaymentRequest(
      $order,
      'session-123',
      TransactionMode::Direct,
    );

    self::assertSame('9.99', $request->toArray()['amount']);
    self::assertSame(
      [[
        'count' => 1,
        'price' => '9.99',
        'description' => 'Order ORDER-1001',
      ]],
      $request->toArray()['products'],
    );
  }

  /**
   * Tests aggregate fallback for quantities NovaPay cannot represent as int32.
   */
  #[DataProvider('unsupportedQuantityProvider')]
  public function testAggregatesProductsForUnsupportedQuantity(
    string $quantity,
  ): void {
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('getQuantity')->willReturn($quantity);
    $item->expects(self::never())->method('getAdjustedUnitPrice');
    $order = $this->createOrder(new Price('10.00', 'UAH'), [$item]);

    $request = $this->builder->buildPaymentRequest(
      $order,
      'session-123',
      TransactionMode::Direct,
    );

    self::assertSame(
      [[
        'count' => 1,
        'price' => '10.00',
        'description' => 'Order ORDER-1001',
      ]],
      $request->toArray()['products'],
    );
  }

  /**
   * Provides non-int32 Commerce quantities.
   *
   * @return iterable<string, array{string}>
   *   Unsupported quantity strings.
   */
  public static function unsupportedQuantityProvider(): iterable {
    yield 'fractional' => ['1.5'];
    yield 'zero' => ['0'];
    yield 'negative' => ['-1'];
    yield 'larger than int32' => ['2147483648'];
  }

  /**
   * Tests aggregate fallback for missing or incompatible item prices.
   */
  #[DataProvider('unsupportedUnitPriceProvider')]
  public function testAggregatesProductsForUnsupportedUnitPrice(
    ?Price $unit_price,
  ): void {
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('getQuantity')->willReturn('1');
    $item->method('getAdjustedUnitPrice')->willReturn($unit_price);
    $item->method('getTitle')->willReturn('Item');
    $order = $this->createOrder(new Price('10.00', 'UAH'), [$item]);

    $request = $this->builder->buildPaymentRequest(
      $order,
      'session-123',
      TransactionMode::Direct,
    );

    self::assertSame('10.00', $request->toArray()['products'][0]['price']);
    self::assertSame(1, $request->toArray()['products'][0]['count']);
  }

  /**
   * Provides item prices that require aggregate fallback.
   *
   * @return iterable<string, array{\Drupal\commerce_price\Price|null}>
   *   Unsupported adjusted unit prices.
   */
  public static function unsupportedUnitPriceProvider(): iterable {
    yield 'missing' => [NULL];
    yield 'wrong currency' => [new Price('10.00', 'USD')];
    yield 'zero' => [new Price('0.00', 'UAH')];
  }

  /**
   * Tests that non-UAH balances are rejected before item conversion.
   */
  public function testRejectsNonUahOrderBeforeBuildingProducts(): void {
    $order = $this->createOrder(new Price('10.00', 'USD'));
    $order->expects(self::never())->method('getItems');

    $this->expectException(OrderPayloadException::class);
    $this->expectExceptionMessage('NovaPay payments require a UAH order balance.');
    $this->builder->buildPaymentRequest(
      $order,
      'session-123',
      TransactionMode::Direct,
    );
  }

  /**
   * Tests that a non-UAH order is rejected before session creation data.
   */
  public function testRejectsNonUahOrderBeforeBuildingSession(): void {
    $order = $this->createOrder(new Price('10.00', 'USD'));
    $order->expects(self::never())->method('hasField');

    $this->expectException(OrderPayloadException::class);
    $this->expectExceptionMessage('NovaPay payments require a UAH order balance.');
    $this->builder->buildSessionRequest(
      $order,
      $this->createGateway(),
      'https://merchant.example/callback',
      'https://merchant.example/return',
      'https://merchant.example/cancel',
    );
  }

  /**
   * Tests missing and non-positive order balances.
   */
  #[DataProvider('invalidBalanceProvider')]
  public function testRejectsInvalidBalance(
    ?Price $balance,
    string $expected_message,
  ): void {
    $order = $this->createOrder($balance);

    $this->expectException(OrderPayloadException::class);
    $this->expectExceptionMessage($expected_message);
    $this->builder->buildPaymentRequest(
      $order,
      'session-123',
      TransactionMode::Direct,
    );
  }

  /**
   * Provides invalid payable balances.
   *
   * @return iterable<string, array{\Drupal\commerce_price\Price|null, string}>
   *   Invalid balance and safe exception message.
   */
  public static function invalidBalanceProvider(): iterable {
    yield 'missing' => [
      NULL,
      'The Commerce order does not have a payable balance.',
    ];
    yield 'zero' => [
      new Price('0.00', 'UAH'),
      'The Commerce order balance must be positive.',
    ];
    yield 'negative' => [
      new Price('-0.01', 'UAH'),
      'The Commerce order balance must be positive.',
    ];
  }

  /**
   * Tests rejection when no existing or checkout-collected phone is found.
   */
  public function testRejectsMissingOrderPhone(): void {
    $order = $this->createOrder(new Price('10.00', 'UAH'));
    $phone_resolver = $this->createMock(OrderPhoneResolverInterface::class);
    $phone_resolver->method('resolve')->with($order)->willReturn(NULL);
    $builder = new OrderPayloadBuilder($phone_resolver);

    $this->expectException(OrderPayloadException::class);
    $this->expectExceptionMessage('The NovaPay customer phone is unavailable.');
    $builder->buildSessionRequest(
      $order,
      $this->createGateway(),
      'https://merchant.example/callback',
      'https://merchant.example/return',
      'https://merchant.example/cancel',
    );
  }

  /**
   * Creates an order mock with stable identifiers and optional items.
   *
   * @param \Drupal\commerce_price\Price|null $balance
   *   Order balance returned by the mock.
   * @param list<\Drupal\commerce_order\Entity\OrderItemInterface> $items
   *   Order items returned by the mock.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface&\PHPUnit\Framework\MockObject\MockObject
   *   The configured order mock.
   */
  private function createOrder(
    ?Price $balance,
    array $items = [],
  ): OrderInterface & MockObject {
    $order = $this->createMock(OrderInterface::class);
    $order->method('id')->willReturn(42);
    $order->method('uuid')
      ->willReturn('dd3a9ee5-e090-44d1-924c-505b9272f85e');
    $order->method('getOrderNumber')->willReturn('ORDER-1001');
    $order->method('getBalance')->willReturn($balance);
    $order->method('getItems')->willReturn($items);

    return $order;
  }

  /**
   * Creates an order item with an adjusted unit price.
   */
  private function createOrderItem(
    string $quantity,
    string $unit_price,
    string $title,
  ): OrderItemInterface {
    $item = $this->createMock(OrderItemInterface::class);
    $item->method('getQuantity')->willReturn($quantity);
    $item->method('getAdjustedUnitPrice')
      ->willReturn(new Price($unit_price, 'UAH'));
    $item->method('getTitle')->willReturn($title);

    return $item;
  }

  /**
   * Creates a billing profile containing address name fields.
   */
  private function createBillingProfile(): ProfileInterface {
    $profile = $this->createMock(ProfileInterface::class);
    $profile->method('hasField')->with('address')->willReturn(TRUE);
    $address = $this->createMock(FieldItemListInterface::class);
    $address->method('getValue')->willReturn([[
      'given_name' => ' Іван ',
      'family_name' => ' Петренко ',
      'additional_name' => ' Олександрович ',
    ]]);
    $profile->method('get')->with('address')->willReturn($address);

    return $profile;
  }

  /**
   * Creates a payment gateway mock with stable identifiers.
   */
  private function createGateway(): PaymentGatewayInterface {
    $gateway = $this->createMock(PaymentGatewayInterface::class);
    $gateway->method('id')->willReturn('novapay_test');
    $gateway->method('uuid')
      ->willReturn('e46d8426-d757-4469-b50e-58ea3d50aa4a');

    return $gateway;
  }

}
