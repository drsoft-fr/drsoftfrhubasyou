<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Domain;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\ApiPlatform\ShipmentErrorMessages;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentDates;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class ShipmentDatesTest extends TestCase
{
    private DateTimeImmutable $now;
    private DateTimeImmutable $yesterday;
    private DateTimeImmutable $lastWeek;
    private DateTimeImmutable $tomorrow;

    protected function setUp(): void
    {
        $this->now = new DateTimeImmutable('2026-11-06 12:00:00');
        $this->yesterday = new DateTimeImmutable('2026-11-05 12:00:00');
        $this->lastWeek = new DateTimeImmutable('2026-10-30 12:00:00');
        $this->tomorrow = new DateTimeImmutable('2026-11-07 12:00:00');
    }

    public function testADateNotSentStaysNull(): void
    {
        self::assertNull(ShipmentDates::fromInput('shippedAt', null));
    }

    public function testAValidDateIsRead(): void
    {
        self::assertEquals(new DateTimeImmutable('2026-11-05T10:12:00+01:00'), ShipmentDates::fromInput('shippedAt', '2026-11-05T10:12:00+01:00'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDateProvider(): array
    {
        return [
            'text' => ['pas-une-date'],
            'empty: PHP would read it as now' => [''],
            'spaces' => ['   '],
            'month 13' => ['2026-13-45'],
            'hour 25' => ['2026-11-05T25:00:00+01:00'],
            'a word PHP understands' => ['tomorrow'],
            'no offset' => ['2026-11-05T10:00:00'],
            'no offset, with a space' => ['2026-11-05 10:00:00'],
            'date only' => ['2026-11-05'],
            'February 31: PHP would read March 3' => ['2026-02-31T10:00:00+01:00'],
            'February 29 of a common year' => ['2026-02-29T10:00:00+01:00'],
            'line break after a valid date' => ["2026-11-05T10:00:00+01:00\n"],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validDateProvider(): array
    {
        return [
            'offset' => ['2026-11-05T10:12:00+01:00'],
            'Z' => ['2026-11-05T09:12:00Z'],
            'offset without a colon' => ['2026-11-05T10:12:00+0100'],
            'negative offset' => ['2026-11-05T04:12:00-05:00'],
            'milliseconds' => ['2026-11-05T09:12:00.123Z'],
            'February 29 of a leap year' => ['2024-02-29T10:00:00+01:00'],
        ];
    }

    #[DataProvider('validDateProvider')]
    public function testAValidFormatIsAccepted(string $value): void
    {
        self::assertInstanceOf(DateTimeImmutable::class, ShipmentDates::fromInput('shippedAt', $value));
    }

    public function testADateCanBeOneDayAheadAtMost(): void
    {
        $limit = $this->now->modify('+1 day');

        self::assertEquals([$limit, null], ShipmentDates::resolve(ShipmentStatus::Shipped, $limit, null, null, null, $this->now));

        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::DATE_OUT_OF_RANGE);

        ShipmentDates::resolve(ShipmentStatus::Shipped, $limit->modify('+1 second'), null, null, null, $this->now);
    }

    public function testADateBefore2000IsRefused(): void
    {
        $first = new DateTimeImmutable('2000-01-01T00:00:00+00:00');

        self::assertEquals([$first, null], ShipmentDates::resolve(ShipmentStatus::Shipped, $first, null, null, null, $this->now));

        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::DATE_OUT_OF_RANGE);

        ShipmentDates::resolve(ShipmentStatus::Shipped, $first->modify('-1 second'), null, null, null, $this->now);
    }

    public function testADeliveryDateOutOfRangeIsRefusedAndNamed(): void
    {
        try {
            ShipmentDates::resolve(ShipmentStatus::Delivered, null, new DateTimeImmutable('2099-01-01T10:00:00+01:00'), null, null, $this->now);
            self::fail('Refusal expected');
        } catch (ShipmentConstraintException $e) {
            self::assertSame(ShipmentConstraintException::DATE_OUT_OF_RANGE, $e->getCode());
            self::assertStringStartsWith('deliveredAt cannot be', ShipmentErrorMessages::for($e));
        }
    }

    public function testAnOldSavedDateDoesNotBlockAChange(): void
    {
        $old = new DateTimeImmutable('1995-01-01 00:00:00');

        self::assertEquals([$old, null], ShipmentDates::resolve(ShipmentStatus::InTransit, null, null, $old, null, $this->now));
    }

    #[DataProvider('invalidDateProvider')]
    public function testAnInvalidDateIsRefusedWithoutItsValue(string $value): void
    {
        try {
            ShipmentDates::fromInput('deliveredAt', $value);
            self::fail('Refusal expected');
        } catch (ShipmentConstraintException $e) {
            self::assertSame(ShipmentConstraintException::INVALID_DATE, $e->getCode());
            self::assertSame('deliveredAt must be an ISO 8601 date with its time offset, for example 2026-11-05T10:12:00+01:00.', ShipmentErrorMessages::for($e));
        }
    }

    public function testASavedDateIsRead(): void
    {
        self::assertEquals($this->now, ShipmentDates::fromSaved('2026-11-06 12:00:00'));
        self::assertNull(ShipmentDates::fromSaved(null));
        self::assertNull(ShipmentDates::fromSaved(''));
    }

    public function testNewShippedParcelLeavesNow(): void
    {
        self::assertEquals(
            [$this->now, null],
            ShipmentDates::resolve(ShipmentStatus::Shipped, null, null, null, null, $this->now)
        );
    }

    public function testNewParcelInExceptionHasNoDate(): void
    {
        self::assertSame(
            [null, null],
            ShipmentDates::resolve(ShipmentStatus::Exception, null, null, null, null, $this->now)
        );
    }

    public function testNewDeliveredParcelWithoutDatesLeavesAndArrivesNow(): void
    {
        self::assertEquals(
            [$this->now, $this->now],
            ShipmentDates::resolve(ShipmentStatus::Delivered, null, null, null, null, $this->now)
        );
    }

    public function testParcelDeclaredDeliveredInThePastLeavesThatDayToo(): void
    {
        self::assertEquals(
            [$this->yesterday, $this->yesterday],
            ShipmentDates::resolve(ShipmentStatus::Delivered, null, $this->yesterday, null, null, $this->now)
        );
    }

    public function testParcelDeclaredDeliveredInTheFutureLeavesNow(): void
    {
        self::assertEquals(
            [$this->now, $this->tomorrow],
            ShipmentDates::resolve(ShipmentStatus::Delivered, null, $this->tomorrow, null, null, $this->now)
        );
    }

    public function testSentDatesAreKept(): void
    {
        self::assertEquals(
            [$this->lastWeek, $this->yesterday],
            ShipmentDates::resolve(ShipmentStatus::Delivered, $this->lastWeek, $this->yesterday, null, null, $this->now)
        );
    }

    public function testSentDatesReplaceSavedOnes(): void
    {
        self::assertEquals(
            [$this->lastWeek, $this->yesterday],
            ShipmentDates::resolve(ShipmentStatus::Delivered, $this->lastWeek, $this->yesterday, $this->yesterday, $this->now, $this->now)
        );
    }

    public function testSavedDatesAreNotOverwrittenByTheDefault(): void
    {
        self::assertEquals(
            [$this->lastWeek, $this->yesterday],
            ShipmentDates::resolve(ShipmentStatus::Delivered, null, null, $this->lastWeek, $this->yesterday, $this->now)
        );
    }

    public function testParcelBecomingDeliveredArrivesNow(): void
    {
        self::assertEquals(
            [$this->lastWeek, $this->now],
            ShipmentDates::resolve(ShipmentStatus::Delivered, null, null, $this->lastWeek, null, $this->now)
        );
    }

    public function testParcelLeavingTheDeliveredStateLosesItsDeliveryDate(): void
    {
        self::assertEquals(
            [$this->lastWeek, null],
            ShipmentDates::resolve(ShipmentStatus::InTransit, null, null, $this->lastWeek, $this->yesterday, $this->now)
        );
    }

    public function testParcelInExceptionKeepsItsShippingDate(): void
    {
        self::assertEquals(
            [$this->lastWeek, null],
            ShipmentDates::resolve(ShipmentStatus::Exception, null, null, $this->lastWeek, null, $this->now)
        );
    }

    public function testDeliveryAndShippingAtTheSameTimeAreAccepted(): void
    {
        self::assertEquals(
            [$this->yesterday, $this->yesterday],
            ShipmentDates::resolve(ShipmentStatus::Delivered, $this->yesterday, $this->yesterday, null, null, $this->now)
        );
    }

    public function testDeliveryDateIsRefusedForAParcelNotDelivered(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::INVALID_DATES);
        $this->expectExceptionMessage('deliveredAt can only be sent for a delivered parcel (status "delivered").');

        ShipmentDates::resolve(ShipmentStatus::InTransit, null, $this->yesterday, null, null, $this->now);
    }

    public function testDeliveryBeforeTheSentShippingIsRefused(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::INVALID_DATES);

        ShipmentDates::resolve(ShipmentStatus::Delivered, $this->yesterday, $this->lastWeek, null, null, $this->now);
    }

    public function testDeliveryBeforeTheSavedShippingIsRefused(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::INVALID_DATES);

        ShipmentDates::resolve(ShipmentStatus::Delivered, null, $this->lastWeek, $this->yesterday, null, $this->now);
    }

    public function testShippingAfterTheSavedDeliveryIsRefused(): void
    {
        $this->expectException(ShipmentConstraintException::class);
        $this->expectExceptionCode(ShipmentConstraintException::INVALID_DATES);

        ShipmentDates::resolve(ShipmentStatus::Delivered, $this->now, null, null, $this->yesterday, $this->now);
    }
}
