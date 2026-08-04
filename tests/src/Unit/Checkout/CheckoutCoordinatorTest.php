<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Checkout;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Url;
use Drupal\commerce_novapay\Api\Dto\Request\AddPaymentRequest;
use Drupal\commerce_novapay\Api\Dto\Request\CreateSessionRequest;
use Drupal\commerce_novapay\Api\Dto\Response\PaymentResponse;
use Drupal\commerce_novapay\Api\Dto\Response\SessionResponse;
use Drupal\commerce_novapay\Api\NovaPayApiClientInterface;
use Drupal\commerce_novapay\Checkout\CheckoutCoordinator;
use Drupal\commerce_novapay\Credential\Credentials;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\ApiTransportException;
use Drupal\commerce_novapay\Exception\CheckoutPreparationException;
use Drupal\commerce_novapay\Order\OrderPayloadBuilderInterface;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderStorageInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PaymentStorageInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_price\Price;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests locked NovaPay checkout creation and active-session reuse.
 */
#[CoversClass(CheckoutCoordinator::class)]
#[Group('commerce_novapay')]
final class CheckoutCoordinatorTest extends TestCase {

  private const REQUEST_TIME = 1700000000;

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface&MockObject $entityTypeManager;

  /**
   * The Commerce order storage.
   */
  private OrderStorageInterface&MockObject $orderStorage;

  /**
   * The Commerce payment storage.
   */
  private PaymentStorageInterface&MockObject $paymentStorage;

  /**
   * The NovaPay API client.
   */
  private NovaPayApiClientInterface&MockObject $apiClient;

  /**
   * The order payload builder.
   */
  private OrderPayloadBuilderInterface&MockObject $payloadBuilder;

  /**
   * The lock protecting the complete NovaPay checkout operation.
   */
  private LockBackendInterface&MockObject $checkoutLock;

  /**
   * The Drupal request time service.
   */
  private TimeInterface&MockObject $time;

  /**
   * The locked Commerce order.
   */
  private OrderInterface&MockObject $order;

  /**
   * The NovaPay gateway entity.
   */
  private PaymentGatewayInterface&MockObject $gateway;

  /**
   * The NovaPay gateway plugin.
   */
  private OffsitePaymentGatewayInterface&RuntimeConfigurationProviderInterface&MockObject $plugin;

  /**
   * The unsaved checkout payment.
   */
  private PaymentInterface&MockObject $payment;

  /**
   * The tested coordinator.
   */
  private CheckoutCoordinator $coordinator;

  /**
   * The payable order balance.
   */
  private Price $balance;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(
      EntityTypeManagerInterface::class,
    );
    $this->orderStorage = $this->createMock(OrderStorageInterface::class);
    $this->paymentStorage = $this->createMock(PaymentStorageInterface::class);
    $this->apiClient = $this->createMock(NovaPayApiClientInterface::class);
    $this->payloadBuilder = $this->createMock(
      OrderPayloadBuilderInterface::class,
    );
    $this->checkoutLock = $this->createMock(LockBackendInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->order = $this->createMock(OrderInterface::class);
    $this->gateway = $this->createMock(PaymentGatewayInterface::class);
    /** @var \Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface&\Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface&\PHPUnit\Framework\MockObject\MockObject $plugin */
    $plugin = $this->createMockForIntersectionOfInterfaces([
      OffsitePaymentGatewayInterface::class,
      RuntimeConfigurationProviderInterface::class,
    ]);
    $this->plugin = $plugin;
    $this->payment = $this->createMock(PaymentInterface::class);
    $this->balance = new Price('100.00', 'UAH');

    $this->entityTypeManager->method('getStorage')->willReturnMap([
      ['commerce_order', $this->orderStorage],
      ['commerce_payment', $this->paymentStorage],
    ]);
    $this->orderStorage->method('loadForUpdate')->with(10)
      ->willReturn($this->order);
    $this->order->method('id')->willReturn(10);
    $this->order->method('getBalance')->willReturn($this->balance);
    $gateway_items = $this->createMock(
      EntityReferenceFieldItemListInterface::class,
    );
    $gateway_items->method('referencedEntities')->willReturn([$this->gateway]);
    $this->order->method('get')->with('payment_gateway')
      ->willReturn($gateway_items);
    $this->gateway->method('id')->willReturn('novapay_test');
    $this->gateway->method('getPluginId')->willReturn('novapay');
    $this->gateway->method('getPlugin')->willReturn($this->plugin);
    $this->payment->method('getOrderId')->willReturn(10);
    $order_id_items = $this->createMock(FieldItemListInterface::class);
    $order_id_items->method('getString')->willReturn('10');
    $this->payment->method('get')->with('order_id')
      ->willReturn($order_id_items);
    $this->payment->method('getPaymentGateway')->willReturn($this->gateway);
    $this->plugin->method('getRuntimeConfiguration')->willReturn(
      $this->createRuntimeConfiguration(),
    );
    $notify_url = $this->createMock(Url::class);
    $notify_url->method('toString')->willReturn(
      'https://merchant.example/novapay/notify',
    );
    $this->plugin->method('getNotifyUrl')->willReturn($notify_url);
    $this->time->method('getRequestTime')->willReturn(self::REQUEST_TIME);

    $this->coordinator = new CheckoutCoordinator(
      $this->entityTypeManager,
      $this->apiClient,
      $this->payloadBuilder,
      $this->checkoutLock,
      $this->time,
    );
  }

