<?php
/**
 * -------------------------------------------------------------------------
 * GLPI Experience Kit plugin for GLPI 11
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

/**
 * Tracks every GLPI object this plugin has ever created (one row per
 * itemtype/items_id pair, tied to the run that created it). This is the
 * plugin's safe-purge mechanism: purge only ever deletes rows that appear
 * here, so records this plugin did not create (including any pre-existing
 * human-created data) can never be touched by a purge.
 */
class PluginExperiencekitRegistry extends CommonDBTM
{
    public static $rightname = 'plugin_experiencekit_use';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_experiencekit_registry';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Generated record', 'Generated records', $nb, 'experiencekit');
    }
}
