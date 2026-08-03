<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Phone;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\commerce_novapay\Phone\OrderPhoneResolver;
use Drupal\commerce_novapay\Phone\PhoneNormalizer;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\field\FieldConfigInterface;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests reuse of existing Drupal phone fields in an order context.
 */
#[CoversClass(OrderPhoneResolver::class)]
#[Group('commerce_novapay')]
final class OrderPhoneResolverTest extends TestCase {

  /**
   * Tests automatic discovery of any Drupal telephone field on a profile.
   */
  public function testResolvesTelephoneFieldFromBillingProfile(): void {
    $order = $this->createOrder();
    $profile = $this->createMock(ProfileInterface::class);
    $this->configurePhoneField(
      $profile,
      'field_contact_number',
      'telephone',
      '050 123 45 67',
    );
    $order->method('getBillingProfile')->willReturn($profile);
    $order->method('getCustomer')->willReturn(
      $this->createMock(UserInterface::class),
    );

    self::assertSame(
      '+380501234567',
      (new OrderPhoneResolver(new PhoneNormalizer()))->resolve($order),
    );
  }

  /**
   * Tests an explicitly designated telephone field on the customer account.
   */
  public function testResolvesDesignatedTelephoneFieldFromCustomer(): void {
    $order = $this->createOrder();
    $profile = $this->createMock(ProfileInterface::class);
    $profile->method('getFieldDefinitions')->willReturn([]);
    $customer = $this->createMock(UserInterface::class);
    $this->configurePhoneField(
      $customer,
      'field_payment_contact',
      'telephone',
      '+442079460000',
    );
    $order->method('getBillingProfile')->willReturn($profile);
    $order->method('getCustomer')->willReturn($customer);

    self::assertSame(
      '+442079460000',
      (new OrderPhoneResolver(new PhoneNormalizer()))->resolve($order),
    );
  }

  /**
   * Tests that unrelated and invalid fields do not suppress checkout input.
   */
  public function testReturnsNullWithoutUsablePhoneField(): void {
    $order = $this->createOrder();
    $profile = $this->createMock(ProfileInterface::class);
    $definition = $this->createMock(FieldConfigInterface::class);
    $definition->method('getType')->willReturn('string');
    $profile->method('getFieldDefinitions')->willReturn([
      'field_reference' => $definition,
    ]);
    $customer = $this->createMock(UserInterface::class);
    $customer->method('getFieldDefinitions')->willReturn([]);
    $order->method('getBillingProfile')->willReturn($profile);
    $order->method('getCustomer')->willReturn($customer);

    self::assertNull(
      (new OrderPhoneResolver(new PhoneNormalizer()))->resolve($order),
    );
  }

  /**
   * Tests that an unmarked telephone field is not selected implicitly.
   */
  public function testIgnoresUnmarkedTelephoneField(): void {
    $order = $this->createOrder();
    $profile = $this->createMock(ProfileInterface::class);
    $this->configurePhoneField(
      $profile,
      'field_private_phone',
      'telephone',
      '+380501234567',
      FALSE,
    );
    $customer = $this->createMock(UserInterface::class);
    $customer->method('getFieldDefinitions')->willReturn([]);
    $order->method('getBillingProfile')->willReturn($profile);
    $order->method('getCustomer')->willReturn($customer);

    self::assertNull(
      (new OrderPhoneResolver(new PhoneNormalizer()))->resolve($order),
    );
  }

  /**
   * Creates an order without a canonical NovaPay phone.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface&\PHPUnit\Framework\MockObject\MockObject
   *   Order mock.
   */
  private function createOrder(): OrderInterface&MockObject {
    $order = $this->createMock(OrderInterface::class);
    $order->method('getEntityTypeId')->willReturn('commerce_order');
    $order->method('getFieldDefinitions')->willReturn([]);
    return $order;
  }

  /**
   * Configures one populated field on a content-entity mock.
   */
  private function configurePhoneField(
    ContentEntityInterface&MockObject $entity,
    string $field_name,
    string $field_type,
    string $value,
    bool $payment_phone = TRUE,
  ): void {
    $definition = $this->createMock(FieldConfigInterface::class);
    $definition->method('getType')->willReturn($field_type);
    $definition->method('getThirdPartySetting')
      ->with('commerce_novapay', 'payment_phone', FALSE)
      ->willReturn($payment_phone);
    $entity->method('getFieldDefinitions')->willReturn([
      $field_name => $definition,
    ]);
    $entity->method('hasField')->with($field_name)->willReturn(TRUE);
    $item = $this->createMock(FieldItemInterface::class);
    $item->method('getString')->willReturn($value);
    $list = $this->createMock(FieldItemListInterface::class);
    $list->method('first')->willReturn($item);
    $entity->method('get')->with($field_name)->willReturn($list);
  }

}
