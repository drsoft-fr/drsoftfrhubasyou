<?php

declare(strict_types=1);

namespace DrSoftFr\Module\HubAsYou\Infrastructure\Install;

use Db;
use DrSoftFr\Module\HubAsYou\Infrastructure\Configuration\Settings;
use Exception;
use Module;

/**
 * Tables, hooks and settings of the module.
 */
final class Installer
{
    public const HOOKS = [
        'displayOrderDetail',
        'displayAdminOrderMain',
        'actionObjectOrderDetailUpdateAfter',
    ];

    private const SQL_DIR = __DIR__ . '/Resources/sql/';

    /**
     * @throws Exception
     */
    public function install(Module $module): void
    {
        $this->executeSqlFile(self::SQL_DIR . 'install.sql');

        if (!$module->registerHook(self::HOOKS)) {
            throw new Exception('An error occurred when registering the hooks of the module.');
        }

        if (!(new Settings())->install()) {
            throw new Exception('An error occurred when saving the settings of the module.');
        }
    }

    /**
     * The parcels are deleted with the module, except when it is being reset: "Reset" in
     * the back office is an uninstallation followed by an installation.
     *
     * @throws Exception
     */
    public function uninstall(bool $keepParcels = false): void
    {
        if (!$keepParcels) {
            $this->executeSqlFile(self::SQL_DIR . 'uninstall.sql');
        }

        if (!(new Settings())->uninstall()) {
            throw new Exception('An error occurred when deleting the settings of the module.');
        }
    }

    /**
     * @throws Exception
     */
    private function executeSqlFile(string $file): void
    {
        $sql = file_get_contents($file);

        if ($sql === false) {
            throw new Exception(sprintf('Unable to read %s.', basename($file)));
        }

        $sql = str_replace(['_DB_PREFIX_', '_MYSQL_ENGINE_'], [_DB_PREFIX_, _MYSQL_ENGINE_], $sql);

        foreach (self::splitStatements($sql) as $statement) {
            if (!Db::getInstance()->execute($statement)) {
                throw new Exception(sprintf('SQL error in %s: %s', basename($file), Db::getInstance()->getMsgError()));
            }
        }
    }

    /**
     * Statements of a SQL file: without its comment lines, each one ending with a semicolon
     * at the end of a line (a semicolon inside a value does not split it).
     *
     * @return string[]
     */
    public static function splitStatements(string $sql): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        return array_values(array_filter(array_map('trim', (array) preg_split('/;\s*$/m', $sql))));
    }
}
