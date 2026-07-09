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
 * One row per generation run. This is the plugin's primary itemtype: the
 * right that gates the whole plugin (generate/purge/health-check) lives
 * here, and the admin UI's main nav entry points at it.
 */
class PluginExperiencekitRun extends CommonDBTM
{
    public static $rightname = 'plugin_experiencekit_use';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_PAUSED    = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_PURGING   = 'purging';
    public const STATUS_PURGED    = 'purged';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_experiencekit_runs';
    }

    public static function getTypeName($nb = 0)
    {
        return _n('Experience Kit run', 'Experience Kit runs', $nb, 'experiencekit');
    }

    public static function getIcon()
    {
        return 'ti ti-building-factory-2';
    }

    public static function getMenuContent()
    {
        if (!static::canView()) {
            return false;
        }

        $menu = [
            'title' => __('Experience Kit', 'experiencekit'),
            'page'  => '/plugins/experiencekit/front/config.php',
            'icon'  => static::getIcon(),
        ];
        $menu['links']['search'] = '/plugins/experiencekit/front/config.php';

        return $menu;
    }

    public static function cronInfo($name)
    {
        return match ($name) {
            'ProcessBatch' => [
                'description' => __('Advances any in-progress Experience Kit generation run by one bounded batch per phase', 'experiencekit'),
                'parameter'   => __('Max records processed per batch', 'experiencekit'),
            ],
            default => [],
        };
    }

    /**
     * GLPI cron entry point (registered as PluginExperiencekitRun /
     * ProcessBatch in hook.php). Advances every currently-running run by
     * one bounded batch. A run that fails or completes mid-loop does not
     * stop the others from being processed in the same tick.
     *
     * @return int >0 done, 0 nothing to do
     */
    public static function cronProcessBatch(CronTask $task): int
    {
        $batchSize = (int) ($task->fields['param'] ?? 0);
        if ($batchSize <= 0) {
            $batchSize = 200;
        }

        $repository = new \GlpiPlugin\Experiencekit\Infrastructure\Persistence\RunRepository();
        $runningRuns = $repository->findByStatus(self::STATUS_RUNNING);

        if (count($runningRuns) === 0) {
            return 0;
        }

        $orchestrator = \GlpiPlugin\Experiencekit\Infrastructure\Support\OrchestratorFactory::make();

        foreach ($runningRuns as $run) {
            try {
                $orchestrator->runNextBatch($run, $batchSize);
                $task->addVolume(1);
            } catch (\Throwable $e) {
                $task->log('Run #' . $run->getID() . ' failed: ' . $e->getMessage());
            }
        }

        return 1;
    }

    public function getStatusLabel(): string
    {
        return match ($this->fields['status'] ?? self::STATUS_PENDING) {
            self::STATUS_PENDING   => __('Pending', 'experiencekit'),
            self::STATUS_RUNNING   => __('Running', 'experiencekit'),
            self::STATUS_PAUSED    => __('Paused', 'experiencekit'),
            self::STATUS_COMPLETED => __('Completed', 'experiencekit'),
            self::STATUS_FAILED    => __('Failed', 'experiencekit'),
            self::STATUS_PURGING   => __('Purging', 'experiencekit'),
            self::STATUS_PURGED    => __('Purged', 'experiencekit'),
            default                 => $this->fields['status'] ?? '',
        };
    }
}
