<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Kernel;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_novapay\Api\Dto\Request\CompleteHoldRequest;
use Drupal\commerce_novapay\Api\Dto\Request\VoidRequest;
use Drupal\commerce_novapay\Api\Dto\Response\AcknowledgementResponse;
use Drupal\commerce_novapay\Api\Dto\Response\SessionStatusResponse;
use Drupal\commerce_novapay\Api\NovaPayApiClientInterface;
use Drupal\commerce_novapay\Credential\CredentialResolverInterface;
use Drupal\commerce_novapay\Credential\Credentials;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\ApiFatalException;
use Drupal\commerce_novapay\Exception\ApiProcessingException;
use Drupal\commerce_novapay\Exception\ApiTransportException;
use Drupal\commerce_novapay\Exception\ApiUnexpectedResponseException;
use Drupal\commerce_novapay\Exception\ApiValidationException;
use Drupal\commerce_novapay\Payment\AuthorizationOperationManager;
use Drupal\commerce_novapay\Payment\PaymentStatusCheckManager;
use Drupal\commerce_novapay\Payment\PaymentStatusCheckResult;
use Drupal\commerce_novapay\Payment\RefundOperationManager;
use Drupal\commerce_novapay\Payment\RefundStatusCheckResult;
use Drupal\commerce_novapay\Payment\SessionLockName;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneInspector;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneInspectorInterface;
use Drupal\commerce_novapay\Plugin\Commerce\PaymentType\NovaPayPayment;
use Drupal\commerce_novapay\PluginForm\NovaPayCaptureForm;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;
use Drupal\commerce_novapay\Postback\PostbackEventRepository;
use Drupal\commerce_novapay\Postback\PostbackEventRepositoryInterface;
use Drupal\commerce_novapay\Postback\PostbackOutcome;
use Drupal\commerce_novapay\Postback\PaymentStatusMapper;
use Drupal\commerce_novapay\Postback\PaymentStatusMapperInterface;
use Drupal\commerce_novapay\Phone\OrderPhoneResolverInterface;
use Drupal\commerce_novapay\PluginForm\NovaPayPaymentOffsiteForm;
use Drupal\commerce_novapay\PluginForm\NovaPayPaymentStatusForm;
use Drupal\commerce_novapay\PluginForm\NovaPayRefundForm;
use Drupal\commerce_novapay\PluginForm\NovaPayRefundStatusForm;
use Drupal\commerce_novapay\PluginForm\NovaPayVoidForm;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsAuthorizationsInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_price\Price;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\profile\Entity\ProfileType;
use Drupal\state_machine\WorkflowManagerInterface;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the NovaPay payment type, workflow, fields, and order integration.
 */
