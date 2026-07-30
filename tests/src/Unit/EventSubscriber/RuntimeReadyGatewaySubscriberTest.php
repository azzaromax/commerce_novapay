<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\EventSubscriber;

use Drupal\commerce_novapay\Credential\CredentialResolverInterface;
use Drupal\commerce_novapay\Credential\Credentials;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\EventSubscriber\RuntimeReadyGatewaySubscriber;
use Drupal\commerce_novapay\Exception\InvalidCredentialsException;
use Drupal\commerce_novapay\Runtime\RuntimeConfiguration;
use Drupal\commerce_novapay\Runtime\RuntimeProfile;
use Drupal\commerce_novapay\Runtime\TransactionMode;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Event\FilterPaymentGatewaysEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests filtering of locally unconfigured NovaPay gateways.
 */
#[CoversClass(RuntimeReadyGatewaySubscriber::class)]
#[Group('commerce_novapay')]
final class RuntimeReadyGatewaySubscriberTest extends TestCase {

  private const GATEWAY_UUID = 'c576714b-99d4-4d4f-a707-9fba3e5e2406';

  /**
   * Tests that an absent local profile removes NovaPay only.
   */
  public function testFiltersUnconfiguredNovaPayGateway(): void {
    $resolver = $this->createMock(CredentialResolverInterface::class);
    $resolver->method('resolveRuntimeConfiguration')
      ->willThrowException(
        InvalidCredentialsException::liveProfileUnavailable(),
      );
    $subscriber = new RuntimeReadyGatewaySubscriber($resolver);

    $event = $this->createEvent([
      'novapay' => $this->createGateway('novapay'),
      'manual' => $this->createGateway('manual'),
    ]);
    $subscriber->filterPaymentGateways($event);

    self::assertSame(
      ['manual'],
      array_keys($event->getPaymentGateways()),
    );
  }

  /**
   * Tests that a resolvable local test profile remains available.
   */
  public function testKeepsConfiguredNovaPayGateway(): void {
    $profile = new RuntimeProfile(
      NovaPayMode::Test,
      NULL,
      TransactionMode::Direct,
      '',
      FALSE,
    );
    $resolver = $this->createMock(CredentialResolverInterface::class);
    $resolver->expects(self::once())
      ->method('resolveRuntimeConfiguration')
      ->with(self::GATEWAY_UUID)
      ->willReturn(new RuntimeConfiguration(
        $profile,
        new Credentials(
          NovaPayMode::Test,
          '2',
          'private',
          'public',
        ),
      ));

    $subscriber = new RuntimeReadyGatewaySubscriber($resolver);
    $event = $this->createEvent([
      'novapay' => $this->createGateway('novapay'),
    ]);
    $subscriber->filterPaymentGateways($event);

    self::assertSame(
      ['novapay'],
      array_keys($event->getPaymentGateways()),
    );
  }

  /**
   * Creates a filter event with a mocked order.
   *
   * @param array<string, \Drupal\commerce_payment\Entity\PaymentGatewayInterface> $gateways
   *   Payment gateways indexed by entity ID.
   */
  private function createEvent(array $gateways): FilterPaymentGatewaysEvent {
    return new FilterPaymentGatewaysEvent(
      $gateways,
      $this->createMock(OrderInterface::class),
    );
  }

  /**
   * Creates a payment gateway mock.
   */
  private function createGateway(
    string $plugin_id,
  ): PaymentGatewayInterface {
    $gateway = $this->createMock(PaymentGatewayInterface::class);
    $gateway->method('getPluginId')->willReturn($plugin_id);
    $gateway->method('uuid')->willReturn(self::GATEWAY_UUID);

    return $gateway;
  }

}
