<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Request;

/**
 * Contains data used to retrieve a NovaPay session status.
 */
final class GetStatusRequest implements NovaPayRequestInterface {

  public function __construct(
    private readonly string $session_id,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    return ['session_id' => $this->session_id];
  }

}
