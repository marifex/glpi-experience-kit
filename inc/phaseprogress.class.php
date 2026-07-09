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
 * Resumable, chunked progress tracking per run+phase. Replaces the
 * hand-rolled `state_phaseN.json` files from the original demo-data scripts
 * with a DB-backed equivalent the admin UI can poll and a crashed/stalled
 * run can resume from.
 */
class PluginExperiencekitPhaseProgress extends CommonDBTM
{
    public static $rightname = 'plugin_experiencekit_use';

    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_DONE    = 'done';
    public const STATUS_FAILED  = 'failed';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_experiencekit_phase_progress';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Phase progress', 'Phase progress', $nb, 'experiencekit');
    }
}
