<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Stores bounded postback event metadata with a unique raw-body hash.
 */
final class PostbackEventRepository implements PostbackEventRepositoryInterface {

  private const TABLE = 'commerce_novapay_postback_event';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function processOnce(
    string $event_key,
    string $session_id,
    string $gateway_id,
    NovaPayStatus $status,
    callable $processor,
  ): ?PostbackOutcome {
    $transaction = $this->database->startTransaction();
    try {
      $existing_outcome = $this->database->select(self::TABLE, 'event')
        ->fields('event', ['outcome'])
        ->condition('event_key', $event_key)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if (
        $existing_outcome !== FALSE
        && $existing_outcome !== PostbackOutcome::UnknownPayment->value
      ) {
        $transaction->commitOrRelease();
        return NULL;
      }
      if ($existing_outcome === PostbackOutcome::UnknownPayment->value) {
        // Older module versions journaled unknown sessions. Remove that claim
        // so a later replay can be applied after the Commerce payment exists.
        $this->database->delete(self::TABLE)
          ->condition('event_key', $event_key)
          ->condition('outcome', PostbackOutcome::UnknownPayment->value)
          ->execute();
      }

      $outcome = $processor();
      if ($outcome === PostbackOutcome::UnknownPayment) {
        // A valid callback can race the local payment save. Acknowledge it,
        // but leave it unclaimed so an identical replay remains actionable.
        $transaction->commitOrRelease();
        return $outcome;
      }
      $this->database->insert(self::TABLE)
        ->fields([
          'event_key' => $event_key,
          'session_id' => $session_id,
          'gateway_id' => $gateway_id,
          'status' => $status->value,
          'received' => $this->time->getRequestTime(),
          'signature_valid' => 1,
          'outcome' => $outcome->value,
        ])
        ->execute();
      $transaction->commitOrRelease();

      return $outcome;
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
  }

}
