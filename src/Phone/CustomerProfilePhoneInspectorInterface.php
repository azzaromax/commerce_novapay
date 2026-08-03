<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Phone;

/**
 * Inspects Commerce customer profile types for a NovaPay phone source.
 */
interface CustomerProfilePhoneInspectorInterface {

  /**
   * Inspects every profile type designated as a Commerce customer profile.
   */
  public function inspect(): CustomerProfilePhoneReadiness;

}
