<?php
/**
 * drSoft.fr HubAsYou
 *
 * @author    drSoft.fr <contact@drsoft.fr>
 * @copyright drSoft.fr
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

/*
 * Integration tests: run inside a PrestaShop shop where the module is installed, on its
 * real database. Each test creates a fake order (reference HAYTEST, no customer) and
 * deletes it afterwards with everything it produced. No e-mail is sent and no order
 * state is written through PrestaShop: the tests replace both by recording doubles.
 *
 * Run them on a development shop, never on a production one.
 *
 * PrestaShop is looked for 4 folders up (modules/<module>/tests/Integration), or in
 * the folder given by the environment variable PS_ROOT_DIR.
 */

$psRoot = getenv('PS_ROOT_DIR') ?: dirname(__DIR__, 4);

if (!is_file($psRoot . '/config/config.inc.php')) {
    fwrite(STDERR, "PrestaShop not found in $psRoot: set PS_ROOT_DIR to its folder.\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once $psRoot . '/config/config.inc.php';

if (!Module::isInstalled('drsoftfrhubasyou')) {
    fwrite(STDERR, "The module drsoftfrhubasyou is not installed in this shop.\n");
    exit(1);
}

foreach (['TestOrder', 'RecordingMailer', 'RecordingStateWriter', 'IntegrationTestCase'] as $class) {
    require_once __DIR__ . '/Support/' . $class . '.php';
}
