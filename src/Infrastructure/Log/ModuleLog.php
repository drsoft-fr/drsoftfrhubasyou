<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Log;

use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use PrestaShopLogger;
use Throwable;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Error and warning lines of the module in the PrestaShop logs (Advanced parameters › Logs),
 * attached to the order when there is one. If the line cannot be written there (the
 * database itself is failing), it goes to the PHP error log instead: never lost silently.
 */
final class ModuleLog
{
    private const SEVERITY_WARNING = 2;
    private const SEVERITY_ERROR = 3;

    public static function error(string $message, int $orderId = 0): void
    {
        self::write(self::SEVERITY_ERROR, $message, $orderId);
    }

    public static function warning(string $message, int $orderId = 0): void
    {
        self::write(self::SEVERITY_WARNING, $message, $orderId);
    }

    /**
     * The message of an exception and of the exceptions that caused it, for example
     * "The e-mail was not sent. - caused by: Connection refused".
     */
    public static function describe(Throwable $t): string
    {
        $messages = [];

        for ($e = $t; $e; $e = $e->getPrevious()) {
            $messages[] = $e->getMessage();
        }

        return implode(' - caused by: ', array_unique(array_filter($messages)));
    }

    private static function write(int $severity, string $message, int $orderId): void
    {
        $message = Settings::MODULE_NAME . ' - ' . $message;

        try {
            // The same failure can happen again on the next save: it is written again
            if (PrestaShopLogger::addLog($message, $severity, null, $orderId ? 'Order' : null, $orderId ?: null, true)) {
                return;
            }
        } catch (Throwable) {
            // The PHP error log below
        }

        // Not written (database failing, or severity below the minimum level kept in the database)
        error_log($message);
    }
}
