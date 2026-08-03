<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Kernel;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormState;
use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneInspector;
use Drupal\commerce_novapay\Phone\CustomerProfilePhoneInspectorInterface;
use Drupal\commerce_novapay\Plugin\Commerce\PaymentType\NovaPayPayment;
use Drupal\commerce_novapay\Phone\OrderPhoneResolverInterface;
use Drupal\commerce_novapay\PluginForm\NovaPayPaymentOffsiteForm;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayInterface;
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

}
