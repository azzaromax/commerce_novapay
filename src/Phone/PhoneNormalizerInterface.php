<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Phone;

/**
 * Normalizes customer phone numbers for NovaPay requests.
 */
interface PhoneNormalizerInterface {

  /**
   * Normalizes a phone number to E.164 format.
   *
   * @throws \InvalidArgumentException
   *   Thrown when the number cannot be represented as E.164.
   */
  public function normalize(#[\SensitiveParameter] string $phone): string;

}
