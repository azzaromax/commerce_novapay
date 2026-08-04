<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback\Parser;

use Drupal\commerce_novapay\Postback\Dto\ParsedPostback;

/**
 * Detects and parses documented NovaPay acquiring postback schemas.
 */
interface PostbackParserInterface {

  /**
   * Parses an exact verified raw JSON body.
   */
  public function parse(
    #[\SensitiveParameter]
    string $raw_body,
  ): ParsedPostback;

}
