<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Indicates that a Commerce order cannot produce a safe NovaPay payload.
 */
final class OrderPayloadException extends \RuntimeException {

  /**
   * Creates an exception for an order without a payable balance.
   */
  public static function missingBalance(): self {
    return new self('The Commerce order does not have a payable balance.');
  }

  /**
   * Creates an exception for a non-UAH order.
   */
  public static function unsupportedCurrency(): self {
    return new self('NovaPay payments require a UAH order balance.');
  }

  /**
   * Creates an exception for a zero or negative order balance.
   */
  public static function nonPositiveBalance(): self {
    return new self('The Commerce order balance must be positive.');
  }

  /**
   * Creates an exception for an order without a stable identifier.
   */
  public static function invalidOrderIdentifier(): self {
    return new self('The Commerce order identifier is unavailable.');
  }

  /**
   * Creates an exception for a gateway without a stable identifier.
   */
  public static function invalidGatewayIdentifier(): self {
    return new self('The Commerce payment gateway identifier is unavailable.');
  }

  /**
   * Creates an exception for a missing NovaPay customer phone.
   */
  public static function missingPhone(): self {
    return new self('The NovaPay customer phone is unavailable.');
  }

}
