<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Base class for safe NovaPay API failures.
 */
abstract class NovaPayApiException extends \RuntimeException {

  /**
   * Constructs an API exception without retaining a response body.
   */
  protected function __construct(
    string $message,
    private readonly ?int $http_status = NULL,
  ) {
    parent::__construct($message);
  }

  /**
   * Gets the HTTP status, or NULL when no response was received.
   */
  public function getHttpStatus(): ?int {
    return $this->http_status;
  }

}