  /**
   * Tests creation and persistence of a pending NovaPay payment.
   */
  public function testCreatesAndPersistsPaymentUnderOrderLock(): void {
    $this->checkoutLock->expects(self::exactly(2))->method('acquire')
      ->with('commerce_novapay_checkout:10', 60.0)
      ->willReturn(TRUE);
    $this->checkoutLock->expects(self::once())->method('release')
      ->with('commerce_novapay_checkout:10');
    $this->paymentStorage->method('loadByProperties')->willReturn([]);
    $session_request = new CreateSessionRequest('+380501234567');
    $payment_request = new AddPaymentRequest(
      'session-id',
      '100.00',
      FALSE,
    );
    $this->payloadBuilder->expects(self::once())
      ->method('buildSessionRequest')
      ->with(
        $this->order,
        $this->gateway,
        'https://merchant.example/novapay/notify',
        'https://merchant.example/return',
        'https://merchant.example/cancel',
      )
      ->willReturn($session_request);
    $this->apiClient->expects(self::once())
      ->method('createSession')
      ->with($this->plugin, $session_request)
      ->willReturn(SessionResponse::fromArray(['id' => 'session-id']));
    $this->payloadBuilder->expects(self::once())
      ->method('buildPaymentRequest')
      ->with(
        $this->order,
        'session-id',
        TransactionMode::Direct,
        '',
      )
      ->willReturn($payment_request);
    $response = PaymentResponse::fromArray(
      [
        'id' => 'operation-id',
        'url' => 'https://qecom.novapay.ua/session-id',
      ],
      NovaPayMode::Test,
    );
    $this->apiClient->expects(self::once())
      ->method('addPayment')
      ->with($this->plugin, $payment_request)
      ->willReturn($response);

    $this->payment->expects(self::once())->method('setAmount')
      ->with($this->balance)->willReturnSelf();
    $this->payment->expects(self::once())->method('setState')
      ->with('pending')->willReturnSelf();
    $this->payment->expects(self::once())->method('setRemoteId')
      ->with('session-id')->willReturnSelf();
    $this->payment->expects(self::once())->method('setRemoteState')
      ->with('created')->willReturnSelf();
    $this->payment->expects(self::once())->method('setExpiresTime')
      ->with(self::REQUEST_TIME + 2592000)->willReturnSelf();
    $this->payment->expects(self::exactly(2))->method('set')
      ->willReturnCallback(function (string $field, string $value): PaymentInterface {
        self::assertContains(
          [$field, $value],
          [
            ['novapay_operation_id', 'operation-id'],
            ['novapay_payment_url', 'https://qecom.novapay.ua/session-id'],
          ],
        );
        return $this->payment;
      });
    $this->payment->expects(self::once())->method('save');
    $this->orderStorage->expects(self::once())->method('releaseLock')->with(10);

    self::assertSame(
      'https://qecom.novapay.ua/session-id',
      $this->coordinator->prepareRedirect(
        $this->payment,
        'https://merchant.example/return',
        'https://merchant.example/cancel',
      ),
    );
  }

  /**
   * Tests that a matching pending payment prevents duplicate API calls.
   */
  public function testReusesNewestMatchingPendingPayment(): void {
    $this->allowCheckoutLock();
    $candidate = $this->createMock(PaymentInterface::class);
    $candidate->method('id')->willReturn(25);
    $candidate->method('getAmount')->willReturn($this->balance);
    $candidate->method('getRemoteId')->willReturn('existing-session');
    $candidate->method('getExpiresTime')->willReturn(
      self::REQUEST_TIME + 3600,
    );
    $operation = $this->createMock(FieldItemListInterface::class);
    $operation->method('getString')->willReturn('existing-operation');
    $url = $this->createMock(FieldItemListInterface::class);
    $url->method('getString')->willReturn(
      'https://qecom.novapay.ua/existing-session',
    );
    $candidate->method('get')->willReturnMap([
      ['novapay_operation_id', $operation],
      ['novapay_payment_url', $url],
    ]);
    $this->paymentStorage->expects(self::once())
      ->method('loadByProperties')
      ->with([
        'order_id' => 10,
        'payment_gateway' => 'novapay_test',
        'state' => 'pending',
      ])
      ->willReturn([25 => $candidate]);
    $this->apiClient->expects(self::never())->method('createSession');
    $this->apiClient->expects(self::never())->method('addPayment');
    $this->payloadBuilder->expects(self::never())
      ->method('buildSessionRequest');
    $this->payment->expects(self::never())->method('save');
    $this->orderStorage->expects(self::once())->method('releaseLock')->with(10);
    $this->checkoutLock->expects(self::once())->method('release')
      ->with('commerce_novapay_checkout:10');

    self::assertSame(
      'https://qecom.novapay.ua/existing-session',
      $this->coordinator->prepareRedirect(
        $this->payment,
        'https://merchant.example/return',
        'https://merchant.example/cancel',
      ),
    );
  }

