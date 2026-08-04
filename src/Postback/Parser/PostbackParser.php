<?php

declare(strict_types=1);

namespace Drupal\commerce_novapay\Postback\Parser;

use Drupal\commerce_novapay\Exception\InvalidPostbackException;
use Drupal\commerce_novapay\Postback\Dto\ParsedPostback;
use Drupal\commerce_novapay\Postback\PostbackVersion;

/**
 * Decodes verified raw JSON and detects acquiring postback v1 or v2.
 */
final class PostbackParser implements PostbackParserInterface {

  private const MAX_BODY_BYTES = 1048576;

  public function __construct(
    private readonly V1PostbackParser $v1_parser,
    private readonly V2PostbackParser $v2_parser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function parse(
    #[\SensitiveParameter]
    string $raw_body,
  ): ParsedPostback {
    if ($raw_body === '' || strlen($raw_body) > self::MAX_BODY_BYTES) {
      throw InvalidPostbackException::unsupportedSchema();
    }

    try {
      $payload = json_decode($raw_body, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      throw InvalidPostbackException::unsupportedSchema();
    }
    if (!is_array($payload) || array_is_list($payload)) {
      throw InvalidPostbackException::unsupportedSchema();
    }

    if ($this->v2_parser->supports($payload)) {
      return new ParsedPostback(
        PostbackVersion::V2,
        $this->v2_parser->parse($payload),
      );
    }
    if ($this->v1_parser->supports($payload)) {
      return new ParsedPostback(
        PostbackVersion::V1,
        $this->v1_parser->parse($payload),
      );
    }

    throw InvalidPostbackException::unsupportedSchema();
  }

}
