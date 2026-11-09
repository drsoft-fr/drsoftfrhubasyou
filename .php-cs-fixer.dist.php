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
 * PrestaShop's coding standard, the one its module validator checks (make cs, make cs-check).
 * Read by PHP CS Fixer outside PrestaShop: no _PS_VERSION_ guard here.
 *
 * One rule is turned off, as PrestaShop does in its own code: blank_line_after_opening_tag.
 * The license comment follows <?php directly, like in PrestaShop's core and native modules,
 * because the validator's license check refuses a blank line before it.
 */

$config = new class extends PrestaShop\CodingStandards\CsFixer\Config {
    public function getRules(): array
    {
        // After PrestaShop's rules: the later entry wins over the @Symfony set
        return array_merge(parent::getRules(), ['blank_line_after_opening_tag' => false]);
    }
};
$config
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache')
    ->getFinder()
    ->in(__DIR__)
    ->exclude(['vendor', 'dist'])
    ->ignoreDotFiles(false)
    ->notPath('#^\.(git|idea|claude)/#');

return $config;
