<?php
/**
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Kiyoh $module
 * @return bool
 */
function upgrade_module_1_4_2($module)
{
    return $module->upgradeRemoveStagingMobileUrls();
}