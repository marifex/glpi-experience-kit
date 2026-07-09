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
 * Wires PluginExperiencekitRun::$rightname into GLPI's native
 * Administration > Profiles > Rights matrix. No custom permission table or
 * access-control UI is introduced.
 */
class PluginExperiencekitProfile extends CommonDBTM
{
    public static function getTypeName($nb = 0)
    {
        return __('Experience Kit', 'experiencekit');
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item->getType() == 'Profile' && $item->fields['id']) {
            return self::createTabEntry(
                PluginExperiencekitRun::getTypeName(2),
                0,
                $item::getType(),
                PluginExperiencekitRun::getIcon()
            );
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item->getType() == 'Profile') {
            self::showForProfile($item->getID());
        }
        return true;
    }

    /**
     * Renders the plugin's right as one row in GLPI's native rights matrix
     * widget (Profile::showMatrix), on the existing Profile edit screen.
     */
    public static function showForProfile($profiles_id)
    {
        $canedit = Session::haveRight('profile', UPDATE);
        $profile = new Profile();
        $profile->getFromDB($profiles_id);

        echo "<div class='center'>";
        echo "<form method='post' action='" . Toolbox::getItemTypeFormURL('Profile') . "'>";

        $rights = [
            [
                'itemtype' => 'PluginExperiencekitRun',
                'label'    => PluginExperiencekitRun::getTypeName(2),
                'field'    => PluginExperiencekitRun::$rightname,
            ],
        ];
        $profile->displayRightsChoiceMatrix($rights, [
            'canedit'       => $canedit,
            'default_class' => 'tab_bg_2',
            'title'         => __('General'),
        ]);

        echo Html::hidden('id', ['value' => $profiles_id]);
        if ($canedit) {
            echo "<div class='center'>";
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update']);
            echo "</div>";
        }
        Html::closeForm();
        echo "</div>";
    }

    /**
     * GLPI's change_profile hook contract: reload this plugin's right into
     * the active session whenever the user switches active profile.
     */
    public static function changeProfile()
    {
        $_SESSION['glpiactiveprofile'][PluginExperiencekitRun::$rightname] =
            $_SESSION['glpiactiveprofile'][PluginExperiencekitRun::$rightname] ?? 0;
    }
}
