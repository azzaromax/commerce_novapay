<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests exact decimal item-level refund calculations in a browser.
 */
#[Group('commerce_novapay')]
final class RefundCalculatorTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   *
   * @var string[]
   */
  protected static $modules = ['commerce_novapay_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests line totals, aggregate total, and invalid-input fallback.
   */
  public function testExactDecimalCalculation(): void {
    $this->drupalGet('/commerce-novapay-test/refund-calculator');
    $page = $this->getSession()->getPage();
    $inputs = $page->findAll('css', '.commerce-novapay-refund-quantity');
    self::assertCount(2, $inputs);

    $this->getSession()->executeScript(
      "const inputs = document.querySelectorAll('.commerce-novapay-refund-quantity'); inputs[0].value = '2'; inputs[0].dispatchEvent(new Event('input', {bubbles: true})); inputs[1].value = '0.5'; inputs[1].dispatchEvent(new Event('input', {bubbles: true}));",
    );

    $this->assertJsCondition(
      "document.querySelector('.commerce-novapay-refund-total').textContent === '22.2 UAH'",
      5000,
    );
    self::assertSame(
      '20.5 UAH',
      $page->find('css', '[data-test-item="11"]')?->getText(),
    );
    self::assertSame(
      '1.7 UAH',
      $page->find('css', '[data-test-item="12"]')?->getText(),
    );

    $this->getSession()->executeScript(
      "const input = document.querySelector('.commerce-novapay-refund-quantity'); input.value = 'invalid'; input.dispatchEvent(new Event('input', {bubbles: true}));",
    );
    $this->assertJsCondition(
      "document.querySelector('.commerce-novapay-refund-total').textContent === '1.7 UAH'",
      5000,
    );
  }

}
