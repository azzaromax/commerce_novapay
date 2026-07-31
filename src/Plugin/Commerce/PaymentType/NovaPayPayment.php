<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Plugin\Commerce\PaymentType;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\commerce_payment\Attribute\CommercePaymentType;
use Drupal\commerce_payment\Plugin\Commerce\PaymentType\PaymentTypeBase;
use Drupal\entity\BundleFieldDefinition;

/**
 * Provides the NovaPay payment type.
 */
#[CommercePaymentType(
  id: 'novapay_payment',
  label: new TranslatableMarkup('NovaPay payment'),
  workflow: 'novapay_payment',
)]
final class NovaPayPayment extends PaymentTypeBase {

  /**
   * {@inheritdoc}
   */
  public function buildFieldDefinitions(): array {
    $fields = [];

    $fields['novapay_operation_id'] = BundleFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('NovaPay operation ID'))
      ->setDescription(new TranslatableMarkup(
        'The remote NovaPay operation identifier.',
      ))
      ->setSetting('max_length', 255);

    $fields['novapay_payment_url'] = BundleFieldDefinition::create('uri')
      ->setLabel(new TranslatableMarkup('NovaPay payment URL'))
      ->setDescription(new TranslatableMarkup(
        'The off-site URL returned for this NovaPay payment.',
      ));

    return $fields;
  }

}
