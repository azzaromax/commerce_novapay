<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Exception;

/**
 * Indicates that the Commerce checkout context is unusable for NovaPay.
 */
final class CheckoutContextException extends \RuntimeException {

  /**
   * Creates an exception for unavailable Commerce order storage.
   */
  public static function orderStorageUnavailable(): self {
    return new self('Commerce order storage is unavailable.');
  }

  /**
   * Creates an exception for an unavailable locked Commerce order.
   */
  public static function orderUnavailable(): self {
    return new self('The Commerce order is unavailable.');
  }

  /**
   * Creates an exception when the checkout lock cannot be retained.
   */
  public static function lockLost(): self {
    return new self('The NovaPay checkout lock was lost.');
  }

  /**
   * Creates an exception for an incompatible payment gateway plugin.
   */
  public static function gatewayUnavailable(): self {
    return new self('The NovaPay gateway is unavailable.');
  }

  /**
   * Creates an exception for an unavailable Commerce order balance.
   */
  public static function balanceUnavailable(): self {
    return new self('The Commerce order balance is unavailable.');
  }

  /**
   * Creates an exception when another request owns the checkout lock.
   */
  public static function lockUnavailable(): self {
    return new self('The NovaPay checkout is already being prepared.');
  }

  /**
   * Creates an exception for an invalid Commerce order identifier.
   */
  public static function invalidOrderIdentifier(): self {
    return new self('The Commerce order identifier is invalid.');
  }

  /**
   * Creates an exception when the locked order uses a different gateway.
   */
  public static function gatewayMismatch(): self {
    return new self('The order does not use the NovaPay gateway.');
  }

  /**
   * Creates an exception for unavailable Commerce payment storage.
   */
  public static function paymentStorageUnavailable(): self {
    return new self('Commerce payment storage is unavailable.');
  }

}
