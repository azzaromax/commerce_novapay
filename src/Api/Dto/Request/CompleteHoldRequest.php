<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Request;

/**
 * Contains data used to complete a NovaPay hold.
 */
final class CompleteHoldRequest implements NovaPayRequestInterface {

  /**
   * Constructs a complete-hold request.
   *
   * @param string $session_id
   *   NovaPay session identifier.
   * @param string|null $amount
   *   Optional decimal amount for a partial capture.
   * @param list<array<string, mixed>> $operations
   *   Partial-capture operations, if applicable.
   */
  public function __construct(
    private readonly string $session_id,
    private readonly ?string $amount = NULL,
    private readonly array $operations = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    $payload = ['session_id' => $this->session_id];
    if ($this->amount !== NULL) {
      $payload['amount'] = $this->amount;
    }
    if ($this->operations !== []) {
      $payload['operations'] = $this->operations;
    }

    return $payload;
  }

}
