<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Domain;

use DateTimeImmutable;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use DrSoftFr\Module\HubAsYou\Domain\ShipmentDates;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use PHPUnit\Framework\TestCase;

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
