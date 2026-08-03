<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Phone;

use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Finds a usable phone on the order, billing profile, or customer account.
 */
final class OrderPhoneResolver implements OrderPhoneResolverInterface {

  /**
   * Constructs the order phone resolver.
   */
  public function __construct(
    private readonly PhoneNormalizerInterface $phone_normalizer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(OrderInterface $order): ?string {
    $entities = [$order];
    $billing_profile = $order->getBillingProfile();
    if ($billing_profile instanceof ContentEntityInterface) {
      $entities[] = $billing_profile;
    }
    $entities[] = $order->getCustomer();

    foreach ($entities as $entity) {
      $phone = $this->resolveFromEntity($entity);
      if ($phone !== NULL) {
        return $phone;
      }
    }

    return NULL;
  }

  /**
   * Finds the first valid phone candidate on one content entity.
   */
  private function resolveFromEntity(ContentEntityInterface $entity): ?string {
    $field_definitions = $entity->getFieldDefinitions();
    $field_names = [];
    foreach ($field_definitions as $field_name => $field_definition) {
      if (
        $field_definition->getType() === 'telephone'
        && $field_definition instanceof ThirdPartySettingsInterface
        && $field_definition->getThirdPartySetting(
          'commerce_novapay',
          'payment_phone',
          FALSE,
        )
      ) {
        $field_names[] = $field_name;
      }
    }

    foreach ($field_names as $field_name) {
      if (!$entity->hasField($field_name)) {
        continue;
      }
      $item = $entity->get($field_name)->first();
      if ($item === NULL) {
        continue;
      }

      try {
        return $this->phone_normalizer->normalize($item->getString());
      }
      catch (\InvalidArgumentException) {
        // Continue to another explicitly identified phone field.
      }
    }

    return NULL;
  }

}