#[CoversClass(NovaPayPayment::class)]
#[CoversClass(CustomerProfilePhoneInspector::class)]
#[CoversClass(PaymentStatusMapper::class)]
#[CoversClass(PostbackEventRepository::class)]
#[Group('commerce_novapay')]
final class NovaPayPaymentTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'commerce_checkout',
    'commerce_payment',
    'commerce_novapay',
    'telephone',
  ];

  /**
   * The tested payment gateway.
   */
  private PaymentGateway $paymentGateway;

  /**
   * The parent order.
   */
  private Order $order;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('commerce_payment');
    $this->installSchema(
      'commerce_novapay',
      [
        'commerce_novapay_postback_event',
        'commerce_novapay_refund_ledger',
      ],
    );
    $this->installConfig(['commerce_payment']);

    $this->paymentGateway = PaymentGateway::create([
      'id' => 'novapay_test',
      'label' => 'NovaPay',
      'plugin' => 'novapay',
    ]);
    $this->paymentGateway->save();

    $order_item = OrderItem::create([
      'type' => 'test',
      'title' => 'NovaPay test product',
      'quantity' => '1',
      'unit_price' => new Price('30', 'USD'),
    ]);
    $order_item->save();

    $this->order = Order::create([
      'type' => 'default',
      'store_id' => $this->store->id(),
      'order_items' => [$order_item],
    ]);
    $this->order->save();
    $this->reloadOrder();
  }

  /**
   * Tests plugin binding, workflow states, transitions, and bundle fields.
   */
  public function testPaymentTypeDefinition(): void {
    $payment_type = $this->container
      ->get('plugin.manager.commerce_payment_type')
      ->createInstance('novapay_payment');
    self::assertInstanceOf(NovaPayPayment::class, $payment_type);
    self::assertSame('novapay_payment', $payment_type->getWorkflowId());
    self::assertSame(
      'novapay_payment',
      $this->paymentGateway->getPlugin()->getPaymentType()->getPluginId(),
    );
    self::assertSame(
      NovaPayPaymentOffsiteForm::class,
      $this->paymentGateway->getPlugin()->getFormClass('offsite-payment'),
    );
    self::assertInstanceOf(
      SupportsAuthorizationsInterface::class,
      $this->paymentGateway->getPlugin(),
    );
    self::assertSame(
      NovaPayCaptureForm::class,
      $this->paymentGateway->getPlugin()->getFormClass('capture-payment'),
    );
    self::assertSame(
      NovaPayVoidForm::class,
      $this->paymentGateway->getPlugin()->getFormClass('void-payment'),
    );
    self::assertInstanceOf(
      SupportsRefundsInterface::class,
      $this->paymentGateway->getPlugin(),
    );
    self::assertSame(
      NovaPayRefundForm::class,
      $this->paymentGateway->getPlugin()->getFormClass('refund-payment'),
    );
    self::assertSame(
      NovaPayRefundStatusForm::class,
      $this->paymentGateway->getPlugin()->getFormClass(
        'check-refund-status',
      ),
    );
    self::assertSame(
      NovaPayPaymentStatusForm::class,
      $this->paymentGateway->getPlugin()->getFormClass(
        'check-payment-status',
      ),
    );

    /** @var \Drupal\state_machine\WorkflowManagerInterface $workflow_manager */
    $workflow_manager = $this->container->get('plugin.manager.workflow');
    self::assertInstanceOf(
      WorkflowManagerInterface::class,
      $workflow_manager,
    );
    $workflow = $workflow_manager->createInstance('novapay_payment');
    self::assertSame(
      [
        'pending',
        'authorization',
        'completed',
        'partially_refunded',
        'refunded',
        'authorization_voided',
        'expired',
        'failed',
      ],
      array_keys($workflow->getStates()),
    );
    self::assertNotNull(
      $workflow->findTransition('pending', 'authorization'),
    );
    self::assertNotNull(
      $workflow->findTransition('authorization', 'completed'),
    );
    self::assertNotNull(
      $workflow->findTransition('completed', 'partially_refunded'),
    );
    self::assertNotNull(
      $workflow->findTransition('partially_refunded', 'refunded'),
    );
    self::assertNotNull(
      $workflow->findTransition('authorization', 'authorization_voided'),
    );
    self::assertNotNull($workflow->findTransition('pending', 'expired'));
    self::assertNotNull($workflow->findTransition('pending', 'failed'));
    self::assertNotNull($workflow->findTransition('failed', 'pending'));
    self::assertNotNull(
      $workflow->findTransition('failed', 'authorization'),
    );
    self::assertNotNull($workflow->findTransition('failed', 'completed'));
    self::assertNull($workflow->findTransition('expired', 'completed'));

    $field_definitions = $this->container
      ->get('entity_field.manager')
      ->getFieldDefinitions('commerce_payment', 'novapay_payment');
    self::assertArrayHasKey('novapay_operation_id', $field_definitions);
    self::assertArrayHasKey('novapay_payment_url', $field_definitions);
    self::assertArrayHasKey('novapay_pending_operation', $field_definitions);
    self::assertArrayHasKey('novapay_pending_amount', $field_definitions);
    self::assertArrayHasKey('novapay_pending_refund', $field_definitions);
    self::assertArrayHasKey('expires', $field_definitions);

  }

  /**
   * Tests that missing recipient data limits capture to the full amount.
   */
  public function testCaptureFormLocksAmountWithoutRecipientIdentifier(): void {
    $gateway_uuid = $this->paymentGateway->uuid();
    self::assertIsString($gateway_uuid);
    $credentials = new Credentials(
      NovaPayMode::Test,
      '2',
      'private',
      'public',
    );
    $without_recipient = new RuntimeConfiguration(
      new RuntimeProfile(
        NovaPayMode::Test,
        NULL,
        TransactionMode::Hold,
        '',
        FALSE,
      ),
      $credentials,
    );
    $with_recipient = new RuntimeConfiguration(
      new RuntimeProfile(
        NovaPayMode::Test,
        NULL,
        TransactionMode::Hold,
        '31316718',
        FALSE,
      ),
      $credentials,
    );
    $resolver = $this->createMock(CredentialResolverInterface::class);
    $resolver->expects(self::exactly(2))
      ->method('resolveRuntimeConfiguration')
      ->with($gateway_uuid)
      ->willReturn($without_recipient, $with_recipient);
    $plugin = $this->paymentGateway->getPlugin();
    $resolver_property = new \ReflectionProperty(
      $plugin,
      'credentialResolver',
    );
    $resolver_property->setValue($plugin, $resolver);

    $payment = $this->createAuthorizationPayment('capture-form-session');
    $plugin_form = new NovaPayCaptureForm();
    $plugin_form->setEntity($payment);
    $plugin_form->setPlugin($plugin);
    $form = $plugin_form->buildConfigurationForm([], new FormState());

    self::assertArrayHasKey('recipient_identifier_notice', $form);
    self::assertTrue($form['amount']['#disabled']);
    self::assertSame(
      $payment->getAmount()->toArray(),
      $form['amount']['#default_value'],
    );
    self::assertStringContainsString(
      'Partial capture is unavailable',
      (string) $form['recipient_identifier_notice']['#markup'],
    );

    $form = $plugin_form->buildConfigurationForm([], new FormState());

    self::assertArrayNotHasKey('recipient_identifier_notice', $form);
    self::assertArrayNotHasKey('#disabled', $form['amount']);
  }

  /**
   * Tests unique event claims and the non-sensitive journal schema.
   */
  public function testPostbackEventUniqueness(): void {
    $repository = $this->container->get(
      'commerce_novapay.postback.event_repository',
    );
    self::assertInstanceOf(PostbackEventRepositoryInterface::class, $repository);
    $calls = 0;
    $processor = static function () use (&$calls): PostbackOutcome {
      $calls++;
      return PostbackOutcome::Applied;
    };

    $first = $repository->processOnce(
      hash('sha256', 'raw-body-with-pii'),
      'session-id',
      'novapay_test',
      NovaPayStatus::Paid,
      $processor,
    );
    $duplicate = $repository->processOnce(
      hash('sha256', 'raw-body-with-pii'),
      'session-id',
      'novapay_test',
      NovaPayStatus::Paid,
      $processor,
    );

    self::assertSame(PostbackOutcome::Applied, $first);
    self::assertNull($duplicate);
    self::assertSame(1, $calls);

    $database = $this->container->get('database');
    $row = $database->select('commerce_novapay_postback_event', 'event')
      ->fields('event')
      ->execute()
      ->fetchAssoc();
    self::assertIsArray($row);
    self::assertSame(hash('sha256', 'raw-body-with-pii'), $row['event_key']);
    self::assertSame('session-id', $row['session_id']);
    self::assertSame('novapay_test', $row['gateway_id']);
    self::assertSame('paid', $row['status']);
    self::assertSame('1', (string) $row['signature_valid']);
    self::assertSame('applied', $row['outcome']);
    self::assertArrayNotHasKey('raw_body', $row);
    self::assertArrayNotHasKey('payload', $row);
  }

  /**
   * Tests that an unknown payment remains eligible for a later replay.
   */
  public function testUnknownPostbackIsNotClaimed(): void {
    $repository = $this->container->get(
      'commerce_novapay.postback.event_repository',
    );
    self::assertInstanceOf(
      PostbackEventRepositoryInterface::class,
      $repository,
    );
    $event_key = hash('sha256', 'postback-before-payment-save');
    $database = $this->container->get('database');

    $unknown = $repository->processOnce(
      $event_key,
      'late-session-id',
      'novapay_test',
      NovaPayStatus::Paid,
      static fn (): PostbackOutcome => PostbackOutcome::UnknownPayment,
    );
    self::assertSame(PostbackOutcome::UnknownPayment, $unknown);
    self::assertFalse($database
      ->select('commerce_novapay_postback_event', 'event')
      ->fields('event', ['event_key'])
      ->condition('event_key', $event_key)
      ->execute()
      ->fetchField());

    // Simulate an unknown claim left by an older module version.
    $database->insert('commerce_novapay_postback_event')
      ->fields([
        'event_key' => $event_key,
        'session_id' => 'late-session-id',
        'gateway_id' => 'novapay_test',
        'status' => NovaPayStatus::Paid->value,
        'received' => 1,
        'signature_valid' => 1,
        'outcome' => PostbackOutcome::UnknownPayment->value,
      ])
      ->execute();

    $applied = $repository->processOnce(
      $event_key,
      'late-session-id',
      'novapay_test',
      NovaPayStatus::Paid,
      static fn (): PostbackOutcome => PostbackOutcome::Applied,
    );
    self::assertSame(PostbackOutcome::Applied, $applied);
    self::assertSame('applied', $database
      ->select('commerce_novapay_postback_event', 'event')
      ->fields('event', ['outcome'])
      ->condition('event_key', $event_key)
      ->execute()
      ->fetchField());
  }

  /**
   * Tests field persistence and total_paid refund semantics.
   */
  public function testPaymentOrderIntegration(): void {
    /** @var \Drupal\commerce_payment\PaymentStorageInterface $payment_storage */
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'USD'),
      'state' => 'completed',
      'remote_id' => 'session-id',
      'remote_state' => 'paid',
      'novapay_operation_id' => 'operation-id',
      'novapay_payment_url' => 'https://example.com/pay/session-id',
    ]);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('novapay_payment', $payment->bundle());
    $payment->save();

    $payment = $this->reloadEntity($payment);
    assert($payment instanceof PaymentInterface);
    self::assertSame(
      'operation-id',
      $payment->get('novapay_operation_id')->value,
    );
    self::assertSame(
      'https://example.com/pay/session-id',
      $payment->get('novapay_payment_url')->value,
    );
    self::assertGreaterThan(0, $payment->getCompletedTime());

    $this->order->save();
    $this->reloadOrder();
    self::assertEquals(
      new Price('30', 'USD'),
      $this->order->getTotalPaid(),
    );

    $payment->setRefundedAmount(new Price('10', 'USD'));
    $payment->setState('partially_refunded');
    $payment->save();
    $this->order->save();
    $this->reloadOrder();
    self::assertEquals(
      new Price('20', 'USD'),
      $this->order->getTotalPaid(),
    );

    $payment->setRefundedAmount(new Price('30', 'USD'));
    $payment->setState('refunded');
    $payment->save();
    $this->order->save();
    $this->reloadOrder();
    self::assertEquals(
      new Price('0', 'USD'),
      $this->order->getTotalPaid(),
    );
  }

  /**
   * Tests that a stale created event cannot reactivate a failed payment.
   */
  public function testCreatedStatusDoesNotRecoverFailedPayment(): void {
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'USD'),
      'state' => 'pending',
      'remote_id' => 'failed-session-id',
      'remote_state' => NovaPayStatus::Processing->value,
    ]);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $payment->save();

    $mapper = new PaymentStatusMapper();
    self::assertTrue($mapper->apply($payment, NovaPayStatus::Failed));
    self::assertFalse($mapper->apply($payment, NovaPayStatus::Created));

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('failed', $payment->getState()->getId());
    self::assertSame(NovaPayStatus::Failed->value, $payment->getRemoteState());
  }

  /**
   * Tests partial quantities, postback confirmation, and cumulative limits.
   */
  public function testItemRefundLedgerWaitsForPostback(): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('partial-refund-session');
    $order_item_id = (int) $this->order->getItems()[0]->id();
    $requests = [];
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->method('voidPayment')->willReturnCallback(
      static function (
        RuntimeConfigurationProviderInterface $gateway,
        VoidRequest $request,
      ) use (&$requests): AcknowledgementResponse {
        $requests[] = $request->toArray();
        return new AcknowledgementResponse(200);
      },
    );
    $manager = $this->createRefundOperationManager($api_client);

    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '0.5'],
    );
    self::assertSame([[
      'session_id' => 'partial-refund-session',
      'operations' => [[
        'id' => 'operation-id',
        'refund_amount' => '15',
      ]],
    ]], $requests);

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('completed', $payment->getState()->getId());
    self::assertEquals(new Price('0', 'USD'), $payment->getRefundedAmount());
    self::assertSame('refund', $payment
      ->get('novapay_pending_operation')->getString());
    self::assertSame(0, $this->countRefundLedgerRows($payment));

    $processing_event = NormalizedPostbackEvent::fromValues(
      'partial-refund-session',
      'processing_void',
      [(string) $this->order->id()],
    );
    $manager->confirm(
      $payment,
      $processing_event,
      hash('sha256', 'partial-processing'),
    );
    self::assertSame(0, $this->countRefundLedgerRows($payment));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $event = NormalizedPostbackEvent::fromValues(
      'partial-refund-session',
      'paid',
      [(string) $this->order->id()],
    );
    $manager->confirm($payment, $event, hash('sha256', 'partial-confirmed'));
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('partially_refunded', $payment->getState()->getId());
    self::assertEquals(new Price('15', 'USD'), $payment->getRefundedAmount());
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
    self::assertSame(1, $this->countRefundLedgerRows($payment));

    try {
      $manager->refund(
        $payment,
        $this->createRuntimeProvider(),
        [$order_item_id => '0.500001'],
      );
      self::fail('A refund must not exceed the remaining paid quantity.');
    }
    catch (InvalidRequestException $exception) {
      self::assertStringContainsString('quantity', $exception->getMessage());
    }
    self::assertCount(1, $requests);

    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '0.5'],
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $final_event = NormalizedPostbackEvent::fromValues(
      'partial-refund-session',
      'voided',
      [(string) $this->order->id()],
    );
    $manager->confirm(
      $payment,
      $final_event,
      hash('sha256', 'full-confirmed'),
    );
    (new PaymentStatusMapper())->apply($payment, NovaPayStatus::Voided);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('refunded', $payment->getState()->getId());
    self::assertEquals(new Price('30', 'USD'), $payment->getRefundedAmount());
    self::assertSame(2, $this->countRefundLedgerRows($payment));
    self::assertFalse($manager->canRefund($payment));
  }

  /**
   * Tests that an empty item selection submits and confirms a full refund.
   */
  public function testEmptySelectionRequestsFullRefund(): void {
    $payment = $this->createCompletedPayment('full-refund-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('voidPayment')
      ->with(
        self::isInstanceOf(RuntimeConfigurationProviderInterface::class),
        self::callback(static fn (VoidRequest $request): bool =>
          $request->toArray() === ['session_id' => 'full-refund-session']),
      )
      ->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($api_client);
    $manager->refund($payment, $this->createRuntimeProvider(), []);

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $processing_event = NormalizedPostbackEvent::fromValues(
      'full-refund-session',
      'processing_void',
      [(string) $this->order->id()],
    );
    $manager->confirm(
      $payment,
      $processing_event,
      hash('sha256', 'processing-full'),
    );
    self::assertSame(0, $this->countRefundLedgerRows($payment));

    $final_event = NormalizedPostbackEvent::fromValues(
      'full-refund-session',
      'voided',
      [(string) $this->order->id()],
    );
    $manager->confirm(
      $payment,
      $final_event,
      hash('sha256', 'confirmed-full'),
    );
    (new PaymentStatusMapper())->apply($payment, NovaPayStatus::Voided);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('refunded', $payment->getState()->getId());
    self::assertEquals(new Price('30', 'USD'), $payment->getRefundedAmount());
    self::assertSame(1, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests that selecting every remaining item submits a full refund.
   */
  public function testCompleteItemSelectionRequestsFullRefund(): void {
    $payment = $this->createCompletedPayment('selected-full-refund-session');
    $order_item_id = (int) $this->order->getItems()[0]->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('voidPayment')
      ->with(
        self::isInstanceOf(RuntimeConfigurationProviderInterface::class),
        self::callback(static fn (VoidRequest $request): bool =>
          $request->toArray() === [
            'session_id' => 'selected-full-refund-session',
          ]),
      )
      ->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($api_client);

    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '1'],
    );

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $pending = json_decode(
      $payment->get('novapay_pending_refund')->getString(),
      TRUE,
      32,
      JSON_THROW_ON_ERROR,
    );
    self::assertIsArray($pending);
    self::assertTrue($pending['full']);
    self::assertSame(
      '30',
      $payment->get('novapay_pending_amount')->getString(),
    );
  }

  /**
   * Tests manual reconciliation of a partial refund without a postback.
   */
  public function testChecksPendingPartialRefundStatus(): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('status-partial-session');
    $order_item_id = (int) $this->order->getItems()[0]->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('voidPayment')
      ->willReturn(new AcknowledgementResponse(200));
    $api_client->expects(self::once())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'status-partial-session',
        'status' => 'paid',
        'client_phone' => '+380501234567',
        'operations' => [[
          'transaction_id' => 'operation-id',
          'refunded_amount' => '15.00',
        ]],
      ]));
    $manager = $this->createRefundOperationManager($api_client);

    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '0.5'],
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertTrue($manager->canCheckStatus($payment));
    $operations = $this->paymentGateway->getPlugin()
      ->buildPaymentOperations($payment);
    self::assertArrayHasKey('check_refund_status', $operations);
    self::assertTrue($operations['check_refund_status']['access']);

    $result = $manager->checkStatus(
      $payment,
      $this->createRuntimeProvider(),
    );

    self::assertSame(RefundStatusCheckResult::Confirmed, $result);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('partially_refunded', $payment->getState()->getId());
    self::assertEquals(new Price('15', 'USD'), $payment->getRefundedAmount());
    self::assertFalse($manager->canCheckStatus($payment));
    $operations = $this->paymentGateway->getPlugin()
      ->buildPaymentOperations($payment);
    self::assertFalse($operations['check_refund_status']['access']);
    self::assertSame(1, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests that an inconclusive status never clears a pending refund.
   */
  public function testKeepsRefundPendingWithoutRemoteEvidence(): void {
    $this->setQuantityStep('0.5');
    $payment = $this->createCompletedPayment('status-pending-session');
    $order_item_id = (int) $this->order->getItems()[0]->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->method('voidPayment')
      ->willReturn(new AcknowledgementResponse(200));
    $api_client->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'status-pending-session',
        'status' => 'paid',
        'operations' => [[
          'transaction_id' => 'operation-id',
          'refunded_amount' => '0',
        ]],
      ]));
    $manager = $this->createRefundOperationManager($api_client);
    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '0.5'],
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);

    $result = $manager->checkStatus(
      $payment,
      $this->createRuntimeProvider(),
    );

    self::assertSame(RefundStatusCheckResult::Pending, $result);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertTrue($manager->canCheckStatus($payment));
    self::assertEquals(new Price('0', 'USD'), $payment->getRefundedAmount());
    self::assertSame(0, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests manual reconciliation of a full refund without a postback.
   */
  public function testChecksPendingFullRefundStatus(): void {
    $payment = $this->createCompletedPayment('status-full-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->method('voidPayment')
      ->willReturn(new AcknowledgementResponse(200));
    $api_client->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'status-full-session',
        'status' => 'paid',
        'operations' => [[
          'transaction_id' => 'operation-id',
          'refunded_amount' => '30',
        ]],
      ]));
    $manager = $this->createRefundOperationManager($api_client);
    $manager->refund($payment, $this->createRuntimeProvider());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);

    $result = $manager->checkStatus(
      $payment,
      $this->createRuntimeProvider(),
    );

    self::assertSame(RefundStatusCheckResult::Confirmed, $result);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('refunded', $payment->getState()->getId());
    self::assertEquals(new Price('30', 'USD'), $payment->getRefundedAmount());
    self::assertSame(1, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests atomic rollback when a full-refund workflow save fails.
   */
  public function testFullRefundStatusCheckRollsBackFailedTransition(): void {
    $payment = $this->createCompletedPayment('status-rollback-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->method('voidPayment')
      ->willReturn(new AcknowledgementResponse(200));
    $api_client->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'status-rollback-session',
        'status' => 'voided',
      ]));
    $manager = $this->createRefundOperationManager($api_client);
    $manager->refund($payment, $this->createRuntimeProvider());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);

    $database = $this->container->get('database');
    $schema = rtrim($database->getPrefix(), '.');
    self::assertMatchesRegularExpression('/^[A-Za-z0-9_]+$/D', $schema);
    $trigger = sprintf(
      '"%s"."commerce_novapay_test_refund_failure"',
      $schema,
    );
    $database->query(
      "CREATE TRIGGER $trigger
      BEFORE UPDATE ON commerce_payment
      WHEN NEW.state = 'refunded'
      BEGIN
        SELECT RAISE(FAIL, 'forced refund transition failure');
      END",
      [],
      ['allow_delimiter_in_query' => TRUE],
    );
    try {
      $manager->checkStatus($payment, $this->createRuntimeProvider());
      self::fail('A failed workflow save must abort refund reconciliation.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertStringContainsString(
        'atomically',
        $exception->getMessage(),
      );
    }
    finally {
      $database->query("DROP TRIGGER $trigger");
    }

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('completed', $payment->getState()->getId());
    self::assertEquals(new Price('0', 'USD'), $payment->getRefundedAmount());
    self::assertSame(
      'refund',
      $payment->get('novapay_pending_operation')->getString(),
    );
    self::assertFalse($payment->get('novapay_pending_refund')->isEmpty());
    self::assertSame(0, $this->countRefundLedgerRows($payment));
  }

  /**
   * Tests refund quantities against the order item widget configuration.
   */
  public function testRefundQuantityUsesOrderItemTypeStep(): void {
    $payment = $this->createCompletedPayment('quantity-step-session');
    $order_item_id = (int) $this->order->getItems()[0]->id();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('voidPayment')
      ->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createRefundOperationManager($api_client);

    $items = $manager->getRefundableItems($payment);
    self::assertCount(1, $items);
    self::assertSame('1', $items[0]->getQuantityStep());
    $plugin = $this->paymentGateway->getPlugin();
    $plugin_form = new NovaPayRefundForm();
    $plugin_form->setEntity($payment);
    $plugin_form->setPlugin($plugin);
    $form = $plugin_form->buildConfigurationForm([], new FormState());
    self::assertSame(
      '1',
      $form['items'][$order_item_id]['quantity']['#step'],
    );

    try {
      $manager->refund(
        $payment,
        $this->createRuntimeProvider(),
        [$order_item_id => '0.5'],
      );
      self::fail('A fractional refund must be rejected for a step of 1.');
    }
    catch (InvalidRequestException $exception) {
      self::assertStringContainsString('quantity step', $exception->getMessage());
    }

    $this->setQuantityStep('0.5');
    $items = $manager->getRefundableItems($payment);
    self::assertSame('0.5', $items[0]->getQuantityStep());
    $form = $plugin_form->buildConfigurationForm([], new FormState());
    self::assertSame(
      '0.5',
      $form['items'][$order_item_id]['quantity']['#step'],
    );
    $manager->refund(
      $payment,
      $this->createRuntimeProvider(),
      [$order_item_id => '0.5'],
    );
  }

  /**
   * Tests that an untrusted browser return cannot create or complete payment.
   */
  public function testBrowserReturnDoesNotChangePaymentState(): void {
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    $query = $payment_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('order_id', $this->order->id());
    self::assertSame(0, (int) $query->count()->execute());

    $plugin = $this->paymentGateway->getPlugin();
    self::assertInstanceOf(OffsitePaymentGatewayInterface::class, $plugin);
    $plugin->onReturn(
      $this->order,
      new Request(['status' => 'paid', 'session_id' => 'untrusted']),
    );

    self::assertSame(0, (int) $query->count()->execute());
    self::assertEquals(new Price('0', 'USD'), $this->order->getTotalPaid());
  }

  /**
   * Tests real workflow persistence for verified hold postback statuses.
   */
  public function testPostbackStatusMappingPersistsWorkflowTransitions(): void {
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'USD'),
      'state' => 'pending',
      'remote_id' => 'postback-session',
      'remote_state' => 'created',
    ]);
    $payment->save();
    $mapper = $this->container->get(
      'commerce_novapay.postback.status_mapper',
    );
    self::assertInstanceOf(PaymentStatusMapperInterface::class, $mapper);

    $mapper->apply($payment, NovaPayStatus::Holded);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertSame('holded', $payment->getRemoteState());

    $mapper->apply($payment, NovaPayStatus::HoldConfirmed);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('completed', $payment->getState()->getId());
    self::assertSame('hold_confirmed', $payment->getRemoteState());
  }

  /**
   * Tests partial capture locking and postback-only amount/state changes.
   */
  public function testPartialCaptureAwaitsPostback(): void {
    $payment = $this->createAuthorizationPayment('capture-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('completeHold')
      ->with(
        self::isInstanceOf(RuntimeConfigurationProviderInterface::class),
        self::callback(static function (CompleteHoldRequest $request): bool {
          return $request->toArray() === [
            'session_id' => 'capture-session',
            'amount' => '10',
            'operations' => [[
              'id' => 'operation-id',
              'amount' => '10',
              'recipient_identifier' => '31316718',
            ]],
          ];
        }),
      )
      ->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createAuthorizationOperationManager($api_client);

    self::assertTrue($manager->canCapture($payment));
    self::assertTrue($manager->canVoid($payment));
    $manager->capture($payment, $this->createRuntimeProvider(), new Price('10', 'USD'));

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertEquals(new Price('30', 'USD'), $payment->getAmount());
    self::assertSame(
      'capture',
      $payment->get('novapay_pending_operation')->getString(),
    );
    self::assertSame(
      '10',
      $payment->get('novapay_pending_amount')->getString(),
    );
    self::assertFalse($manager->canCapture($payment));
    self::assertFalse($manager->canVoid($payment));

    $this->expectException(InvalidRequestException::class);
    try {
      $manager->capture($payment, $this->createRuntimeProvider());
    }
    finally {
      $mapper = $this->container->get(
        'commerce_novapay.postback.status_mapper',
      );
      self::assertInstanceOf(PaymentStatusMapperInterface::class, $mapper);
      $mapper->apply($payment, NovaPayStatus::HoldConfirmed);
      $payment = $this->reloadEntity($payment);
      self::assertInstanceOf(PaymentInterface::class, $payment);
      self::assertSame('completed', $payment->getState()->getId());
      self::assertEquals(new Price('10', 'USD'), $payment->getAmount());
      self::assertTrue(
        $payment->get('novapay_pending_operation')->isEmpty(),
      );
      self::assertTrue($payment->get('novapay_pending_amount')->isEmpty());
    }
  }

  /**
   * Tests that void is always full and remains pending until postback.
   */
  public function testAuthorizationVoidAwaitsPostback(): void {
    $payment = $this->createAuthorizationPayment('void-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('voidPayment')
      ->with(
        self::isInstanceOf(RuntimeConfigurationProviderInterface::class),
        self::callback(static fn (VoidRequest $request): bool =>
          $request->toArray() === ['session_id' => 'void-session']),
      )
      ->willReturn(new AcknowledgementResponse(200));
    $manager = $this->createAuthorizationOperationManager($api_client);

    $manager->void($payment, $this->createRuntimeProvider());
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertSame(
      'void',
      $payment->get('novapay_pending_operation')->getString(),
    );
    self::assertTrue($payment->get('novapay_pending_amount')->isEmpty());

    $mapper = $this->container->get(
      'commerce_novapay.postback.status_mapper',
    );
    self::assertInstanceOf(PaymentStatusMapperInterface::class, $mapper);
    $mapper->apply($payment, NovaPayStatus::Voided);
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame(
      'authorization_voided',
      $payment->getState()->getId(),
    );
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
  }

  /**
   * Tests that a full capture omits partial-capture API properties.
   */
  public function testFullCapturePayload(): void {
    $payment = $this->createAuthorizationPayment('full-capture-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('completeHold')
      ->with(
        self::isInstanceOf(RuntimeConfigurationProviderInterface::class),
        self::callback(static fn (CompleteHoldRequest $request): bool =>
          $request->toArray() === [
            'session_id' => 'full-capture-session',
          ]),
      )
      ->willReturn(new AcknowledgementResponse(200));

    $lock = $this->createMock(LockBackendInterface::class);
    $lock_name = SessionLockName::fromSessionId('full-capture-session');
    $lock->expects(self::once())->method('acquire')
      ->with($lock_name, 30.0)
      ->willReturn(TRUE);
    $lock->expects(self::once())->method('release')->with($lock_name);
    $manager = $this->createAuthorizationOperationManager($api_client, $lock);
    $manager->capture($payment, $this->createRuntimeProvider());

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertSame(
      '30',
      $payment->get('novapay_pending_amount')->getString(),
    );
  }

  /**
   * Tests that an uncertain transport result remains blocked for postback.
   */
  public function testTransportFailureRetainsPendingMarker(): void {
    $payment = $this->createAuthorizationPayment('uncertain-void-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('voidPayment')
      ->willThrowException(ApiTransportException::requestFailed());
    $manager = $this->createAuthorizationOperationManager($api_client);

    try {
      $manager->void($payment, $this->createRuntimeProvider());
      self::fail('An uncertain transport result must fail the form request.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertStringContainsString('uncertain', $exception->getMessage());
    }

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertSame(
      'void',
      $payment->get('novapay_pending_operation')->getString(),
    );
    self::assertFalse($manager->canVoid($payment));
  }

  /**
   * Tests that fatal responses retain the in-flight financial marker.
   */
  public function testFatalFailureRetainsPendingMarker(): void {
    $this->assertUncertainVoidFailureRetainsMarker(
      new ApiFatalException(500),
      'fatal-void-session',
    );
  }

  /**
   * Tests that processing outcomes retain the in-flight financial marker.
   */
  public function testProcessingFailureRetainsPendingMarker(): void {
    $this->assertUncertainVoidFailureRetainsMarker(
      new ApiProcessingException(400, 'TimeoutError'),
      'processing-void-session',
    );
  }

  /**
   * Tests that malformed server failures retain the financial marker.
   */
  public function testMalformedServerFailureRetainsPendingMarker(): void {
    $this->assertUncertainVoidFailureRetainsMarker(
      ApiUnexpectedResponseException::invalidError(502),
      'malformed-server-void-session',
    );
  }

  /**
   * Tests that a definitive validation rejection permits a corrected retry.
   */
  public function testValidationFailureClearsPendingMarker(): void {
    $payment = $this->createAuthorizationPayment('validation-void-session');
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('voidPayment')
      ->willThrowException(new ApiValidationException(400, []));
    $manager = $this->createAuthorizationOperationManager($api_client);

    try {
      $manager->void($payment, $this->createRuntimeProvider());
      self::fail('A validation rejection must fail the form request.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertStringContainsString('rejected', $exception->getMessage());
    }

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertTrue($payment->get('novapay_pending_operation')->isEmpty());
    self::assertTrue($manager->canVoid($payment));
  }

  /**
   * Tests that an expired authorization is unavailable and never submitted.
   */
  public function testExpiredAuthorizationIsUnavailable(): void {
    $payment = $this->createAuthorizationPayment('expired-hold-session');
    $payment->setExpiresTime(1);
    $payment->save();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::never())->method('completeHold');
    $manager = $this->createAuthorizationOperationManager($api_client);

    self::assertFalse($manager->canCapture($payment));
    self::assertFalse($manager->canVoid($payment));
    $this->expectException(InvalidRequestException::class);
    $manager->capture($payment, $this->createRuntimeProvider());
  }

  /**
   * Tests capture amount bounds before a financial API request is sent.
   */
  public function testCaptureAmountBounds(): void {
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::never())->method('completeHold');
    $manager = $this->createAuthorizationOperationManager($api_client);
    $payment = $this->createAuthorizationPayment('invalid-amount-session');

    $this->expectException(InvalidRequestException::class);
    $manager->capture(
      $payment,
      $this->createRuntimeProvider(),
      new Price('30.01', 'USD'),
    );
  }

  /**
   * Tests an explicitly designated customer telephone field as a source.
   */
  public function testDesignatedCustomerPhoneField(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_payment_phone',
      'entity_type' => 'user',
      'type' => 'telephone',
    ])->save();
    $field = FieldConfig::create([
      'field_name' => 'field_payment_phone',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Payment phone',
    ]);
    $field->setThirdPartySetting(
      'commerce_novapay',
      'payment_phone',
      TRUE,
    );
    $field->save();

    $customer = User::create([
      'name' => 'novapay-phone-customer',
      'status' => TRUE,
      'field_payment_phone' => '050 123 45 67',
    ]);
    $customer->save();
    $this->order->setCustomer($customer);

    $resolver = $this->container->get(
      'commerce_novapay.order_phone_resolver',
    );
    self::assertInstanceOf(OrderPhoneResolverInterface::class, $resolver);
    self::assertSame('+380501234567', $resolver->resolve($this->order));
  }

  /**
   * Tests the Field UI payment-phone checkbox and persisted setting.
   */
  public function testTelephoneFieldConfigurationForm(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_checkout_phone',
      'entity_type' => 'user',
      'type' => 'telephone',
    ])->save();
    $field = FieldConfig::create([
      'field_name' => 'field_checkout_phone',
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Checkout phone',
    ]);
    $field->save();
    $form_object = $this->createMock(EntityFormInterface::class);
    $form_object->method('getEntity')->willReturn($field);
    $form_state = (new FormState())->setFormObject($form_object);
    $form = [
      'actions' => [
        'submit' => ['#submit' => []],
      ],
    ];

    commerce_novapay_form_field_config_edit_form_alter($form, $form_state);

    self::assertArrayHasKey('commerce_novapay_payment_phone', $form);
    self::assertFalse(
      $form['commerce_novapay_payment_phone']['#default_value'],
    );
    self::assertContains(
      'commerce_novapay_field_config_edit_form_submit',
      $form['actions']['submit']['#submit'],
    );

    $form_state->setValue('commerce_novapay_payment_phone', TRUE);
    commerce_novapay_field_config_edit_form_submit($form, $form_state);
    $field = FieldConfig::load($field->id());
    self::assertInstanceOf(FieldConfig::class, $field);
    self::assertTrue((bool) $field->getThirdPartySetting(
      'commerce_novapay',
      'payment_phone',
      FALSE,
    ));
  }

  /**
   * Tests readiness states for Commerce customer profile telephone fields.
   */
  public function testCustomerProfilePhoneReadiness(): void {
    $profile_type = ProfileType::load('customer');
    self::assertInstanceOf(ProfileType::class, $profile_type);
    self::assertTrue((bool) $profile_type->getThirdPartySetting(
      'commerce_order',
      'customer_profile_type',
      FALSE,
    ));
    $inspector = $this->container->get(
      'commerce_novapay.customer_profile_phone_inspector',
    );
    self::assertInstanceOf(
      CustomerProfilePhoneInspectorInterface::class,
      $inspector,
    );

    $readiness = $inspector->inspect();
    self::assertFalse($readiness->isReady());
    self::assertSame(['Customer'], $readiness->getMissingTelephone());
    self::assertSame([], $readiness->getUnmarkedTelephone());

    FieldStorageConfig::create([
      'field_name' => 'field_profile_phone',
      'entity_type' => 'profile',
      'type' => 'telephone',
    ])->save();
    $field = FieldConfig::create([
      'field_name' => 'field_profile_phone',
      'entity_type' => 'profile',
      'bundle' => 'customer',
      'label' => 'Customer phone',
    ]);
    $field->save();

    $readiness = $inspector->inspect();
    self::assertFalse($readiness->isReady());
    self::assertSame([], $readiness->getMissingTelephone());
    self::assertSame(['Customer'], $readiness->getUnmarkedTelephone());

    $field->setThirdPartySetting(
      'commerce_novapay',
      'payment_phone',
      TRUE,
    );
    $field->save();
    $readiness = $inspector->inspect();
    self::assertTrue($readiness->isReady());
    self::assertSame([], $readiness->getMissingTelephone());
    self::assertSame([], $readiness->getUnmarkedTelephone());
  }

  /**
   * Reloads the typed parent order.
   */
  private function reloadOrder(): void {
    $order = $this->reloadEntity($this->order);
    assert($order instanceof Order);
    $this->order = $order;
  }

  /**
   * Tests direct and hold payment reconciliation without a postback.
   */
  public function testReconcilesPaymentStatusWithoutFinancialRequest(): void {
    $direct = $this->createPendingPayment('direct-status-session');
    $direct_client = $this->createMock(NovaPayApiClientInterface::class);
    $direct_client->expects(self::once())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'direct-status-session',
        'status' => 'paid',
      ]));
    $direct_manager = $this->createPaymentStatusCheckManager($direct_client);

    self::assertSame(
      PaymentStatusCheckResult::Reconciled,
      $direct_manager->checkStatus($direct, $this->createRuntimeProvider()),
    );
    $direct = $this->reloadEntity($direct);
    self::assertInstanceOf(PaymentInterface::class, $direct);
    self::assertSame('completed', $direct->getState()->getId());
    self::assertSame('paid', $direct->getRemoteState());

    $hold = $this->createAuthorizationPayment('hold-status-session');
    $hold->set('novapay_pending_operation', 'capture');
    $hold->set('novapay_pending_amount', '30');
    $hold->save();
    $hold_client = $this->createMock(NovaPayApiClientInterface::class);
    $hold_client->expects(self::once())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'hold-status-session',
        'status' => 'hold_confirmed',
      ]));
    $hold_manager = $this->createPaymentStatusCheckManager($hold_client);

    self::assertSame(
      PaymentStatusCheckResult::Reconciled,
      $hold_manager->checkStatus($hold, $this->createRuntimeProvider()),
    );
    $hold = $this->reloadEntity($hold);
    self::assertInstanceOf(PaymentInterface::class, $hold);
    self::assertSame('completed', $hold->getState()->getId());
    self::assertTrue($hold->get('novapay_pending_operation')->isEmpty());
  }

  /**
   * Tests that inconclusive and mismatching results cannot mutate a payment.
   */
  public function testPaymentStatusCheckPreservesPendingState(): void {
    $payment = $this->createAuthorizationPayment('pending-status-session');
    $payment->set('novapay_pending_operation', 'void');
    $payment->save();
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())->method('getStatus')
      ->willReturn(SessionStatusResponse::fromArray([
        'id' => 'pending-status-session',
        'status' => 'processing_void',
      ]));
    $manager = $this->createPaymentStatusCheckManager($api_client);

    self::assertSame(
      PaymentStatusCheckResult::Reconciled,
      $manager->checkStatus($payment, $this->createRuntimeProvider()),
    );
    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame('authorization', $payment->getState()->getId());
    self::assertSame('void', $payment->get('novapay_pending_operation')->getString());
    self::assertSame('processing_void', $payment->getRemoteState());
  }

  /**
   * Creates a persisted NovaPay authorization for operation tests.
   */
  private function createAuthorizationPayment(
    string $session_id,
  ): PaymentInterface {
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'USD'),
      'state' => 'authorization',
      'remote_id' => $session_id,
      'remote_state' => 'holded',
      'novapay_operation_id' => 'operation-id',
    ]);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $payment->save();
    return $payment;
  }

  /**
   * Creates a persisted pending NovaPay payment for direct status checks.
   */
  private function createPendingPayment(string $session_id): PaymentInterface {
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'USD'),
      'state' => 'pending',
      'remote_id' => $session_id,
      'remote_state' => 'created',
    ]);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $payment->save();
    return $payment;
  }

  /**
   * Creates a persisted completed payment for refund operation tests.
   */
  private function createCompletedPayment(string $session_id): PaymentInterface {
    $payment_storage = $this->container
      ->get('entity_type.manager')
      ->getStorage('commerce_payment');
    $payment = $payment_storage->create([
      'payment_gateway' => $this->paymentGateway,
      'order_id' => $this->order->id(),
      'amount' => new Price('30', 'USD'),
      'state' => 'completed',
      'remote_id' => $session_id,
      'remote_state' => 'paid',
      'novapay_operation_id' => 'operation-id',
    ]);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    $payment->save();
    return $payment;
  }

  /**
   * Configures the quantity widget for the tested order item type.
   */
  private function setQuantityStep(string $step): void {
    $bundle = $this->order->getItems()[0]->bundle();
    $display_id = 'commerce_order_item.' . $bundle . '.default';
    $display = EntityFormDisplay::load($display_id)
      ?? EntityFormDisplay::create([
        'id' => $display_id,
        'targetEntityType' => 'commerce_order_item',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    self::assertInstanceOf(EntityFormDisplay::class, $display);
    $component = $display->getComponent('quantity') ?? [
      'type' => 'commerce_quantity',
      'weight' => 1,
      'region' => 'content',
      'settings' => ['placeholder' => ''],
      'third_party_settings' => [],
    ];
    $component['settings']['step'] = $step;
    $display->setComponent('quantity', $component)->save();
  }

  /**
   * Creates the refund manager with a mocked one-shot API boundary.
   */
  private function createRefundOperationManager(
    NovaPayApiClientInterface $api_client,
  ): RefundOperationManager {
    return new RefundOperationManager(
      $this->container->get('entity_type.manager'),
      $api_client,
      $this->container->get('lock'),
      $this->container->get('commerce_novapay.refund_ledger'),
      $this->container->get('database'),
    );
  }

  /**
   * Counts confirmed ledger rows for one payment.
   */
  private function countRefundLedgerRows(PaymentInterface $payment): int {
    return (int) $this->container->get('database')
      ->select('commerce_novapay_refund_ledger', 'refund')
      ->condition('payment_id', $payment->id())
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Creates the tested manager with a mocked financial boundary.
   */
  private function createAuthorizationOperationManager(
    NovaPayApiClientInterface $api_client,
    ?LockBackendInterface $lock = NULL,
  ): AuthorizationOperationManager {
    return new AuthorizationOperationManager(
      $this->container->get('entity_type.manager'),
      $api_client,
      $lock ?? $this->container->get('lock'),
    );
  }

  /**
   * Creates a status manager with a mocked read-only API boundary.
   */
  private function createPaymentStatusCheckManager(
    NovaPayApiClientInterface $api_client,
  ): PaymentStatusCheckManager {
    return new PaymentStatusCheckManager(
      $this->container->get('entity_type.manager'),
      $api_client,
      $this->container->get('commerce_novapay.postback.status_mapper'),
      $this->container->get('lock'),
    );
  }

  /**
   * Asserts that an ambiguous void result remains blocked for postback.
   */
  private function assertUncertainVoidFailureRetainsMarker(
    \Throwable $failure,
    string $session_id,
  ): void {
    $payment = $this->createAuthorizationPayment($session_id);
    $api_client = $this->createMock(NovaPayApiClientInterface::class);
    $api_client->expects(self::once())
      ->method('voidPayment')
      ->willThrowException($failure);
    $manager = $this->createAuthorizationOperationManager($api_client);

    try {
      $manager->void($payment, $this->createRuntimeProvider());
      self::fail('An uncertain remote result must fail the form request.');
    }
    catch (PaymentGatewayException $exception) {
      self::assertStringContainsString('uncertain', $exception->getMessage());
    }

    $payment = $this->reloadEntity($payment);
    self::assertInstanceOf(PaymentInterface::class, $payment);
    self::assertSame(
      'void',
      $payment->get('novapay_pending_operation')->getString(),
    );
    self::assertFalse($manager->canVoid($payment));
  }

  /**
   * Creates a non-sensitive runtime provider for operation payloads.
   */
  private function createRuntimeProvider(): RuntimeConfigurationProviderInterface {
    $configuration = new RuntimeConfiguration(
      new RuntimeProfile(
        NovaPayMode::Test,
        NULL,
        TransactionMode::Hold,
        '31316718',
        FALSE,
      ),
      new Credentials(NovaPayMode::Test, '2', 'private', 'public'),
    );
    $provider = $this->createMock(
      RuntimeConfigurationProviderInterface::class,
    );
    $provider->method('getRuntimeConfiguration')->willReturn($configuration);
    return $provider;
  }

}
