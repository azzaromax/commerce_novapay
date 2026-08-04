<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback\Parser;

use Drupal\commerce_novapay\Postback\Dto\NormalizedPostbackEvent;

/**
 * Parses one documented NovaPay postback schema version.
 */
interface PostbackVersionParserInterface {

  /**
   * Determines whether the decoded object belongs to this schema.
   *
   * @param array<string, mixed> $payload
   *   The verified decoded payload.
   */
  public function supports(array $payload): bool;

  /**
   * Normalizes one verified decoded payload.
   *
   * @param array<string, mixed> $payload
   *   The verified decoded payload.
   */
  public function parse(array $payload): NormalizedPostbackEvent;

}
