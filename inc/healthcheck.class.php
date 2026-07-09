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
 * Result log for health-check runs (e.g. the requester-actor-link coverage
 * check from the original demo dataset's §5 regression). Health checks can
 * run standalone against the whole environment, not just a specific run, so
 * runs_id is nullable.
 */
class PluginExperiencekitHealthcheck extends CommonDBTM
{
    public static $rightname = 'plugin_experiencekit_use';

    public const STATUS_PASS = 'pass';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_experiencekit_healthchecks';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Health check result', 'Health check results', $nb, 'experiencekit');
    }
}
