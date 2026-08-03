<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Response;

/**
 * Represents a successful NovaPay command acknowledgement.
 */
final class AcknowledgementResponse {

  /**
   * Constructs an acknowledgement response.
   *
   * @param int $status_code
   *   Successful HTTP status code.
   */
  public function __construct(
    private readonly int $status_code,
  ) {}

  /**
   * Gets the successful HTTP status code.
   */
  public function getStatusCode(): int {
    return $this->status_code;
  }

}
