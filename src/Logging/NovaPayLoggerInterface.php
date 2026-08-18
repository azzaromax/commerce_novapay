<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Logging;

/**
 * Writes sanitized structured NovaPay diagnostics to the Drupal log channel.
 */
interface NovaPayLoggerInterface {

  /**
   * Logs sanitized informational data only when detailed logging is enabled.
   *
   * @param bool $enabled
   *   Whether detailed logging is enabled for the gateway.
   * @param string $event
   *   A bounded machine-readable event name.
   * @param array<string, mixed> $context
   *   Structured diagnostic context.
   */
  public function logDetailed(
    bool $enabled,
    string $event,
    array $context = [],
  ): void;

  /**
   * Logs a sanitized JSON payload only when detailed logging is enabled.
   *
   * @param bool $enabled
   *   Whether detailed logging is enabled for the gateway.
   * @param string $event
   *   A bounded machine-readable event name.
   * @param string $json
   *   The untrusted JSON document to sanitize.
   * @param array<string, mixed> $context
   *   Structured diagnostic context excluding the JSON payload.
   */
  public function logDetailedJson(
    bool $enabled,
    string $event,
    #[\SensitiveParameter]
    string $json,
    array $context = [],
  ): void;

  /**
   * Logs a sanitized operational error regardless of the detailed toggle.
   *
   * @param string $event
   *   A bounded machine-readable event name.
   * @param array<string, mixed> $context
   *   Structured diagnostic context.
   */
  public function logError(string $event, array $context = []): void;

}
