<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;
use Drupal\commerce_novapay\Postback\Parser\PostbackParserInterface;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_novapay\Signature\SandboxLegacyVerifierInterface;
use Drupal\commerce_novapay\Signature\VerifierInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\OrderStorageInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PaymentStorageInterface;

/**
 * Implements the signature-first NovaPay postback processing boundary.
 */
final class PostbackProcessor implements PostbackProcessorInterface {

  public function __construct(
    private readonly VerifierInterface $verifier,
    private readonly SandboxLegacyVerifierInterface $sandbox_legacy_verifier,
    private readonly PostbackParserInterface $parser,
    private readonly EntityTypeManagerInterface $entity_type_manager,
    private readonly PaymentStatusMapperInterface $status_mapper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function process(
    PaymentGatewayInterface $gateway,
    RuntimeConfigurationProviderInterface $gateway_plugin,
    #[\SensitiveParameter]
    string $raw_body,
    #[\SensitiveParameter]
    string $signature,
  ): PostbackResult {
    $credentials = $gateway_plugin->getRuntimeConfiguration()->getCredentials();
    $public_key = $credentials->getPublicKeyPem();
    $verified = $this->verifier->verify($raw_body, $signature, $public_key);
    if (
      !$verified
      && $credentials->getMode() === NovaPayMode::Test
    ) {
      $verified = $this->sandbox_legacy_verifier->verify(
        $raw_body,
        $signature,
        $public_key,
      );
    }
    if (!$verified) {
      return PostbackResult::invalidSignature();
    }

    try {
      $parsed = $this->parser->parse($raw_body);
    }
    catch (InvalidPostbackException) {
      return PostbackResult::invalidPayload();
    }
    $event = $parsed->getEvent();
    $payment = $this->findPayment($gateway, $event);
    if ($payment === NULL) {
      return PostbackResult::forEvent(
        PostbackOutcome::UnknownPayment,
        $parsed->getVersion(),
        $event->getStatus(),
      );
    }

    $this->status_mapper->apply($payment, $event->getStatus());
    return PostbackResult::forEvent(
      PostbackOutcome::Applied,
      $parsed->getVersion(),
      $event->getStatus(),
    );
  }

  /**
   * Finds one unambiguous payment by session, then safe external-ID fallback.
   */
  private function findPayment(
    PaymentGatewayInterface $gateway,
    NormalizedPostbackEvent $event,
  ): ?PaymentInterface {
    $payment_storage = $this->entity_type_manager
      ->getStorage('commerce_payment');
    if (!$payment_storage instanceof PaymentStorageInterface) {
      throw new \RuntimeException('Commerce payment storage is unavailable.');
    }

    $payment = $this->getUniquePayment($payment_storage->loadByProperties([
      'payment_gateway' => $gateway->id(),
      'remote_id' => $event->getSessionId(),
    ]));
    if ($payment !== NULL) {
      return $payment;
    }

    return $this->findPaymentByExternalIds(
      $payment_storage,
      $gateway,
      $event,
    );
  }

  /**
   * Resolves a unique same-gateway payment from external order references.
   */
  private function findPaymentByExternalIds(
    PaymentStorageInterface $payment_storage,
    PaymentGatewayInterface $gateway,
    NormalizedPostbackEvent $event,
  ): ?PaymentInterface {
    $order_storage = $this->entity_type_manager->getStorage('commerce_order');
    if (!$order_storage instanceof OrderStorageInterface) {
      throw new \RuntimeException('Commerce order storage is unavailable.');
    }

    $candidates = [];
    foreach ($event->getExternalIds() as $external_id) {
      $orders = $order_storage->loadByProperties([
        'order_number' => $external_id,
      ]);
      if (preg_match('/^[1-9][0-9]*$/D', $external_id) === 1) {
        $order = $order_storage->load($external_id);
        if ($order instanceof OrderInterface) {
          $orders[$order->id()] = $order;
        }
      }

      foreach ($orders as $order) {
        if (!$order instanceof OrderInterface) {
          continue;
        }
        $payments = $payment_storage->loadByProperties([
          'payment_gateway' => $gateway->id(),
          'order_id' => $order->id(),
        ]);
        foreach ($payments as $payment) {
          if (!$payment instanceof PaymentInterface) {
            continue;
          }
          $remote_id = $payment->getRemoteId();
          if (
            is_string($remote_id)
            && $remote_id !== ''
            && $remote_id !== $event->getSessionId()
          ) {
            continue;
          }
          $candidates[(string) $payment->id()] = $payment;
        }
      }
    }

    return $this->getUniquePayment($candidates);
  }

  /**
   * Returns a payment only when the candidate set is unambiguous.
   *
   * @param array<array-key, mixed> $candidates
   *   Loaded payment candidates.
   */
  private function getUniquePayment(array $candidates): ?PaymentInterface {
    $payments = array_values(array_filter(
      $candidates,
      static fn (mixed $candidate): bool =>
        $candidate instanceof PaymentInterface,
    ));

    return count($payments) === 1 ? $payments[0] : NULL;
  }

}
