<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Application\Query;

use DrSoftFr\Module\HubAsYou\ApiPlatform\ShipmentErrorMessages;
use DrSoftFr\Module\HubAsYou\Application\Query\ListShipments;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentConstraintException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('_PS_VERSION_')) {
    exit;
}

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

    public function testNoParameter(): void
    {
        $query = new ListShipments();

        self::assertNull($query->orderId);
        self::assertNull($query->externalReference);
        self::assertSame(0, $query->offset);
        self::assertSame(ListShipments::DEFAULT_LIMIT, $query->limit);
    }

    public function testOffsetZeroIsAccepted(): void
    {
        self::assertSame(0, (new ListShipments(offset: '0'))->offset);
        self::assertSame(0, (new ListShipments(offset: 0))->offset);
    }

    /**
     * @return array<string, array{array<string, int|string>, string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'order: text' => [['orderId' => 'abc'], 'orderId'],
            'order: zero' => [['orderId' => '0'], 'orderId'],
            'order: negative' => [['orderId' => '-1'], 'orderId'],
            'order: decimal' => [['orderId' => '1.5'], 'orderId'],
            'order: starts like a number' => [['orderId' => '2abc'], 'orderId'],
            'order: empty' => [['orderId' => ''], 'orderId'],
            'order: line break' => [['orderId' => "2\n"], 'orderId'],
            'reference: empty' => [['externalReference' => ''], 'externalReference'],
            'reference: spaces' => [['externalReference' => '  '], 'externalReference'],
            'limit: text' => [['limit' => 'abc'], 'limit'],
            'limit: zero' => [['limit' => '0'], 'limit'],
            'limit: integer zero' => [['limit' => 0], 'limit'],
            'limit: negative' => [['limit' => -5], 'limit'],
            'limit: empty' => [['limit' => ''], 'limit'],
            'offset: text' => [['offset' => 'abc'], 'offset'],
            'offset: negative' => [['offset' => -1], 'offset'],
            'offset: decimal' => [['offset' => '1.5'], 'offset'],
            'offset: empty' => [['offset' => ''], 'offset'],
        ];
    }

    /**
     * @param array<string, int|string> $parameters
     */
    #[DataProvider('refusedProvider')]
    public function testAWrongValueIsRefusedAndTheFieldNamed(array $parameters, string $field): void
    {
        try {
            new ListShipments(...$parameters);
            self::fail('Refusal expected');
        } catch (ShipmentConstraintException $e) {
            self::assertSame(ShipmentConstraintException::INVALID_LIST_PARAMETER, $e->getCode());
            self::assertSame(sprintf('The value of %s is not valid.', $field), ShipmentErrorMessages::for($e));
        }
    }
}
