<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Payment;

/**
 * Builds one bounded lock name for all operations on a NovaPay session.
 */
final class SessionLockName {

  /**
   * Builds a non-sensitive lock name from a validated session identifier.
   */
  public static function fromSessionId(string $session_id): string {
    return 'commerce_novapay:session:' . hash('sha256', $session_id);
  }

}
