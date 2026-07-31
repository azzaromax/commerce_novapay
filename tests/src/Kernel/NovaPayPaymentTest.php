<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Kernel;

use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_novapay\Plugin\Commerce\PaymentType\NovaPayPayment;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\state_machine\WorkflowManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the NovaPay payment type, workflow, fields, and order integration.
 */
#[CoversClass(NovaPayPayment::class)]
#[Group('commerce_novapay')]
final class NovaPayPaymentTest extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = [
    'commerce_payment',
    'commerce_novapay',
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

    $field_definitions = $this->container
      ->get('entity_field.manager')
      ->getFieldDefinitions('commerce_payment', 'novapay_payment');
    self::assertArrayHasKey('novapay_operation_id', $field_definitions);
    self::assertArrayHasKey('novapay_payment_url', $field_definitions);
    self::assertArrayHasKey('expires', $field_definitions);
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
   * Reloads the typed parent order.
   */
  private function reloadOrder(): void {
    $order = $this->reloadEntity($this->order);
    assert($order instanceof Order);
    $this->order = $order;
  }

}
