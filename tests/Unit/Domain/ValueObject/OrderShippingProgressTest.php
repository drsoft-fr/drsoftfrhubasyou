<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Domain\ValueObject;

use DrSoftFr\Module\HubAsYou\Domain\ValueObject\OrderShippingProgress;
use DrSoftFr\Module\HubAsYou\Domain\ValueObject\ShipmentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderShippingProgressTest extends TestCase
{
    /**
     * @return array{ordered: int, refunded: int, returned: int, virtual: bool}
     */
    private static function line(int $ordered, int $refunded = 0, int $returned = 0): array
    {
        return ['ordered' => $ordered, 'refunded' => $refunded, 'returned' => $returned, 'virtual' => false];
    }

    /**
     * @return array<string, array{array<int, array{ordered: int, refunded: int, returned: int, virtual: bool}>, array<int, int>, ShipmentStatus[], OrderShippingProgress}>
     */
    public static function progressProvider(): array
    {
        return [
            'no parcel' => [
                [3 => self::line(1)], [], [], OrderShippingProgress::NotShipped,
            ],
            'only a parcel in exception' => [
                [3 => self::line(1)], [], [ShipmentStatus::Exception], OrderShippingProgress::NotShipped,
            ],
            'entirely refunded, parcel in exception' => [
                [3 => self::line(1, refunded: 1)], [], [ShipmentStatus::Exception], OrderShippingProgress::NotShipped,
            ],
            'a parcel shipped' => [
                [3 => self::line(1)], [], [ShipmentStatus::Shipped], OrderShippingProgress::Shipped,
            ],
            'a parcel in transit' => [
                [3 => self::line(1)], [], [ShipmentStatus::InTransit], OrderShippingProgress::Shipped,
            ],
            'delivered, but a unit is not in a parcel yet' => [
                [3 => self::line(2)], [3 => 1], [ShipmentStatus::Delivered], OrderShippingProgress::Shipped,
            ],
            'delivered, the other parcel in exception and still due' => [
                [3 => self::line(1), 4 => self::line(1)], [3 => 1], [ShipmentStatus::Delivered, ShipmentStatus::Exception], OrderShippingProgress::Shipped,
            ],
            'everything delivered' => [
                [3 => self::line(2)], [3 => 2], [ShipmentStatus::Delivered], OrderShippingProgress::Delivered,
            ],
            '2 delivered, the 3rd refunded' => [
                [3 => self::line(3, refunded: 1)], [3 => 2], [ShipmentStatus::Delivered], OrderShippingProgress::Delivered,
            ],
            'lost parcel set in exception and refunded' => [
                [3 => self::line(1), 4 => self::line(1, refunded: 1)], [3 => 1], [ShipmentStatus::Delivered, ShipmentStatus::Exception], OrderShippingProgress::Delivered,
            ],
            'a parcel still travelling holds the order back, even refunded' => [
                [3 => self::line(1), 4 => self::line(1, refunded: 1)], [3 => 1], [ShipmentStatus::Delivered, ShipmentStatus::InTransit], OrderShippingProgress::Shipped,
            ],
            'a parcel just shipped holds the order back, even returned' => [
                [3 => self::line(2, returned: 1)], [3 => 1], [ShipmentStatus::Delivered, ShipmentStatus::Shipped], OrderShippingProgress::Shipped,
            ],
        ];
    }

    /**
     * @param array<int, array{ordered: int, refunded: int, returned: int, virtual: bool}> $orderLines
     * @param array<int, int> $delivered
     * @param ShipmentStatus[] $statuses
     */
    #[DataProvider('progressProvider')]
    public function testProgress(array $orderLines, array $delivered, array $statuses, OrderShippingProgress $expected): void
    {
        self::assertSame($expected, OrderShippingProgress::of($orderLines, $delivered, $statuses));
    }
}
