<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Indicates that a NovaPay request body could not be prepared safely.
 */
final class ApiRequestException extends NovaPayApiException {

  /**
   * Creates a safe JSON encoding exception.
   */
  public static function encodingFailed(): self {
    return new self('The NovaPay request body could not be encoded.');
  }

}
