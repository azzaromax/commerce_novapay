<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Applies one normalized NovaPay status to a Commerce payment.
 */
interface PaymentStatusMapperInterface {

  /**
   * Applies the remote state and an allowed Commerce workflow transition.
   */
  public function apply(
    PaymentInterface $payment,
    NovaPayStatus $status,
  ): void;

}
