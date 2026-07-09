<?php
/**
 * -------------------------------------------------------------------------
 * GLPI Experience Kit plugin for GLPI 11
 * -------------------------------------------------------------------------
 * Main admin screen (Setup > Plugins gear icon, and the "Experience Kit"
 * entry under Tools). Generate/Runs/Health Check/Purge tabs land here in
 * later build steps; for now this confirms the plugin is installed and
 * active, and that the current user holds the plugin's right.
 */

// NOTE: intentionally not the usual relative '../../../inc/includes.php'.
// This plugin's canonical files live in a OneDrive-backed repo and are
// reached via a Windows directory junction at glpi/plugins/experiencekit.
// PHP resolves __DIR__/__FILE__ for junctioned paths to the physical
// target directory, so a hop-count-based relative include silently
// breaks. GLPI_ROOT is defined independently by GLPI core's own
// src/autoload/constants.php (a real, non-junctioned path) and is
// already available by the time routing reaches this legacy file, so
// anchoring on it is reliable regardless of how this file was reached.
require_once GLPI_ROOT . '/inc/includes.php';

Session::checkRight(PluginExperiencekitRun::$rightname, READ);

Html::header(
    PluginExperiencekitRun::getTypeName(2),
    '',
    'tools',
    'PluginExperiencekitRun'
);

echo "<div class='center'>";
echo "<h2>" . htmlescape(__('GLPI Experience Kit', 'experiencekit')) . "</h2>";
echo "<p>" . htmlescape(__('The plugin is installed and active.', 'experiencekit')) . "</p>";
echo "</div>";

Html::footer();
