<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Signature;

/**
 * Verifies legacy signatures accepted only from the NovaPay sandbox.
 */
interface SandboxLegacyVerifierInterface {

  /**
   * Verifies one canonical base64-encoded legacy sandbox signature.
   */
  public function verify(
    #[\SensitiveParameter]
    string $raw_body,
    #[\SensitiveParameter]
    string $signature_base64,
    #[\SensitiveParameter]
    string $public_key_pem,
  ): bool;

}
