<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Persistence;

use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use DrSoftFr\Module\HubAsYou\Infrastructure\Log\ModuleLog;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Every failed read or write of the module's database goes through here: the error the
 * database gave is written to the logs, so no caller can forget it.
 *
 * The exception returned carries a generic message: the Admin API answers 500 without it
 * (ShipmentErrorMessages), the back office shows the generic message. With debug disabled,
 * PrestaShop's Db only answers false and keeps the error: it is read at once, before
 * another query clears it.
 *
 * The logs are a table of the same database: a line written during a transaction would be
 * undone by its ROLLBACK. During transaction(), the lines wait and are written after it.
 */
final class DatabaseError
{
    private static int $openTransactions = 0;

    /** @var string[] */
    private static array $waitingLines = [];

    /**
     * @param \Throwable|null $previous The exception thrown by PrestaShop (debug mode) or by the caller
     */
    public static function exception(string $message, ?\Throwable $previous = null): ShipmentException
    {
        $detail = self::log($message, $previous);

        return new ShipmentException($message, 0, new \RuntimeException($detail, 0, $previous));
    }

    /**
     * Writes the error to the logs and returns what the database said.
     */
    public static function log(string $message, ?\Throwable $previous = null): string
    {
        $db = \Db::getInstance();
        $number = (int) $db->getNumberError();
        $detail = $number !== 0
            ? sprintf('%s (%d)', $db->getMsgError(), $number)
            : ($previous ? $previous->getMessage() : 'no error given by the database');
        $line = sprintf('database error - %s - %s', $message, $detail);

        if (self::$openTransactions > 0) {
            self::$waitingLines[] = $line;
        } else {
            ModuleLog::error($line);
        }

        return $detail;
    }

    /**
     * Runs one write; false or an exception is a database error.
     *
     * @param callable(\Db): bool $write
     *
     * @throws ShipmentException
     */
    public static function write(string $message, callable $write): void
    {
        try {
            $written = $write(\Db::getInstance());
        } catch (\Throwable $t) {
            throw self::exception($message, $t);
        }

        if ($written === false) {
            throw self::exception($message);
        }
    }

    /**
     * Runs $work in a transaction: everything is written, or nothing. In debug mode PrestaShop
     * throws on a database error, otherwise it answers false: $work throws in both cases.
     *
     * @template T
     *
     * @param callable(\Db): T $work
     * @param (callable(\Db): ?ShipmentException)|null $refusal A refusal to answer instead of a database error
     *                                                          (read before the ROLLBACK, which clears the error)
     *
     * @return T
     *
     * @throws ShipmentException
     */
    public static function transaction(string $failure, callable $work, ?callable $refusal = null): mixed
    {
        $db = \Db::getInstance();
        ++self::$openTransactions;

        try {
            if (!$db->execute('START TRANSACTION', false)) {
                throw new \RuntimeException('The transaction could not be started.');
            }

            $result = $work($db);

            if (!$db->execute('COMMIT', false)) {
                throw new \RuntimeException('The transaction could not be committed.');
            }

            return $result;
        } catch (\Throwable $t) {
            if (!$t instanceof ShipmentException) {
                $t = ($refusal ? $refusal($db) : null) ?? self::exception($failure, $t);
            }

            try {
                if (!$db->execute('ROLLBACK', false)) {
                    self::log('The transaction could not be rolled back.');
                }
            } catch (\Throwable $rollback) {
                self::log('The transaction could not be rolled back.', $rollback);
            }

            throw $t;
        } finally {
            if (--self::$openTransactions === 0) {
                $lines = self::$waitingLines;
                self::$waitingLines = [];

                foreach ($lines as $line) {
                    ModuleLog::error($line);
                }
            }
        }
    }
}
