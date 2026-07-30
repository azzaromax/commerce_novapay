<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Credential;

/**
 * Resolves environment-local NovaPay credentials.
 */
interface CredentialResolverInterface {

  /**
   * Resolves and validates credentials for a payment gateway.
   *
   * @param string $gateway_uuid
   *   The Commerce payment gateway UUID.
   * @param \Drupal\commerce_novapay\Credential\NovaPayMode $mode
   *   The environment selected by the local runtime profile.
   *
   * @return \Drupal\commerce_novapay\Credential\Credentials
   *   Validated credentials for signing and signature verification.
   *
   * @throws \Drupal\commerce_novapay\Exception\InvalidCredentialsException
   *   Thrown when credentials are missing, unreadable, or invalid.
   */
  public function resolve(
    string $gateway_uuid,
    NovaPayMode $mode,
  ): Credentials;

}
