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

    $fields['novapay_pending_operation'] = BundleFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Pending NovaPay operation'))
      ->setDescription(new TranslatableMarkup(
        'A bounded command name retained until NovaPay postback confirmation.',
      ))
      ->setSetting('max_length', 16);

    $fields['novapay_pending_amount'] = BundleFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Pending NovaPay amount'))
      ->setDescription(new TranslatableMarkup(
        'The decimal capture amount awaiting NovaPay postback confirmation.',
      ))
      ->setSetting('max_length', 64);

    $fields['novapay_pending_refund'] = BundleFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Pending NovaPay refund'))
      ->setDescription(new TranslatableMarkup(
        'A bounded item selection retained until signed postback confirmation.',
      ));

    return $fields;
  }

}
