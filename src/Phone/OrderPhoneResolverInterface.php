<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Phone;

use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Resolves an existing customer phone from a Commerce order context.
 */
interface OrderPhoneResolverInterface {

  /**
   * Returns a normalized phone or NULL when checkout must collect one.
   */
  public function resolve(OrderInterface $order): ?string;

}
