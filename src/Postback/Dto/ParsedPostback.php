<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback\Dto;

use Drupal\commerce_novapay\Postback\PostbackVersion;

/**
 * Couples a normalized event to its detected documented schema version.
 */
final class ParsedPostback {

  public function __construct(
    private readonly PostbackVersion $version,
    private readonly NormalizedPostbackEvent $event,
  ) {}

  /**
   * Gets the detected postback version.
   */
  public function getVersion(): PostbackVersion {
    return $this->version;
  }

  /**
   * Gets the normalized payment event.
   */
  public function getEvent(): NormalizedPostbackEvent {
    return $this->event;
  }

}
