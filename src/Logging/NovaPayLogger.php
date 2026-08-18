<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Logging;

use Psr\Log\LoggerInterface;

/**
 * Enforces logging toggle and sanitizer use for every NovaPay log entry.
 */
final class NovaPayLogger implements NovaPayLoggerInterface {

  private const MAX_ENTRY_BYTES = 32768;

  private const JSON_FLAGS = JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE
    | JSON_THROW_ON_ERROR;

  /**
   * Constructs a safe NovaPay logger.
   */
  public function __construct(
    private readonly LoggerInterface $logger,
    private readonly LogSanitizerInterface $sanitizer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function logDetailed(
    bool $enabled,
    string $event,
    array $context = [],
  ): void {
    if (!$enabled) {
      return;
    }
    $this->write(FALSE, $event, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function logDetailedJson(
    bool $enabled,
    string $event,
    #[\SensitiveParameter]
    string $json,
    array $context = [],
  ): void {
    if (!$enabled) {
      return;
    }
    $context['payload'] = $this->sanitizer->sanitizeJson($json);
    $this->write(FALSE, $event, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function logError(string $event, array $context = []): void {
    $this->write(TRUE, $event, $context);
  }

  /**
   * Writes one self-contained JSON object and never disrupts payment flows.
   *
   * @param bool $error
   *   Whether to write the entry at error level.
   * @param string $event
   *   A bounded machine-readable event name.
   * @param array<string, mixed> $context
   *   Structured diagnostic context.
   */
  private function write(bool $error, string $event, array $context): void {
    $event = preg_match('/^[a-z0-9_.-]{1,64}$/D', $event) === 1
      ? $event
      : 'invalid_event';
    $entry = $this->sanitizer->sanitize([
      'event' => $event,
      'context' => $context,
    ]);

    try {
      $message = json_encode($entry, self::JSON_FLAGS, 32);
      if (strlen($message) > self::MAX_ENTRY_BYTES) {
        $message = json_encode(
          [
            'event' => $event,
            'context' => [
              'entry' => LogSanitizer::OVERSIZED,
              'original_bytes' => strlen($message),
            ],
          ],
          self::JSON_FLAGS,
          4,
        );
      }
      if ($error) {
        $this->logger->error($message);
      }
      else {
        $this->logger->info($message);
      }
    }
    catch (\Throwable) {
      // Logging must never break a financial or callback operation. Do not
      // attempt an unsafe fallback containing the original context.
    }
  }

}
