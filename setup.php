<?php
/**
 * -------------------------------------------------------------------------
 * GLPI Experience Kit plugin for GLPI 11
 * -------------------------------------------------------------------------
 * Registration, version declaration, and hook wiring.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access this file directly");
}

require_once __DIR__ . '/autoload.php';

define('PLUGIN_EXPERIENCEKIT_VERSION', '1.0.1');
define('PLUGIN_EXPERIENCEKIT_MIN_GLPI_VERSION', '11.0');
define('PLUGIN_EXPERIENCEKIT_MAX_GLPI_VERSION', '11.99.99');

/**
 * Called on every page load while the plugin is active. Registers hooks only;
 * no business logic lives here.
 */
function plugin_init_experiencekit()
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['experiencekit'] = true;

    Plugin::registerClass('PluginExperiencekitProfile', ['addtabon' => ['Profile']]);
    $PLUGIN_HOOKS['change_profile']['experiencekit'] = ['PluginExperiencekitProfile', 'changeProfile'];

    Plugin::registerClass('PluginExperiencekitRun');
    Plugin::registerClass('PluginExperiencekitRegistry');
    Plugin::registerClass('PluginExperiencekitPhaseProgress');
    Plugin::registerClass('PluginExperiencekitHealthcheck');

    // Gear icon on Setup > Plugins.
    $PLUGIN_HOOKS['config_page']['experiencekit'] = 'front/config.php';

    // Reachable from the main "Tools" nav, not just the Setup > Plugins gear icon,
    // since this is a repeatedly-used operational tool rather than one-time config.
    if (PluginExperiencekitRun::canView()) {
        $PLUGIN_HOOKS['menu_toadd']['experiencekit'] = ['tools' => 'PluginExperiencekitRun'];
    }
}

/**
 * Version and GLPI core compatibility declaration, read by GLPI's plugin manager.
 */
function plugin_version_experiencekit()
{
    return [
        'name'         => 'GLPI Experience Kit',
        'version'      => PLUGIN_EXPERIENCEKIT_VERSION,
        'author'       => 'MarifeX',
        'license'      => 'Proprietary',
        'homepage'     => '',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_EXPERIENCEKIT_MIN_GLPI_VERSION,
                'max' => PLUGIN_EXPERIENCEKIT_MAX_GLPI_VERSION,
            ],
        ],
    ];
}
