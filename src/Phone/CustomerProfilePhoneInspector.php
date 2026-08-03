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
      if (
        !$profile_type instanceof ProfileTypeInterface
        || !$profile_type->getThirdPartySetting(
          'commerce_order',
          'customer_profile_type',
          FALSE,
        )
      ) {
        continue;
      }

      $has_telephone = FALSE;
      $has_payment_phone = FALSE;
      $definitions = $this->entity_field_manager->getFieldDefinitions(
        'profile',
        (string) $profile_type->id(),
      );
      foreach ($definitions as $definition) {
        if (
          !$definition instanceof FieldConfigInterface
          || $definition->getType() !== 'telephone'
        ) {
          continue;
        }
        $has_telephone = TRUE;
        if ($definition->getThirdPartySetting(
          'commerce_novapay',
          'payment_phone',
          FALSE,
        )) {
          $has_payment_phone = TRUE;
          break;
        }
      }

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

}
