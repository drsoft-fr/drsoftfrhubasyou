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
 * Unit tests run without PrestaShop. Every PHP file of the module stops at once when
 * _PS_VERSION_ is not defined (rule of PrestaShop's module validator): it is defined
 * here, with a value that is not a version, so that the classes can be loaded.
 */
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', 'unit-tests');
}

require_once __DIR__ . '/../../vendor/autoload.php';
