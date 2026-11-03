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

        if (!Settings::install()) {
            throw new Exception('An error occurred when saving the settings of the module.');
        }
    }

    /**
     * The parcels are deleted with the module.
     *
     * @throws Exception
     */
    public function uninstall(): void
    {
        $this->executeSqlFile(self::SQL_DIR . 'uninstall.sql');

        if (!Settings::uninstall()) {
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

        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if (!Db::getInstance()->execute($statement)) {
                throw new Exception(sprintf('SQL error in %s: %s', basename($file), Db::getInstance()->getMsgError()));
            }
        }
    }
}
