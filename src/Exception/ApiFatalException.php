<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Indicates a fatal error reported by NovaPay.
 */
final class ApiFatalException extends NovaPayApiException {

  /**
   * Constructs a fatal API exception.
   */
  public function __construct(int $http_status) {
    parent::__construct('NovaPay reported a system error.', $http_status);
  }

}
