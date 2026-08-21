<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Runtime;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\commerce_novapay\Credential\CredentialResolver;
use Drupal\commerce_novapay\Credential\NovaPayMode;
use Drupal\commerce_novapay\Credential\RsaKeyValidatorInterface;
use Drupal\commerce_novapay\Exception\InvalidRuntimeProfileException;

/**
 * Stores runtime settings and live keys in the Drupal private filesystem.
 */
final class RuntimeProfileStorage implements RuntimeProfileStorageInterface {

  private const MAX_PROFILE_BYTES = 65536;

  private const MAX_KEY_BYTES = 65536;

  /**
   * Constructs an environment-local runtime profile storage.
   */
  public function __construct(
    private readonly RsaKeyValidatorInterface $key_validator,
    private readonly LockBackendInterface $lock,
    private readonly string $private_base_uri = 'private://commerce_novapay',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function load(string $gateway_uuid): ?RuntimeProfile {
    $this->assertGatewayUuid($gateway_uuid);
    $uri = $this->getDirectory($gateway_uuid) . '/settings.json';
    if (!@is_file($uri)) {
      return NULL;
    }

    $lock_name = CredentialResolver::getLockName($gateway_uuid);
    if (!$this->acquireLock($lock_name)) {
      throw InvalidRuntimeProfileException::lockUnavailable();
    }

    try {
      $contents = $this->readFile($uri, self::MAX_PROFILE_BYTES);
      if ($contents === NULL) {
        throw InvalidRuntimeProfileException::profileUnavailable();
      }

      try {
        $values = json_decode(
          $contents,
          TRUE,
          16,
          JSON_THROW_ON_ERROR,
        );
      }
      catch (\JsonException) {
        throw InvalidRuntimeProfileException::invalidProfile();
      }

      if (!is_array($values)) {
        throw InvalidRuntimeProfileException::invalidProfile();
      }

      return RuntimeProfile::fromArray($values);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function assertWritable(string $gateway_uuid): void {
    $this->assertGatewayUuid($gateway_uuid);
    $directory = $this->getDirectory($gateway_uuid);
    $scheme = parse_url($directory, PHP_URL_SCHEME);
    if (
      is_string($scheme)
      && !in_array($scheme, stream_get_wrappers(), TRUE)
    ) {
      throw InvalidRuntimeProfileException::privateStorageUnavailable();
    }

    if (
      !@is_dir($directory)
      && !@mkdir($directory, 0700, TRUE)
      && !@is_dir($directory)
    ) {
      throw InvalidRuntimeProfileException::privateStorageUnavailable();
    }

    if (!@chmod($directory, 0700) || !@is_writable($directory)) {
      throw InvalidRuntimeProfileException::privateStorageUnavailable();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hasValidLiveKeys(string $gateway_uuid): bool {
    $this->assertGatewayUuid($gateway_uuid);
    $lock_name = CredentialResolver::getLockName($gateway_uuid);
    if (!$this->acquireLock($lock_name)) {
      throw InvalidRuntimeProfileException::lockUnavailable();
    }

    try {
      $directory = $this->getDirectory($gateway_uuid);
      $private_key = $this->readFile(
        $directory . '/private.pem',
        self::MAX_KEY_BYTES,
      );
      $public_key = $this->readFile(
        $directory . '/public.pem',
        self::MAX_KEY_BYTES,
      );
      if ($private_key === NULL || $public_key === NULL) {
        return FALSE;
      }

      try {
        $this->key_validator->validatePrivateKey($private_key);
        $this->key_validator->validatePublicKey($public_key);
      }
      catch (\RuntimeException) {
        return FALSE;
      }

      return TRUE;
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(
    string $gateway_uuid,
    RuntimeProfile $profile,
    #[\SensitiveParameter]
    ?string $private_key_pem = NULL,
    #[\SensitiveParameter]
    ?string $public_key_pem = NULL,
  ): void {
    $this->assertGatewayUuid($gateway_uuid);
    $lock_name = CredentialResolver::getLockName($gateway_uuid);
    if (!$this->acquireLock($lock_name)) {
      throw InvalidRuntimeProfileException::lockUnavailable();
    }

    try {
      $this->assertWritable($gateway_uuid);
      $has_private_upload = $private_key_pem !== NULL;
      $has_public_upload = $public_key_pem !== NULL;
      if ($has_private_upload !== $has_public_upload) {
        throw InvalidRuntimeProfileException::incompleteKeyUpload();
      }

      $files = [
        'settings.json' => json_encode(
          $profile->toArray(),
          JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n",
      ];

      if ($profile->getMode() === NovaPayMode::Live) {
        if ($has_private_upload && $has_public_upload) {
          $this->key_validator->validatePrivateKey($private_key_pem);
          $this->key_validator->validatePublicKey($public_key_pem);
          $files = [
            'private.pem' => $private_key_pem,
            'public.pem' => $public_key_pem,
          ] + $files;
        }
        elseif (!$this->hasValidLiveKeysWithoutLock($gateway_uuid)) {
          throw InvalidRuntimeProfileException::incompleteKeyUpload();
        }
      }

      $this->activateFiles($gateway_uuid, $files);
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $gateway_uuid): void {
    $this->assertGatewayUuid($gateway_uuid);
    $lock_name = CredentialResolver::getLockName($gateway_uuid);
    if (!$this->acquireLock($lock_name)) {
      throw InvalidRuntimeProfileException::lockUnavailable();
    }

    try {
      $directory = $this->getDirectory($gateway_uuid);
      if (!is_dir($directory)) {
        return;
      }

      $entries = @scandir($directory);
      if (!is_array($entries)) {
        throw InvalidRuntimeProfileException::writeFailed();
      }

      foreach ($entries as $entry) {
        if (
          in_array(
            $entry,
            ['settings.json', 'private.pem', 'public.pem'],
            TRUE,
          )
          || preg_match(
            '/^\\.(settings\\.json|private\\.pem|public\\.pem)\\.(tmp|bak)-[0-9a-f]{32}$/D',
            $entry,
          ) === 1
        ) {
          $uri = $directory . '/' . $entry;
          if (!@unlink($uri) && is_file($uri)) {
            throw InvalidRuntimeProfileException::writeFailed();
          }
        }
      }

      if (!@rmdir($directory)) {
        throw InvalidRuntimeProfileException::writeFailed();
      }
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Checks existing live keys while the caller holds the profile lock.
   *
   * @param string $gateway_uuid
   *   The Commerce payment gateway UUID.
   */
  private function hasValidLiveKeysWithoutLock(string $gateway_uuid): bool {
    $directory = $this->getDirectory($gateway_uuid);
    $private_key = $this->readFile(
      $directory . '/private.pem',
      self::MAX_KEY_BYTES,
    );
    $public_key = $this->readFile(
      $directory . '/public.pem',
      self::MAX_KEY_BYTES,
    );
    if ($private_key === NULL || $public_key === NULL) {
      return FALSE;
    }

    try {
      $this->key_validator->validatePrivateKey($private_key);
      $this->key_validator->validatePublicKey($public_key);
    }
    catch (\RuntimeException) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Activates a staged set of files with rollback under the profile lock.
   *
   * @param string $gateway_uuid
   *   The Commerce payment gateway UUID.
   * @param array<string, string> $files
   *   File contents indexed by stable target filename.
   */
  private function activateFiles(string $gateway_uuid, array $files): void {
    $directory = $this->getDirectory($gateway_uuid);
    $token = bin2hex(random_bytes(16));
    $temporary_files = [];
    $backups = [];
    $activated = [];

    try {
      foreach ($files as $filename => $contents) {
        $temporary_uri = $directory . '/.' . $filename . '.tmp-' . $token;
        // The Drupal local stream wrapper does not support LOCK_EX through
        // file_put_contents(). A unique filename plus the cooperative gateway
        // lock protects the staged write before atomic rename.
        $written = @file_put_contents($temporary_uri, $contents);
        if (
          $written !== strlen($contents)
          || !@chmod($temporary_uri, 0600)
        ) {
          throw InvalidRuntimeProfileException::writeFailed();
        }
        $temporary_files[$filename] = $temporary_uri;
      }

      foreach ($temporary_files as $filename => $temporary_uri) {
        $target_uri = $directory . '/' . $filename;
        if (is_file($target_uri)) {
          $backup_uri = $directory . '/.' . $filename . '.bak-' . $token;
          if (!@rename($target_uri, $backup_uri)) {
            throw InvalidRuntimeProfileException::writeFailed();
          }
          $backups[$filename] = $backup_uri;
        }

        if (!@rename($temporary_uri, $target_uri)) {
          throw InvalidRuntimeProfileException::writeFailed();
        }
        unset($temporary_files[$filename]);
        $activated[$filename] = $target_uri;

        if (!@chmod($target_uri, 0600)) {
          throw InvalidRuntimeProfileException::writeFailed();
        }
      }

    }
    catch (\Throwable $exception) {
      foreach ($activated as $target_uri) {
        @unlink($target_uri);
      }
      foreach ($backups as $filename => $backup_uri) {
        @rename($backup_uri, $directory . '/' . $filename);
      }
      foreach ($temporary_files as $temporary_uri) {
        @unlink($temporary_uri);
      }

      if ($exception instanceof InvalidRuntimeProfileException) {
        throw $exception;
      }
      throw InvalidRuntimeProfileException::writeFailed();
    }

    foreach ($backups as $backup_uri) {
      if (!@unlink($backup_uri) && is_file($backup_uri)) {
        throw InvalidRuntimeProfileException::writeFailed();
      }
    }
  }

  /**
   * Reads one size-limited file.
   */
  private function readFile(string $uri, int $maximum_bytes): ?string {
    $contents = @file_get_contents($uri, FALSE, NULL, 0, $maximum_bytes + 1);
    if (
      !is_string($contents)
      || trim($contents) === ''
      || strlen($contents) > $maximum_bytes
    ) {
      return NULL;
    }

    return $contents;
  }

  /**
   * Gets the stable private directory for one gateway UUID.
   */
  private function getDirectory(string $gateway_uuid): string {
    return rtrim($this->private_base_uri, '/') . '/' . $gateway_uuid;
  }

  /**
   * Rejects traversal and non-UUID gateway identifiers.
   */
  private function assertGatewayUuid(string $gateway_uuid): void {
    if (
      preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di',
        $gateway_uuid,
      ) !== 1
    ) {
      throw InvalidRuntimeProfileException::invalidProfile();
    }
  }

  /**
   * Acquires a short-lived profile lock.
   */
  private function acquireLock(string $lock_name): bool {
    if ($this->lock->acquire($lock_name, 30.0)) {
      return TRUE;
    }

    $this->lock->wait($lock_name, 5);
    return $this->lock->acquire($lock_name, 30.0);
  }

}
