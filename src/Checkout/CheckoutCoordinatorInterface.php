<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Checkout;

use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Prepares or reuses a NovaPay off-site checkout payment.
 */
interface CheckoutCoordinatorInterface {

  /**
   * Returns the trusted NovaPay URL for the supplied Commerce payment.
   */
  public function prepareRedirect(
    PaymentInterface $payment,
    string $return_url,
    string $cancel_url,
  ): string;

}
