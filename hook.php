<?php
/**
 * -------------------------------------------------------------------------
 * GLPI Experience Kit plugin for GLPI 11
 * -------------------------------------------------------------------------
 * Install / uninstall routines.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/autoload.php';

/**
 * Creates the plugin's four tables and registers its right and cron task.
 */
function plugin_experiencekit_install()
{
    global $DB;

    $default_charset   = DBConnection::getDefaultCharset();
    $default_collation = DBConnection::getDefaultCollation();
    $default_key_sign  = DBConnection::getDefaultPrimaryKeySignOption();

    $migration = new Migration(PLUGIN_EXPERIENCEKIT_VERSION);

    if (!$DB->tableExists('glpi_plugin_experiencekit_runs')) {
        $query = "CREATE TABLE `glpi_plugin_experiencekit_runs` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `name` varchar(255) DEFAULT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'pending',
            `volume_profile` varchar(20) NOT NULL DEFAULT 'medium',
            `profile_json` mediumtext DEFAULT NULL,
            `current_phase` varchar(40) DEFAULT NULL,
            `seed` int NOT NULL DEFAULT 0,
            `users_id` int {$default_key_sign} NOT NULL DEFAULT 0,
            `notifications_was_enabled` tinyint DEFAULT NULL,
            `notifications_mailing_was_enabled` tinyint DEFAULT NULL,
            `error_message` text DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            `date_completed` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `status` (`status`),
            KEY `users_id` (`users_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query) or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_experiencekit_registry')) {
        $query = "CREATE TABLE `glpi_plugin_experiencekit_registry` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `runs_id` int {$default_key_sign} NOT NULL,
            `itemtype` varchar(100) NOT NULL,
            `items_id` int {$default_key_sign} NOT NULL,
            `phase` varchar(40) NOT NULL,
            `scenario_tag` varchar(60) DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `item_unique` (`itemtype`, `items_id`),
            KEY `runs_id` (`runs_id`),
            KEY `itemtype_phase` (`itemtype`, `phase`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query) or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_experiencekit_phase_progress')) {
        $query = "CREATE TABLE `glpi_plugin_experiencekit_phase_progress` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `runs_id` int {$default_key_sign} NOT NULL,
            `phase` varchar(40) NOT NULL,
            `status` varchar(20) NOT NULL DEFAULT 'pending',
            `total_units` int NOT NULL DEFAULT 0,
            `completed_units` int NOT NULL DEFAULT 0,
            `last_error` text DEFAULT NULL,
            `started_at` timestamp NULL DEFAULT NULL,
            `finished_at` timestamp NULL DEFAULT NULL,
            `last_heartbeat` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `run_phase` (`runs_id`, `phase`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query) or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_experiencekit_healthchecks')) {
        $query = "CREATE TABLE `glpi_plugin_experiencekit_healthchecks` (
            `id` int {$default_key_sign} NOT NULL AUTO_INCREMENT,
            `runs_id` int {$default_key_sign} DEFAULT NULL,
            `check_key` varchar(60) NOT NULL,
            `status` varchar(10) NOT NULL,
            `details_json` mediumtext DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `runs_id` (`runs_id`),
            KEY `check_key_status` (`check_key`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$default_charset} COLLATE={$default_collation} ROW_FORMAT=DYNAMIC";
        $DB->doQuery($query) or die($DB->error());
    }

    $migration->executeMigration();

    // Only profiles that already have config READ|UPDATE (Super-Admin and,
    // by default, Admin) get this right; everyone else gets 0. This tool can
    // generate large volumes of data and purge it, so it must not be broadly
    // granted by default.
    $migration->addRight(PluginExperiencekitRun::$rightname, ALLSTANDARDRIGHT, ['config' => READ | UPDATE]);

    CronTask::Register(
        'PluginExperiencekitRun',
        'ProcessBatch',
        MINUTE_TIMESTAMP,
        [
            'param'   => 200,
            'comment' => 'Advances any in-progress Experience Kit generation run by one bounded batch per phase.',
        ]
    );

    return true;
}

/**
 * Drops the plugin's four tables and removes its right and cron task,
 * leaving no orphaned data in GLPI core tables. Does NOT purge any
 * generated GLPI objects (Users/Tickets/etc.) themselves - use the plugin's
 * own "purge synthetic data" action for that before uninstalling.
 */
function plugin_experiencekit_uninstall()
{
    global $DB;

    $tables = [
        'glpi_plugin_experiencekit_healthchecks',
        'glpi_plugin_experiencekit_phase_progress',
        'glpi_plugin_experiencekit_registry',
        'glpi_plugin_experiencekit_runs',
    ];

    foreach ($tables as $table) {
        $DB->doQuery("DROP TABLE IF EXISTS `{$table}`") or die($DB->error());
    }

    $DB->delete('glpi_profilerights', ['name' => PluginExperiencekitRun::$rightname]);
    CronTask::unregister('experiencekit');

    return true;
}