  /**
   * Tests that an API failure never leaks the acquired order lock.
   */
  public function testReleasesOrderLockAfterApiFailure(): void {
    $this->allowCheckoutLock();
    $this->paymentStorage->method('loadByProperties')->willReturn([]);
    $session_request = new CreateSessionRequest('+380501234567');
    $this->payloadBuilder->method('buildSessionRequest')
      ->willReturn($session_request);
    $this->apiClient->method('createSession')->willThrowException(
      ApiTransportException::requestFailed(),
    );
    $this->apiClient->expects(self::never())->method('addPayment');
    $this->payment->expects(self::never())->method('save');
    $this->orderStorage->expects(self::once())->method('releaseLock')->with(10);
    $this->checkoutLock->expects(self::once())->method('release')
      ->with('commerce_novapay_checkout:10');

    try {
      $this->coordinator->prepareRedirect(
        $this->payment,
        'https://merchant.example/return',
        'https://merchant.example/cancel',
      );
      self::fail('A checkout preparation exception was not thrown.');
    }
    catch (CheckoutPreparationException $exception) {
      self::assertSame('create_session', $exception->getStage());
      self::assertSame(
        ApiTransportException::class,
        $exception->getSourceClass(),
      );
      self::assertNull($exception->getHttpStatus());
      self::assertNull($exception->getApiDetail());
      self::assertNull($exception->getPrevious());
    }
  }

  /**
   * Tests that an expired pending payment is never reused.
   */
  public function testDoesNotReuseExpiredPendingPayment(): void {
    $this->allowCheckoutLock();
    $candidate = $this->createMock(PaymentInterface::class);
    $candidate->method('id')->willReturn(25);
    $candidate->method('getAmount')->willReturn($this->balance);
    $candidate->method('getRemoteId')->willReturn('expired-session');
    $candidate->method('getExpiresTime')->willReturn(self::REQUEST_TIME - 1);
    $operation = $this->createMock(FieldItemListInterface::class);
    $operation->method('getString')->willReturn('expired-operation');
    $url = $this->createMock(FieldItemListInterface::class);
    $url->method('getString')->willReturn(
      'https://qecom.novapay.ua/expired-session',
    );
    $candidate->method('get')->willReturnMap([
      ['novapay_operation_id', $operation],
      ['novapay_payment_url', $url],
    ]);
    $this->paymentStorage->method('loadByProperties')->willReturn([$candidate]);
    $session_request = new CreateSessionRequest('+380501234567');
    $this->payloadBuilder->method('buildSessionRequest')
      ->willReturn($session_request);
    $this->apiClient->expects(self::once())->method('createSession')
      ->willThrowException(ApiTransportException::requestFailed());
    $this->orderStorage->expects(self::once())->method('releaseLock')->with(10);
    $this->checkoutLock->expects(self::once())->method('release')
      ->with('commerce_novapay_checkout:10');

    $this->expectException(CheckoutPreparationException::class);
    $this->coordinator->prepareRedirect(
      $this->payment,
      'https://merchant.example/return',
      'https://merchant.example/cancel',
    );
  }

  /**
   * Tests that lock contention stops checkout before the order is loaded.
   */
  public function testCheckoutLockContentionFailsClosed(): void {
    $this->checkoutLock->expects(self::once())->method('acquire')
      ->with('commerce_novapay_checkout:10', 60.0)
      ->willReturn(FALSE);
    $this->checkoutLock->expects(self::once())->method('wait')
      ->with('commerce_novapay_checkout:10', 60)
      ->willReturn(TRUE);
    $this->checkoutLock->expects(self::never())->method('release');
    $this->orderStorage->expects(self::never())->method('loadForUpdate');

    try {
      $this->coordinator->prepareRedirect(
        $this->payment,
        'https://merchant.example/return',
        'https://merchant.example/cancel',
      );
      self::fail('A checkout preparation exception was not thrown.');
    }
    catch (CheckoutPreparationException $exception) {
      self::assertSame('checkout_lock', $exception->getStage());
    }
  }

  /**
   * Allows initial acquisition and renewal of the checkout lock.
   */
  private function allowCheckoutLock(): void {
    $this->checkoutLock->method('acquire')->willReturn(TRUE);
  }

  /**
   * Creates a safe test-mode runtime configuration.
   */
  private function createRuntimeConfiguration(): RuntimeConfiguration {
    $profile = new RuntimeProfile(
      NovaPayMode::Test,
      NULL,
      TransactionMode::Direct,
      '',
      FALSE,
    );
    $credentials = new Credentials(
      NovaPayMode::Test,
      '2',
      'private-key-not-used',
      'public-key-not-used',
    );
    return new RuntimeConfiguration($profile, $credentials);
  }

}
