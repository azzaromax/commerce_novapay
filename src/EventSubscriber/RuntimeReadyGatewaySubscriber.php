<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\EventSubscriber;

use Drupal\commerce_novapay\Credential\CredentialResolverInterface;
use Drupal\commerce_payment\Event\FilterPaymentGatewaysEvent;
use Drupal\commerce_payment\Event\PaymentEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Removes NovaPay from checkout when local credentials are unavailable.
 */
final class RuntimeReadyGatewaySubscriber implements EventSubscriberInterface {

  /**
   * Constructs a NovaPay gateway readiness subscriber.
   */
  public function __construct(
    private readonly CredentialResolverInterface $credential_resolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      PaymentEvents::FILTER_PAYMENT_GATEWAYS => 'filterPaymentGateways',
    ];
  }

  /**
   * Removes locally unconfigured NovaPay gateways.
   */
  public function filterPaymentGateways(
    FilterPaymentGatewaysEvent $event,
  ): void {
    $gateways = $event->getPaymentGateways();
    foreach ($gateways as $id => $gateway) {
      if ($gateway->getPluginId() !== 'novapay') {
        continue;
      }

      try {
        $uuid = $gateway->uuid();
        if (!is_string($uuid) || $uuid === '') {
          unset($gateways[$id]);
          continue;
        }

        $this->credential_resolver->resolveRuntimeConfiguration($uuid);
      }
      catch (\Throwable) {
        unset($gateways[$id]);
      }
    }

    $event->setPaymentGateways($gateways);
  }

}
