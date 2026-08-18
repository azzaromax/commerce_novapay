<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Exception\PostbackProcessingException;
use Drupal\commerce_novapay\Payment\PendingOperation;
use Drupal\commerce_novapay\Payment\SessionLockName;
use Drupal\commerce_novapay\Payment\RefundOperationManagerInterface;
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
    private readonly PostbackEventRepositoryInterface $event_repository,
    private readonly LockBackendInterface $lock,
    private readonly RefundOperationManagerInterface $refund_manager,
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
    $detailed_logging = $gateway_plugin->getRuntimeConfiguration()
      ->getProfile()
      ->isLoggingEnabled();
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

    $diagnostics = [];
    try {
      $parsed = $this->parser->parse($raw_body);
    }
    catch (InvalidPostbackException) {
      return PostbackResult::invalidPayload();
    }
    if (
      $parsed->getVersion() === PostbackVersion::V1
      && $credentials->getMode() !== NovaPayMode::Test
    ) {
      return PostbackResult::invalidPayload();
    }
    $event = $parsed->getEvent();
    $lock_name = SessionLockName::fromSessionId($event->getSessionId());
    if (!$this->lock->acquire($lock_name)) {
      $this->lock->wait($lock_name);
      if (!$this->lock->acquire($lock_name)) {
        throw PostbackProcessingException::serializationFailed();
      }
    }

    try {
      $event_key = hash('sha256', $raw_body);
      $outcome = $this->event_repository->processOnce(
        $event_key,
        $event->getSessionId(),
        (string) $gateway->id(),
        $event->getStatus(),
        function () use ($gateway, $event, $event_key, $detailed_logging, &$diagnostics): PostbackOutcome {
          $payment = $this->findPayment($gateway, $event);
          if ($payment === NULL) {
            return PostbackOutcome::UnknownPayment;
          }

          $pending_refund = $this->hasConfirmablePendingRefund(
            $payment,
            $event->getStatus(),
          );
          $this->refund_manager->confirm($payment, $event, $event_key);
          $status_applied = $this->status_mapper->apply(
            $payment,
            $event->getStatus(),
          );
          if ($status_applied || $pending_refund) {
            return PostbackOutcome::Applied;
          }

          if ($detailed_logging) {
            $diagnostics = $this->buildIgnoredDiagnostics($payment);
          }
          return PostbackOutcome::Ignored;
        },
      );
    }
    finally {
      $this->lock->release($lock_name);
    }

    return PostbackResult::forEvent(
      $outcome ?? PostbackOutcome::Duplicate,
      $parsed->getVersion(),
      $event->getStatus(),
      $diagnostics,
    );
  }

  /**
   * Builds a fixed-value explanation for a non-mutating valid callback.
   *
   * This deliberately excludes session/order/payment identifiers, raw payload,
   * signature and any customer or payment data.
   *
   * @return array<string, string>
   *   Sanitized diagnostic context.
   */
  private function buildIgnoredDiagnostics(PaymentInterface $payment): array {
    $state = $payment->getState()->getId();
    $known_states = [
      'pending',
      'authorization',
      'completed',
      'partially_refunded',
      'refunded',
      'authorization_voided',
      'expired',
      'failed',
    ];
    $remote_state = $payment->getRemoteState();
    $pending_operation = $payment->hasField('novapay_pending_operation')
      ? $payment->get('novapay_pending_operation')->getString()
      : '';
    $pending_operation = PendingOperation::tryFrom($pending_operation);

    return [
      'reason' => 'no_permitted_payment_mutation',
      'payment_state' => in_array($state, $known_states, TRUE)
        ? $state
        : 'other',
      'remote_state' => is_string($remote_state)
        && NovaPayStatus::tryFrom($remote_state) !== NULL
        ? $remote_state
        : 'none_or_other',
      'pending_operation' => $pending_operation instanceof PendingOperation
        ? $pending_operation->value
        : 'none_or_other',
    ];
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
      throw PostbackProcessingException::paymentStorageUnavailable();
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
      throw PostbackProcessingException::orderStorageUnavailable();
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

  /**
   * Checks whether refund confirmation may mutate the payment before mapping.
   */
  private function hasConfirmablePendingRefund(
    PaymentInterface $payment,
    NovaPayStatus $status,
  ): bool {
    return in_array(
      $status,
      [
        NovaPayStatus::ProcessingVoid,
        NovaPayStatus::Paid,
        NovaPayStatus::Voided,
      ],
      TRUE,
    )
      && $payment->hasField('novapay_pending_operation')
      && $payment->get('novapay_pending_operation')->getString()
        === PendingOperation::Refund->value;
  }

}
