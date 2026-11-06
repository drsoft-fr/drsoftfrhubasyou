<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Application\Query;

use DrSoftFr\Module\HubAsYou\Application\Query\ListShipments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListShipmentsTest extends TestCase
{
    /**
     * @return array<string, array{int|string|null, int}>
     */
    public static function limitProvider(): array
    {
        return [
            'as given' => [5, 5],
            'as a string' => ['5', 5],
            'the highest allowed' => [100, 100],
            'over the highest allowed' => [500, 100],
            'none' => [null, ListShipments::DEFAULT_LIMIT],
            'zero' => [0, ListShipments::DEFAULT_LIMIT],
            'negative' => [-1, ListShipments::DEFAULT_LIMIT],
            'not a number' => ['abc', ListShipments::DEFAULT_LIMIT],
        ];
    }

    #[DataProvider('limitProvider')]
    public function testLimit(int|string|null $limit, int $expected): void
    {
        self::assertSame($expected, (new ListShipments(limit: $limit))->limit);
    }

    public function testFilters(): void
    {
        $query = new ListShipments('45', ' HAY-1 ', '20');

        self::assertSame(45, $query->orderId);
        self::assertSame('HAY-1', $query->externalReference);
        self::assertSame(20, $query->offset);
    }

    public function testEmptyFiltersAreIgnored(): void
    {
        $query = new ListShipments('abc', '  ', -5);

        self::assertNull($query->orderId);
        self::assertNull($query->externalReference);
        self::assertSame(0, $query->offset);
    }
}
