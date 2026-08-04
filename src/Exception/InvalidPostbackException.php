<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Represents a verified but unsupported NovaPay postback payload.
 */
final class InvalidPostbackException extends \RuntimeException {

  /**
   * Creates a safe unsupported-schema exception.
   */
  public static function unsupportedSchema(): self {
    return new self('The NovaPay postback schema is unsupported.');
  }

}
