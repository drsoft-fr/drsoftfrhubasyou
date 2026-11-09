<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Integration\Infrastructure\Lock;

use Db;
use DrSoftFr\Module\HubAsYou\Domain\Exception\OrderBusyException;
use DrSoftFr\Module\HubAsYou\Infrastructure\Lock\OrderLock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

/**
 * The lock of an order on the real database (GET_LOCK). Another request is played by a
 * second connection to the database.
 */
final class OrderLockTest extends TestCase
{
    private OrderLock $lock;

    /** No order is needed: the lock only names an order id, far from the real ones */
    private int $orderId;

    private ?Db $other = null;

    protected function setUp(): void
    {
        $this->lock = new OrderLock();
        $this->orderId = random_int(2_000_000_000, 2_100_000_000);
    }

    protected function tearDown(): void
    {
        $this->other?->getValue('SELECT RELEASE_ALL_LOCKS()', false);
        // Closed by its destructor: disconnect() then __destruct() of PrestaShop's DbPDO warns
        $this->other = null;
        Db::getInstance()->getValue('SELECT RELEASE_ALL_LOCKS()', false);
    }

    public function testTheLockIsHeldDuringTheWorkAndReleasedAfter(): void
    {
        $heldDuring = $this->lock->synchronized($this->orderId, fn (): bool => $this->isHeldByThisConnection($this->orderId));

        self::assertTrue($heldDuring);
        self::assertTrue($this->isFree($this->orderId));
    }

    public function testTheSameRequestCanTakeTheLockOfItsOrderAgain(): void
    {
        $result = $this->lock->synchronized($this->orderId, function (): array {
            $inner = $this->lock->synchronized($this->orderId, static fn (): string => 'inner work');

            return [$inner, $this->isHeldByThisConnection($this->orderId)];
        });

        // The inner release does not free the order while the outer work goes on
        self::assertSame(['inner work', true], $result);
        self::assertTrue($this->isFree($this->orderId));
    }

    public function testTheLockIsReleasedWhenTheWorkFails(): void
    {
        try {
            $this->lock->synchronized($this->orderId, static function (): void {
                throw new RuntimeException('The work failed.');
            });
            self::fail('The exception of the work must reach the caller.');
        } catch (RuntimeException $e) {
            self::assertSame('The work failed.', $e->getMessage());
        }

        self::assertTrue($this->isFree($this->orderId));
    }

    public function testAnOrderLockedByAnotherRequestDoesNotBlockAnotherOrder(): void
    {
        $this->lockFromAnotherConnection($this->orderId);

        $start = microtime(true);
        $result = $this->lock->synchronized($this->orderId + 1, static fn (): string => 'done');

        self::assertSame('done', $result);
        self::assertLessThan(2, microtime(true) - $start);
    }

    #[Group('slow')]
    public function testAnOrderLockedByAnotherRequestIsRefusedAfterTheWait(): void
    {
        $this->lockFromAnotherConnection($this->orderId);
        $start = microtime(true);
        $worked = false;

        try {
            $this->lock->synchronized($this->orderId, static function () use (&$worked): void {
                $worked = true;
            });
            self::fail('A locked order must be refused.');
        } catch (OrderBusyException $e) {
            self::assertSame(['order' => $this->orderId], $e->parameters);
        }

        self::assertFalse($worked);
        self::assertGreaterThanOrEqual(OrderLock::TIMEOUT - 1, microtime(true) - $start);
    }

    private function lockFromAnotherConnection(int $orderId): void
    {
        $class = Db::getClass();
        $this->other = new $class(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_);

        self::assertSame(1, (int) $this->other->getValue('SELECT GET_LOCK(' . $this->quotedName($orderId) . ', 0)', false));
    }

    private function isFree(int $orderId): bool
    {
        return (int) Db::getInstance()->getValue('SELECT IS_FREE_LOCK(' . $this->quotedName($orderId) . ')', false) === 1;
    }

    private function isHeldByThisConnection(int $orderId): bool
    {
        $db = Db::getInstance();

        return (int) $db->getValue('SELECT IS_USED_LOCK(' . $this->quotedName($orderId) . ')', false) === (int) $db->getValue('SELECT CONNECTION_ID()', false);
    }

    /**
     * The name the lock gives to an order (unique per database and table prefix).
     */
    private function quotedName(int $orderId): string
    {
        return "'" . (new ReflectionMethod(OrderLock::class, 'getName'))->invoke($this->lock, $orderId) . "'";
    }
}
