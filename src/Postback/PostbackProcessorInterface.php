<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback;

use Drupal\commerce_novapay\Runtime\RuntimeConfigurationProviderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Verifies, normalizes, resolves, and applies one NovaPay postback.
 */
interface PostbackProcessorInterface {

  /**
   * Processes one exact raw postback body and signature.
   */
  public function process(
    PaymentGatewayInterface $gateway,
    RuntimeConfigurationProviderInterface $gateway_plugin,
    #[\SensitiveParameter]
    string $raw_body,
    #[\SensitiveParameter]
    string $signature,
  ): PostbackResult;

}
