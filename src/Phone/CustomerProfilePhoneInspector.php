<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Phone;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\FieldConfigInterface;
use Drupal\profile\Entity\ProfileTypeInterface;

/**
 * Checks configured Commerce customer profiles for designated phone fields.
 */
final class CustomerProfilePhoneInspector implements CustomerProfilePhoneInspectorInterface {

  /**
   * Constructs the customer profile phone inspector.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entity_type_manager,
    private readonly EntityFieldManagerInterface $entity_field_manager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function inspect(): CustomerProfilePhoneReadiness {
    $missing_telephone = [];
    $unmarked_telephone = [];
    $profile_types = $this->entity_type_manager
      ->getStorage('profile_type')
      ->loadMultiple();

    foreach ($profile_types as $profile_type) {
      $profile_type = $this->getCommerceCustomerProfile($profile_type);
      if ($profile_type === NULL) {
        continue;
      }

      [$has_telephone, $has_payment_phone] = $this->inspectPhoneFields(
        $profile_type,
      );
      $label = (string) $profile_type->label();
      if (!$has_telephone) {
        $missing_telephone[] = $label;
      }
      elseif (!$has_payment_phone) {
        $unmarked_telephone[] = $label;
      }
    }

    sort($missing_telephone);
    sort($unmarked_telephone);
    return new CustomerProfilePhoneReadiness(
      $missing_telephone,
      $unmarked_telephone,
    );
  }

  /**
   * Determines whether an entity is designated as a Commerce customer profile.
   */
  private function getCommerceCustomerProfile(mixed $profile_type): ?ProfileTypeInterface {
    if (
      !$profile_type instanceof ProfileTypeInterface
      || !(bool) $profile_type->getThirdPartySetting(
        'commerce_order',
        'customer_profile_type',
        FALSE,
      )
    ) {
      return NULL;
    }

    return $profile_type;
  }

  /**
   * Inspects telephone field definitions for one customer profile type.
   *
   * @return array{bool, bool}
   *   Whether a telephone field exists and whether one is designated for
   *   NovaPay payments.
   */
  private function inspectPhoneFields(ProfileTypeInterface $profile_type): array {
    $has_telephone = FALSE;
    $has_payment_phone = FALSE;
    $definitions = $this->entity_field_manager->getFieldDefinitions(
      'profile',
      (string) $profile_type->id(),
    );
    foreach ($definitions as $definition) {
      $definition = $this->getTelephoneField($definition);
      if ($definition === NULL) {
        continue;
      }
      $has_telephone = TRUE;
      if ($this->isPaymentPhoneField($definition)) {
        $has_payment_phone = TRUE;
        break;
      }
    }

    return [$has_telephone, $has_payment_phone];
  }

  /**
   * Checks whether a field definition is a configurable telephone field.
   */
  private function getTelephoneField(mixed $definition): ?FieldConfigInterface {
    if (
      !$definition instanceof FieldConfigInterface
      || $definition->getType() !== 'telephone'
    ) {
      return NULL;
    }

    return $definition;
  }

  /**
   * Checks whether a telephone field is designated for NovaPay payments.
   */
  private function isPaymentPhoneField(FieldConfigInterface $definition): bool {
    return (bool) $definition->getThirdPartySetting(
      'commerce_novapay',
      'payment_phone',
      FALSE,
    );
  }

}
