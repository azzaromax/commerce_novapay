<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Asset;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the packaged official NovaPay logo remains passive and unchanged.
 */
#[Group('commerce_novapay')]
final class NovaPayLogoTest extends TestCase {

  private const OFFICIAL_LOGO_SHA256 =
    '84639212ebd6440fa7785b5ea2120f6b2b65e053253ad6627a9d8b1f6ecca1b6';

  /**
   * Tests the approved asset checksum and expected geometry.
   */
  #[Test]
  public function officialLogoIsUnmodified(): void {
    $path = $this->logoPath();
    self::assertFileExists($path);
    self::assertSame(self::OFFICIAL_LOGO_SHA256, hash_file('sha256', $path));

    $document = $this->loadLogo($path);
    self::assertSame('svg', $document->documentElement->localName);
    self::assertSame('124', $document->documentElement->getAttribute('width'));
    self::assertSame('25', $document->documentElement->getAttribute('height'));
    self::assertSame(
      '0 0 124 25',
      $document->documentElement->getAttribute('viewBox'),
    );
  }

  /**
   * Tests that the SVG contains no executable or external content.
   */
  #[Test]
  public function officialLogoContainsNoActiveContent(): void {
    $path = $this->logoPath();
    $contents = file_get_contents($path);
    self::assertIsString($contents);
    self::assertDoesNotMatchRegularExpression(
      '/<!DOCTYPE|<!ENTITY/i',
      $contents,
    );

    $document = $this->loadLogo($path);
    $xpath = new \DOMXPath($document);
    $elements = $xpath->query('//*');
    self::assertNotFalse($elements);
    $blocked_elements = [
      'animate',
      'animatemotion',
      'animatetransform',
      'audio',
      'discard',
      'embed',
      'foreignobject',
      'handler',
      'iframe',
      'object',
      'script',
      'set',
      'style',
      'video',
    ];

    foreach ($elements as $element) {
      self::assertInstanceOf(\DOMElement::class, $element);
      self::assertNotContains(
        strtolower($element->localName),
        $blocked_elements,
      );
      foreach ($element->attributes as $attribute) {
        $name = strtolower($attribute->localName);
        self::assertFalse(str_starts_with($name, 'on'));
        if (in_array($name, ['href', 'src'], TRUE)) {
          self::assertStringStartsWith('#', trim($attribute->value));
        }
        if ($name === 'style') {
          $this->assertPassiveStyle($attribute->value);
        }
      }
    }
  }

  /**
   * Loads the logo with network access disabled.
   */
  private function loadLogo(string $path): \DOMDocument {
    $contents = file_get_contents($path);
    self::assertIsString($contents);
    $document = new \DOMDocument();
    $previous = libxml_use_internal_errors(TRUE);
    try {
      self::assertTrue($document->loadXML(
        $contents,
        LIBXML_NONET | LIBXML_NOBLANKS,
      ));
    }
    finally {
      libxml_clear_errors();
      libxml_use_internal_errors($previous);
    }

    return $document;
  }

  /**
   * Verifies CSS embedded in the SVG references only local fragments.
   */
  private function assertPassiveStyle(string $style): void {
    self::assertDoesNotMatchRegularExpression(
      '/@import|expression\s*\(|javascript:/i',
      $style,
    );
    preg_match_all('/url\(([^)]+)\)/i', $style, $matches);
    foreach ($matches[1] as $target) {
      self::assertStringStartsWith(
        '#',
        trim($target, " \t\n\r\0\x0B'\""),
      );
    }
  }

  /**
   * Gets the packaged logo path.
   */
  private function logoPath(): string {
    return dirname(__DIR__, 4) . '/assets/images/logo.svg';
  }

}
