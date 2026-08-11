<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_novapay\Api\Dto\Request\VoidRequest;
use Drupal\commerce_novapay\Api\NovaPayApiClientInterface;
use Drupal\commerce_novapay\Exception\ApiFatalException;
use Drupal\commerce_novapay\Exception\ApiProcessingException;
use Drupal\commerce_novapay\Exception\ApiTransportException;
use Drupal\commerce_novapay\Exception\ApiUnexpectedResponseException;
use Drupal\commerce_novapay\Exception\NovaPayApiException;
use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;
use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Exception\InvalidRequestException;
use Drupal\commerce_payment\Exception\PaymentGatewayException;
use Drupal\commerce_payment\PaymentStorageInterface;
use Drupal\commerce_price\Calculator;
use Drupal\commerce_price\Price;

/**
 * Serializes NovaPay refunds and defers financial state to postback.
 */
final class RefundOperationManager implements RefundOperationManagerInterface {

  private const LOCK_TIMEOUT_SECONDS = 30.0;

  private const MAX_PENDING_BYTES = 65535;

  public function __construct(
    private readonly EntityTypeManagerInterface $entity_type_manager,
    private readonly NovaPayApiClientInterface $api_client,
    private readonly LockBackendInterface $lock,
    private readonly RefundLedgerRepositoryInterface $ledger,
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function canRefund(PaymentInterface $payment): bool {
    try {
      return in_array(
        $payment->getState()->getId(),
        ['completed', 'partially_refunded'],
        TRUE,
      )
        && $payment->hasField('novapay_pending_operation')
        && $payment->get('novapay_pending_operation')->isEmpty()
        && $payment->getBalance() instanceof Price
        && $payment->getBalance()->isPositive()
        && $this->getRefundableItems($payment) !== [];
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getRefundableItems(PaymentInterface $payment): array {
    $payment_id = $this->requirePaymentId($payment);
    $order = $payment->getOrder();
    if (!$order instanceof OrderInterface) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'The payment order is unavailable.',
      );
    }

    $currency = $payment->getAmount()?->getCurrencyCode();
    $refunded = $this->ledger->getRefundedQuantities($payment_id);
    $items = [];
    foreach ($order->getItems() as $order_item) {
      $order_item_id = $this->normalizeEntityId($order_item->id());
      $unit_price = $order_item->getAdjustedUnitPrice();
      if (
        $order_item_id === NULL
        || !$unit_price instanceof Price
        || $unit_price->getCurrencyCode() !== $currency
        || !$unit_price->isPositive()
      ) {
        continue;
      }

      $ordered_quantity = $this->normalizeQuantity(
        $order_item->getQuantity(),
        $payment,
      );
      $refunded_quantity = $refunded[$order_item_id] ?? '0';
      $available_quantity = Calculator::subtract(
        $ordered_quantity,
        $refunded_quantity,
      );
      if (Calculator::compare($available_quantity, '0') <= 0) {
        continue;
      }

      $items[] = new RefundableItem(
        $order_item_id,
        $order_item->getTitle(),
        $ordered_quantity,
        $refunded_quantity,
        $available_quantity,
        $unit_price,
      );
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function refund(
    PaymentInterface $payment,
    RuntimeConfigurationProviderInterface $gateway,
    array $quantities = [],
  ): void {
    $payment_id = $this->requirePaymentId($payment);
    $session_id = trim((string) $payment->getRemoteId());
    if ($session_id === '') {
      throw InvalidRequestException::createForPayment(
        $payment,
        'The NovaPay session ID is unavailable.',
      );
    }

    $lock_name = SessionLockName::fromSessionId($session_id);
    if (!$this->lock->acquire($lock_name, self::LOCK_TIMEOUT_SECONDS)) {
      throw PaymentGatewayException::createForPayment(
        $payment,
        'Another NovaPay operation is already being processed.',
      );
    }

    try {
      $current = $this->loadCurrentPayment($payment_id, $payment);
      if (!$this->canRefund($current)) {
        throw InvalidRequestException::createForPayment(
          $current,
          'This payment is not available for another NovaPay refund.',
        );
      }
      if (trim((string) $current->getRemoteId()) !== $session_id) {
        throw InvalidRequestException::createForPayment(
          $current,
          'The NovaPay session ID changed while preparing the refund.',
        );
      }

      $is_full = $this->isEmptySelection($quantities);
      $items = $is_full
        ? $this->buildFullSelection($current)
        : $this->buildPartialSelection($current, $quantities);
      $amount = $this->getSelectionTotal($items, $current);
      $operation_id = trim(
        $current->get('novapay_operation_id')->getString(),
      );
      if (!$is_full && $operation_id === '') {
        throw InvalidRequestException::createForPayment(
          $current,
          'A partial refund requires the NovaPay operation ID.',
        );
      }

      $operations = $is_full ? [] : [[
        'id' => $operation_id,
        'refund_amount' => $amount->getNumber(),
      ]];
      $this->markPending($current, $is_full, $items, $amount);

      try {
        $this->api_client->voidPayment(
          $gateway,
          new VoidRequest($session_id, $operations),
        );
      }
      catch (\Throwable $exception) {
        if (!$this->hasUncertainOutcome($exception)) {
          $this->clearPending($current);
          $current->save();
        }
        throw PaymentGatewayException::createForPayment(
          $current,
          $this->hasUncertainOutcome($exception)
            ? 'The NovaPay response is uncertain. Wait for postback confirmation before retrying.'
            : 'NovaPay rejected the refund request.',
          previous: $exception,
        );
      }
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function confirm(
    PaymentInterface $payment,
    NormalizedPostbackEvent $event,
    string $event_key,
  ): void {
    if (
      !$payment->hasField('novapay_pending_operation')
      || $payment->get('novapay_pending_operation')->getString()
        !== PendingOperation::Refund->value
    ) {
      return;
    }

    [$is_full, $processing_seen, $items] = $this->decodePending($payment);
    if (
      !$is_full
      && !$processing_seen
      && $event->getStatus() === NovaPayStatus::ProcessingVoid
    ) {
      $payment->set(
        'novapay_pending_refund',
        $this->encodePending($is_full, TRUE, $items),
      );
      $payment->save();
      return;
    }
    $pending_amount = $this->getSelectionTotal($items, $payment);
    $payment_amount = $payment->getAmount();
    $refunded_amount = $payment->getRefundedAmount();
    if (!$payment_amount instanceof Price || !$refunded_amount instanceof Price) {
      throw new \UnexpectedValueException(
        'The pending NovaPay refund amount is invalid.',
      );
    }
    $new_refunded_amount = $refunded_amount->add($pending_amount);
    $is_final = $event->getStatus() === NovaPayStatus::Voided;
    if ($is_full && !$is_final) {
      return;
    }
    if (!$is_final && !$this->confirmsPartialAmount(
      $event,
      $processing_seen,
      $pending_amount,
      $new_refunded_amount,
      $payment_amount,
    )) {
      return;
    }

    $payment_id = $this->requirePaymentId($payment);
    $transaction = $this->database->startTransaction();
    try {
      $this->ledger->recordConfirmed($payment_id, $event_key, $items);
      if ($is_final) {
        $payment->setRefundedAmount($payment_amount);
      }
      else {
        $payment->setRefundedAmount($new_refunded_amount);
        $payment->setRemoteState($event->getStatus()->value);
        if ($payment->getState()->getId() === 'completed') {
          $payment->getState()->applyTransitionById('partially_refund');
        }
      }
      $this->clearPending($payment);
      $payment->save();
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

  /**
   * Builds and validates a selected item refund.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The current payment.
   * @param array<int, string> $quantities
   *   Submitted quantities keyed by order item ID.
   *
   * @return list<\Drupal\commerce_novapay\Payment\RefundSelection>
   *   Validated selections.
   */
  private function buildPartialSelection(
    PaymentInterface $payment,
    array $quantities,
  ): array {
    $available_items = [];
    foreach ($this->getRefundableItems($payment) as $item) {
      $available_items[$item->getOrderItemId()] = $item;
    }

    $selections = [];
    foreach ($quantities as $order_item_id => $quantity) {
      if (!isset($available_items[$order_item_id])) {
        throw InvalidRequestException::createForPayment(
          $payment,
          'The refund contains an unavailable order item.',
        );
      }
      $quantity = $this->normalizeQuantity($quantity, $payment, TRUE);
      if ($quantity === '0') {
        continue;
      }
      $item = $available_items[$order_item_id];
      if (
        Calculator::compare($quantity, $item->getAvailableQuantity()) > 0
      ) {
        throw InvalidRequestException::createForPayment(
          $payment,
          'The refund quantity exceeds the paid quantity.',
        );
      }
      $selections[] = new RefundSelection(
        $order_item_id,
        $quantity,
        $item->getUnitPrice()->multiply($quantity),
      );
    }
    if ($selections === []) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'Select a positive quantity or leave every quantity empty for a full refund.',
      );
    }

    return $selections;
  }

  /**
   * Allocates the full remaining payment balance across remaining quantities.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The current payment.
   *
   * @return list<\Drupal\commerce_novapay\Payment\RefundSelection>
   *   Full remaining item selection.
   */
  private function buildFullSelection(PaymentInterface $payment): array {
    $balance = $payment->getBalance();
    if (!$balance instanceof Price || !$balance->isPositive()) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'The refundable payment balance is unavailable.',
      );
    }

    $remaining = $balance;
    $selections = [];
    foreach ($this->getRefundableItems($payment) as $item) {
      $amount = $item->getUnitPrice()->multiply(
        $item->getAvailableQuantity(),
      );
      if ($amount->greaterThan($remaining)) {
        $amount = $remaining;
      }
      if (!$amount->isPositive()) {
        continue;
      }
      $selections[] = new RefundSelection(
        $item->getOrderItemId(),
        $item->getAvailableQuantity(),
        $amount,
      );
      $remaining = $remaining->subtract($amount);
    }
    if ($selections === []) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'No refundable order item quantities remain.',
      );
    }
    if ($remaining->isPositive()) {
      $last = array_pop($selections);
      $selections[] = new RefundSelection(
        $last->getOrderItemId(),
        $last->getQuantity(),
        $last->getAmount()->add($remaining),
      );
    }

    return $selections;
  }

  /**
   * Gets a selection total and checks it against the payment balance.
   *
   * @param list<\Drupal\commerce_novapay\Payment\RefundSelection> $items
   *   Refund selections.
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The current payment.
   */
  private function getSelectionTotal(
    array $items,
    PaymentInterface $payment,
  ): Price {
    $balance = $payment->getBalance();
    if (!$balance instanceof Price) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'The refundable payment balance is unavailable.',
      );
    }
    $total = new Price('0', $balance->getCurrencyCode());
    foreach ($items as $item) {
      $total = $total->add($item->getAmount());
    }
    if (!$total->isPositive() || $total->greaterThan($balance)) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'The refund amount exceeds the paid balance.',
      );
    }

    return $total;
  }

  /**
   * Persists a bounded refund intent before the non-idempotent API request.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The current payment.
   * @param bool $is_full
   *   Whether NovaPay receives a full refund without operations.
   * @param list<\Drupal\commerce_novapay\Payment\RefundSelection> $items
   *   Pending selections.
   * @param \Drupal\commerce_price\Price $amount
   *   Exact pending amount.
   */
  private function markPending(
    PaymentInterface $payment,
    bool $is_full,
    array $items,
    Price $amount,
  ): void {
    $payload = $this->encodePending($is_full, FALSE, $items);
    if (strlen($payload) > self::MAX_PENDING_BYTES) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'The refund contains too many order items.',
      );
    }

    $payment->set('novapay_pending_operation', PendingOperation::Refund->value);
    $payment->set('novapay_pending_amount', $amount->getNumber());
    $payment->set('novapay_pending_refund', $payload);
    $payment->save();
  }

  /**
   * Restores a previously validated pending selection.
   *
   * @return array{bool, bool, list<\Drupal\commerce_novapay\Payment\RefundSelection>}
   *   Full flag, processing-observed flag, and selections.
   */
  private function decodePending(PaymentInterface $payment): array {
    try {
      $payload = json_decode(
        $payment->get('novapay_pending_refund')->getString(),
        TRUE,
        32,
        JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException) {
      throw new \UnexpectedValueException(
        'The pending NovaPay refund selection is invalid.',
      );
    }
    if (
      !is_array($payload)
      || !is_bool($payload['full'] ?? NULL)
      || !is_bool($payload['processing_seen'] ?? NULL)
      || !is_array($payload['items'] ?? NULL)
      || !array_is_list($payload['items'])
    ) {
      throw new \UnexpectedValueException(
        'The pending NovaPay refund selection is invalid.',
      );
    }

    $amount = $payment->getAmount();
    if (!$amount instanceof Price) {
      throw new \UnexpectedValueException(
        'The pending NovaPay refund currency is invalid.',
      );
    }
    $items = [];
    foreach ($payload['items'] as $item) {
      if (
        !is_array($item)
        || !is_int($item['order_item_id'] ?? NULL)
        || !is_string($item['quantity'] ?? NULL)
        || !is_string($item['amount'] ?? NULL)
      ) {
        throw new \UnexpectedValueException(
          'The pending NovaPay refund selection is invalid.',
        );
      }
      $items[] = new RefundSelection(
        $item['order_item_id'],
        $this->normalizeQuantity($item['quantity'], $payment),
        new Price($item['amount'], $amount->getCurrencyCode()),
      );
    }
    if ($items === []) {
      throw new \UnexpectedValueException(
        'The pending NovaPay refund selection is empty.',
      );
    }

    return [$payload['full'], $payload['processing_seen'], $items];
  }

  /**
   * Encodes bounded pending refund metadata.
   *
   * @param bool $is_full
   *   Whether the API request omitted operations for a full refund.
   * @param bool $processing_seen
   *   Whether processing_void was observed after submission.
   * @param list<\Drupal\commerce_novapay\Payment\RefundSelection> $items
   *   Validated pending item selections.
   */
  private function encodePending(
    bool $is_full,
    bool $processing_seen,
    array $items,
  ): string {
    return json_encode([
      'full' => $is_full,
      'processing_seen' => $processing_seen,
      'items' => array_map(
        static fn (RefundSelection $item): array => $item->toArray(),
        $items,
      ),
    ], JSON_THROW_ON_ERROR);
  }

  /**
   * Checks explicit postback refund evidence for a non-final refund.
   *
   * @param \Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent $event
   *   Verified normalized postback event.
   * @param bool $processing_seen
   *   Whether processing_void was observed for this pending intent.
   * @param \Drupal\commerce_price\Price $pending_amount
   *   Exact amount submitted in the current refund request.
   * @param \Drupal\commerce_price\Price $new_refunded_amount
   *   Cumulative confirmed amount after the current refund.
   * @param \Drupal\commerce_price\Price $payment_amount
   *   Original completed payment amount.
   */
  private function confirmsPartialAmount(
    NormalizedPostbackEvent $event,
    bool $processing_seen,
    Price $pending_amount,
    Price $new_refunded_amount,
    Price $payment_amount,
  ): bool {
    if (!$new_refunded_amount->lessThan($payment_amount)) {
      return FALSE;
    }
    if ($event->getStatus() !== NovaPayStatus::Paid) {
      return FALSE;
    }
    if ($processing_seen) {
      return TRUE;
    }
    $reported = $event->getRefundedAmount();
    if ($reported === NULL) {
      return FALSE;
    }
    $reported_amount = new Price(
      $reported,
      $payment_amount->getCurrencyCode(),
    );

    return $reported_amount->greaterThanOrEqual($pending_amount)
      && $reported_amount->greaterThanOrEqual($new_refunded_amount);
  }

  /**
   * Returns whether every submitted quantity is blank or zero.
   *
   * @param array<int, string> $quantities
   *   Submitted item quantities.
   */
  private function isEmptySelection(array $quantities): bool {
    foreach ($quantities as $quantity) {
      if (trim($quantity) !== '') {
        try {
          if (Calculator::compare(trim($quantity), '0') !== 0) {
            return FALSE;
          }
        }
        catch (\InvalidArgumentException) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

  /**
   * Validates an exact non-negative quantity string.
   */
  private function normalizeQuantity(
    mixed $quantity,
    PaymentInterface $payment,
    bool $allow_zero = FALSE,
  ): string {
    if (!is_string($quantity)) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'Refund quantities must be decimal strings.',
      );
    }
    $quantity = trim($quantity);
    if (preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]{1,6})?$/D', $quantity) !== 1) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'A refund quantity has an invalid format.',
      );
    }
    $quantity = Calculator::trim($quantity);
    if (!$allow_zero && Calculator::compare($quantity, '0') <= 0) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'A refund quantity must be greater than zero.',
      );
    }

    return $quantity;
  }

  /**
   * Loads the persisted payment after discarding the entity cache.
   */
  private function loadCurrentPayment(
    int $payment_id,
    PaymentInterface $payment,
  ): PaymentInterface {
    $storage = $this->entity_type_manager->getStorage('commerce_payment');
    if (!$storage instanceof PaymentStorageInterface) {
      throw PaymentGatewayException::createForPayment(
        $payment,
        'Commerce payment storage is unavailable.',
      );
    }
    $storage->resetCache([$payment_id]);
    $current = $storage->load($payment_id);
    if (
      !$current instanceof PaymentInterface
      || $current->getPaymentGatewayId() !== $payment->getPaymentGatewayId()
      || $current->bundle() !== 'novapay_payment'
    ) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'The NovaPay payment could not be reloaded safely.',
      );
    }

    return $current;
  }

  /**
   * Gets the required positive numeric payment identifier.
   */
  private function requirePaymentId(PaymentInterface $payment): int {
    $payment_id = $this->normalizeEntityId($payment->id());
    if ($payment_id === NULL) {
      throw InvalidRequestException::createForPayment(
        $payment,
        'The payment must be saved before it can be refunded.',
      );
    }

    return $payment_id;
  }

  /**
   * Converts a positive SQL entity identifier to an integer.
   */
  private function normalizeEntityId(mixed $entity_id): ?int {
    if (is_int($entity_id) && $entity_id > 0) {
      return $entity_id;
    }
    if (
      is_string($entity_id)
      && preg_match('/^[1-9][0-9]*$/D', $entity_id) === 1
      && (
        strlen($entity_id) < 10
        || strlen($entity_id) === 10
        && strcmp($entity_id, '4294967295') <= 0
      )
    ) {
      return (int) $entity_id;
    }

    return NULL;
  }

  /**
   * Clears all durable pending-refund metadata.
   */
  private function clearPending(PaymentInterface $payment): void {
    $payment->set('novapay_pending_operation', NULL);
    $payment->set('novapay_pending_amount', NULL);
    $payment->set('novapay_pending_refund', NULL);
  }

  /**
   * Returns whether NovaPay might have accepted the financial command.
   */
  private function hasUncertainOutcome(\Throwable $exception): bool {
    if (
      $exception instanceof ApiTransportException
      || $exception instanceof ApiFatalException
      || $exception instanceof ApiProcessingException
    ) {
      return TRUE;
    }
    if (
      $exception instanceof NovaPayApiException
      && ($exception->getHttpStatus() ?? 0) >= 500
    ) {
      return TRUE;
    }
    if ($exception instanceof ApiUnexpectedResponseException) {
      $status = $exception->getHttpStatus();
      return is_int($status) && $status >= 200 && $status < 300;
    }

    return FALSE;
  }

}
