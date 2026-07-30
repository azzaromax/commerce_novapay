<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Runtime;

/**
 * Persists NovaPay settings outside config, State API, and the database.
 */
interface RuntimeProfileStorageInterface {

  /**
   * Loads the local profile, or NULL when it has not been configured.
   */
  public function load(string $gateway_uuid): ?RuntimeProfile;

  /**
   * Ensures the gateway's private directory can be written.
   */
  public function assertWritable(string $gateway_uuid): void;

  /**
   * Returns whether valid live RSA key files already exist.
   */
  public function hasValidLiveKeys(string $gateway_uuid): bool;

  /**
   * Atomically saves settings and optionally rotates both live keys.
   */
  public function save(
    string $gateway_uuid,
    RuntimeProfile $profile,
    #[\SensitiveParameter]
    ?string $private_key_pem = NULL,
    #[\SensitiveParameter]
    ?string $public_key_pem = NULL,
  ): void;

  /**
   * Deletes the exact local profile directory for a removed gateway.
   */
  public function delete(string $gateway_uuid): void;

}
