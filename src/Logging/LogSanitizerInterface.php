<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Logging;

/**
 * Sanitizes structured and JSON diagnostic data before it reaches a logger.
 */
interface LogSanitizerInterface {

  /**
   * Recursively removes secrets, PII, and unbounded values.
   */
  public function sanitize(mixed $value): mixed;

  /**
   * Decodes and sanitizes JSON without retaining malformed raw input.
   */
  public function sanitizeJson(
    #[\SensitiveParameter]
    string $json,
  ): mixed;

}
