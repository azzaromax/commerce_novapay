<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Phone;

/**
 * Describes payment-phone readiness of Commerce customer profile types.
 */
final class CustomerProfilePhoneReadiness {

  /**
   * Constructs the readiness result.
   *
   * @param list<string> $missing_telephone
   *   Customer profile type labels without a telephone field.
   * @param list<string> $unmarked_telephone
   *   Labels with telephone fields but no NovaPay payment-phone designation.
   */
  public function __construct(
    private readonly array $missing_telephone,
    private readonly array $unmarked_telephone,
  ) {}

  /**
   * Returns whether every customer profile type has a designated phone.
   */
  public function isReady(): bool {
    return $this->missing_telephone === []
      && $this->unmarked_telephone === [];
  }

  /**
   * Gets profile type labels without telephone fields.
   *
   * @return list<string>
   *   Profile type labels.
   */
  public function getMissingTelephone(): array {
    return $this->missing_telephone;
  }

  /**
   * Gets profile type labels with unmarked telephone fields.
   *
   * @return list<string>
   *   Profile type labels.
   */
  public function getUnmarkedTelephone(): array {
    return $this->unmarked_telephone;
  }

}
