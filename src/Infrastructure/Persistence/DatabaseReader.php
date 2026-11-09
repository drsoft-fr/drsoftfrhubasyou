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

use Db;
use DbQuery;
use DrSoftFr\Module\HubAsYou\Domain\Exception\ShipmentException;
use Throwable;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Every read of the module's repositories: a failed read is an error, never "nothing found",
 * and the error of the database is in the logs (DatabaseError).
 *
 * With debug disabled, PrestaShop's Db reports a failed read as false, which getRow() and
 * getValue() also return for "no row". Without this class, an order could be refused as
 * unknown because its table could not be read. executeS() tells them apart: false on
 * failure, an array otherwise. No query cache: the module checks quantities and states
 * from what it reads.
 */
final class DatabaseReader
{
    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ShipmentException The database could not be read
     */
    public static function rows(DbQuery $query): array
    {
        try {
            $rows = Db::getInstance()->executeS($query, true, false);
        } catch (Throwable $t) {
            throw DatabaseError::exception('The database could not be read.', $t);
        }

        if (!is_array($rows)) {
            throw DatabaseError::exception('The database could not be read.');
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null null when there is no row
     *
     * @throws ShipmentException The database could not be read
     */
    public static function row(DbQuery $query): ?array
    {
        return self::rows($query->limit(1))[0] ?? null;
    }

    /**
     * First column of the first row.
     *
     * @return mixed null when there is no row
     *
     * @throws ShipmentException The database could not be read
     */
    public static function value(DbQuery $query): mixed
    {
        $row = self::row($query);

        return $row === null ? null : reset($row);
    }
}
