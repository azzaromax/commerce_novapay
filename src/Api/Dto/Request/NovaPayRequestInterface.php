<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Api\Dto\Request;

/**
 * Defines a request body without the resolver-controlled merchant ID.
 */
interface NovaPayRequestInterface {

  /**
   * Returns the request fields in their stable JSON insertion order.
   *
   * @return array<string, mixed>
   *   The request fields.
   */
  public function toArray(): array;

}
