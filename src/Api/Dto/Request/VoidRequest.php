<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Request;

/**
 * Contains data used to void or refund a NovaPay session.
 */
final class VoidRequest implements NovaPayRequestInterface {

  /**
   * Constructs a void request.
   *
   * @param string $session_id
   *   NovaPay session identifier.
   * @param list<array<string, mixed>> $operations
   *   Partial-refund operations, if applicable.
   */
  public function __construct(
    private readonly string $session_id,
    private readonly array $operations = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    $payload = ['session_id' => $this->session_id];
    if ($this->operations !== []) {
      $payload['operations'] = $this->operations;
    }

    return $payload;
  }

}
