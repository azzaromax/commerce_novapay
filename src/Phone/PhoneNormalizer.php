<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Phone;

/**
 * Normalizes Ukrainian local and international customer phone numbers.
 */
final class PhoneNormalizer implements PhoneNormalizerInterface {

  /**
   * {@inheritdoc}
   */
  public function normalize(#[\SensitiveParameter] string $phone): string {
    $normalized = preg_replace('/[\s().-]+/u', '', trim($phone));
    if (!is_string($normalized) || $normalized === '') {
      throw new \InvalidArgumentException('Invalid phone number.');
    }

    if (str_starts_with($normalized, '00')) {
      $normalized = '+' . substr($normalized, 2);
    }
    elseif (preg_match('/^0\d{9}$/D', $normalized) === 1) {
      $normalized = '+38' . $normalized;
    }
    elseif (preg_match('/^380\d{9}$/D', $normalized) === 1) {
      $normalized = '+' . $normalized;
    }

    if (preg_match('/^\+[1-9]\d{7,14}$/D', $normalized) !== 1) {
      throw new \InvalidArgumentException('Invalid phone number.');
    }

    return $normalized;
  }

}
