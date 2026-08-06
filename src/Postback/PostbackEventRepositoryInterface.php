<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

/**
 * Provides atomic postback event deduplication and journaling.
 */
interface PostbackEventRepositoryInterface {

  /**
   * Runs a processor and records its stable outcome unless it already exists.
   *
   * Unknown payments are acknowledged but not claimed, allowing an identical
   * callback to be applied if it raced creation of the local payment entity.
   *
   * @param string $event_key
   *   SHA-256 hash of the exact raw request body.
   * @param string $session_id
   *   The normalized NovaPay session identifier.
   * @param string $gateway_id
   *   The Commerce payment gateway configuration ID.
   * @param \Drupal\commerce_novapay\Postback\NovaPayStatus $status
   *   The normalized NovaPay status.
   * @param callable(): \Drupal\commerce_novapay\Postback\PostbackOutcome $processor
   *   The financial state processor to run exactly once.
   *
   * @return \Drupal\commerce_novapay\Postback\PostbackOutcome|null
   *   The processing outcome, or NULL when this event was already recorded.
   */
  public function processOnce(
    string $event_key,
    string $session_id,
    string $gateway_id,
    NovaPayStatus $status,
    callable $processor,
  ): ?PostbackOutcome;

}
