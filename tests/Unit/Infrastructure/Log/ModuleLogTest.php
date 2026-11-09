<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Tests\Unit\Infrastructure\Log;

use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ModuleLog;
use PHPUnit\Framework\TestCase;

if (!defined('_PS_VERSION_')) {
    exit;
}

final class ModuleLogTest extends TestCase
{
    public function testDescribeGivesTheCauses(): void
    {
        $t = new \LogicException('The e-mail could not be sent.', 0, new \RuntimeException('Connection refused'));

        self::assertSame('The e-mail could not be sent. - caused by: Connection refused', ModuleLog::describe($t));
    }

    public function testDescribeSkipsEmptyAndRepeatedMessages(): void
    {
        $t = new \LogicException('Saved.', 0, new \RuntimeException('', 0, new \RuntimeException('Saved.')));

        self::assertSame('Saved.', ModuleLog::describe($t));
    }
}
