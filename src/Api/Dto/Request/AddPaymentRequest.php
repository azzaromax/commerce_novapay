<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Request;

/**
 * Contains data used to add a payment to a NovaPay session.
 */
final class AddPaymentRequest implements NovaPayRequestInterface {

  /**
   * Constructs an add-payment request.
   *
   * @param string $session_id
   *   NovaPay session identifier.
   * @param string $amount
   *   Decimal payment amount.
   * @param bool $use_hold
   *   Whether the payment uses two-stage authorization.
   * @param string|null $external_id
   *   Optional merchant order identifier.
   * @param string|null $identifier
   *   Optional recipient identifier.
   * @param list<array<string, mixed>> $products
   *   Order products prepared by the order payload builder.
   */
  public function __construct(
    private readonly string $session_id,
    private readonly string $amount,
    private readonly bool $use_hold,
    private readonly ?string $external_id = NULL,
    private readonly ?string $identifier = NULL,
    private readonly array $products = [],
  ) {}

  /**
   * {@inheritdoc}
   */
  public function toArray(): array {
    $payload = [
      'session_id' => $this->session_id,
      'amount' => $this->amount,
      'use_hold' => $this->use_hold,
    ];

    if ($this->external_id !== NULL) {
      $payload['external_id'] = $this->external_id;
    }
    if ($this->identifier !== NULL) {
      $payload['identifier'] = $this->identifier;
    }
    if ($this->products !== []) {
      $payload['products'] = $this->products;
    }

    return $payload;
  }

}
