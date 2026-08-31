<?php

declare(strict_types=1);

namespace Drupal\Tests\commerce_novapay\Unit\Postback;

use Drupal\commerce_novapay\Postback\NovaPayStatus;
use Drupal\commerce_novapay\Postback\PaymentStatusMapper;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\state_machine\Plugin\Field\FieldType\StateItemInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests NovaPay-to-Commerce payment workflow mapping.
 */
#[CoversClass(PaymentStatusMapper::class)]
#[Group('commerce_novapay')]
final class PaymentStatusMapperTest extends TestCase {

  /**
   * Tests documented transitions and remote-state persistence.
   */
  #[DataProvider('transitionProvider')]
  public function testAppliesStatus(
    string $current_state,
    NovaPayStatus $remote_status,
    ?string $transition_id,
  ): void {
    $payment = $this->createMock(PaymentInterface::class);
    $state = $this->createMock(StateItemInterface::class);
    $state->method('getId')->willReturn($current_state);
    $payment->method('getState')->willReturn($state);
    if ($transition_id === NULL) {
      $state->expects(self::never())->method('applyTransitionById');
      $payment->expects(self::never())->method('getOrder');
    }
    else {
      $order = $this->createMock(OrderInterface::class);
      $payment->expects(self::once())->method('getOrder')
        ->willReturn($order);
      $order->expects(self::once())->method('save');
      $state->expects(self::once())->method('applyTransitionById')
        ->with($transition_id);
    }
    if ($current_state === 'completed' && $remote_status === NovaPayStatus::Voided) {
      $amount = new Price('30', 'UAH');
      $payment->method('getAmount')->willReturn($amount);
      $payment->expects(self::once())->method('setRefundedAmount')
        ->with($amount)->willReturnSelf();
    }
    $payment->expects(self::once())->method('setRemoteState')
      ->with($remote_status->value)->willReturnSelf();
    $payment->expects(self::once())->method('save');

    self::assertSame(
      $transition_id !== NULL || $remote_status === NovaPayStatus::Processing,
      (new PaymentStatusMapper())->apply($payment, $remote_status),
    );
  }

  /**
   * Tests that a transition cannot partially mutate an orphaned payment.
   */
  public function testTransitionRequiresParentOrder(): void {
    $payment = $this->createMock(PaymentInterface::class);
    $state = $this->createMock(StateItemInterface::class);
    $state->method('getId')->willReturn('pending');
    $payment->method('getState')->willReturn($state);
    $payment->expects(self::once())->method('getOrder')->willReturn(NULL);
    $state->expects(self::never())->method('applyTransitionById');
    $payment->expects(self::never())->method('setRemoteState');
    $payment->expects(self::never())->method('save');

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('no parent order');
    (new PaymentStatusMapper())->apply($payment, NovaPayStatus::Paid);
  }

  /**
   * Provides documented remote/local mapping pairs.
   *
   * @return iterable<string, array{string, \Drupal\commerce_novapay\Postback\NovaPayStatus, ?string}>
   *   Current state, remote status, and expected transition.
   */
  public static function transitionProvider(): iterable {
    yield 'holded authorization' => [
      'pending', NovaPayStatus::Holded, 'authorize',
    ];
    yield 'direct paid' => [
      'pending', NovaPayStatus::Paid, 'authorize_capture',
    ];
    yield 'hold confirmed' => [
      'authorization', NovaPayStatus::HoldConfirmed, 'capture',
    ];
    yield 'authorization voided' => [
      'authorization', NovaPayStatus::Voided, 'void',
    ];
    yield 'completed refunded' => [
      'completed', NovaPayStatus::Voided, 'refund',
    ];
    yield 'expired' => ['pending', NovaPayStatus::Expired, 'expire'];
    yield 'failed' => ['pending', NovaPayStatus::Failed, 'fail'];
    yield 'failed payment retried' => [
      'failed', NovaPayStatus::Processing, 'retry',
    ];
    yield 'failed direct payment recovered' => [
      'failed', NovaPayStatus::Paid, 'retry_authorize_capture',
    ];
    yield 'failed hold payment recovered' => [
      'failed', NovaPayStatus::Holded, 'retry_authorize',
    ];
    yield 'failed hold payment captured' => [
      'failed', NovaPayStatus::HoldConfirmed, 'retry_authorize_capture',
    ];
    yield 'processing remains pending' => [
      'pending', NovaPayStatus::Processing, NULL,
    ];
  }

  /**
   * Tests that an initial status cannot reactivate a failed payment.
   */
  public function testCreatedCannotRecoverFailedPayment(): void {
    $payment = $this->createMock(PaymentInterface::class);
    $state = $this->createMock(StateItemInterface::class);
    $state->method('getId')->willReturn('failed');
    $payment->method('getState')->willReturn($state);
    $state->expects(self::never())->method('applyTransitionById');
    $payment->expects(self::never())->method('setRemoteState');
    $payment->expects(self::never())->method('save');

    self::assertFalse(
      (new PaymentStatusMapper())->apply($payment, NovaPayStatus::Created),
    );
  }

  /**
   * Tests that an older intermediate event cannot overwrite a final state.
   */
  public function testProcessingCannotRollBackCompletedPayment(): void {
    $payment = $this->createMock(PaymentInterface::class);
    $state = $this->createMock(StateItemInterface::class);
    $state->method('getId')->willReturn('completed');
    $payment->method('getState')->willReturn($state);
    $state->expects(self::never())->method('applyTransitionById');
    $payment->expects(self::never())->method('setRemoteState');
    $payment->expects(self::never())->method('save');

    self::assertFalse(
      (new PaymentStatusMapper())->apply(
        $payment,
        NovaPayStatus::Processing,
      ),
    );
  }

  /**
   * Tests that paid cannot recover an irreversible Commerce payment state.
   */
  #[DataProvider('irreversibleStateProvider')]
  public function testPaidCannotRecoverIrreversibleState(string $state_id): void {
    $payment = $this->createMock(PaymentInterface::class);
    $state = $this->createMock(StateItemInterface::class);
    $state->method('getId')->willReturn($state_id);
    $payment->method('getState')->willReturn($state);
    $state->expects(self::never())->method('applyTransitionById');
    $payment->expects(self::never())->method('setRemoteState');
    $payment->expects(self::never())->method('save');

    self::assertFalse(
      (new PaymentStatusMapper())->apply($payment, NovaPayStatus::Paid),
    );
  }

  /**
   * Provides states that a later paid event must never recover.
   *
   * @return iterable<string, array{string}>
   *   Irreversible Commerce state IDs.
   */
  public static function irreversibleStateProvider(): iterable {
    yield 'expired payment' => ['expired'];
    yield 'voided authorization' => ['authorization_voided'];
    yield 'refunded payment' => ['refunded'];
  }

}
