<?php
/**
 * -------------------------------------------------------------------------
 * GLPI Experience Kit plugin for GLPI 11
 * -------------------------------------------------------------------------
 * Main admin screen (Setup > Plugins gear icon, and the "Experience Kit"
 * entry under Tools): start/monitor/control generation runs.
 *
 * NOTE: intentionally not the usual relative '../../../inc/includes.php'.
 * This plugin's canonical files live in a OneDrive-backed repo and are
 * reached via a Windows directory junction at glpi/plugins/experiencekit.
 * PHP resolves __DIR__/__FILE__ for junctioned paths to the physical
 * target directory, so a hop-count-based relative include silently
 * breaks. GLPI_ROOT is defined independently by GLPI core's own
 * src/autoload/constants.php (a real, non-junctioned path) and is
 * already available by the time routing reaches this legacy file, so
 * anchoring on it is reliable regardless of how this file was reached.
 */
require_once GLPI_ROOT . '/inc/includes.php';

use GlpiPlugin\Experiencekit\Domain\VolumeProfileFactory;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\HealthCheckRepository;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\PhaseProgressRepository;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\RegistryRepository;
use GlpiPlugin\Experiencekit\Infrastructure\Support\OrchestratorFactory;

Session::checkRight(PluginExperiencekitRun::$rightname, READ);

$orchestrator = OrchestratorFactory::make();
$purgeOrchestrator = OrchestratorFactory::makePurgeOrchestrator();
$healthCheckService = OrchestratorFactory::makeHealthCheckService();

if (isset($_POST['start_run'])) {
    Session::checkRight(PluginExperiencekitRun::$rightname, CREATE);
    $orchestrator->startRun(
        $_POST['volume_profile'] ?? VolumeProfileFactory::MEDIUM,
        Session::getLoginUserID(),
        trim((string) ($_POST['run_name'] ?? '')) ?: null,
        trim((string) ($_POST['organization_name'] ?? '')) ?: 'MarifeX',
    );
    Html::back();
}

if (isset($_POST['run_now'])) {
    Session::checkRight(PluginExperiencekitRun::$rightname, UPDATE);
    $run = new PluginExperiencekitRun();
    if ($run->getFromDB((int) $_POST['runs_id'])) {
        try {
            // Bounded: this is a synchronous web request, not the
            // background cron - a handful of batches gives visible
            // progress without risking a PHP max_execution_time timeout.
            // The cron task (or clicking this again) carries the run the
            // rest of the way.
            for ($i = 0; $i < 10; $i++) {
                $run->getFromDB($run->getID());
                if ($run->fields['status'] !== PluginExperiencekitRun::STATUS_RUNNING) {
                    break;
                }
                $orchestrator->runNextBatch($run, 100);
            }
        } catch (\GlpiPlugin\Experiencekit\Domain\Exception\GenerationException $e) {
            Session::addMessageAfterRedirect($e->getMessage(), true, ERROR);
        }
    }
    Html::back();
}

if (isset($_POST['pause_run'])) {
    Session::checkRight(PluginExperiencekitRun::$rightname, UPDATE);
    $run = new PluginExperiencekitRun();
    if ($run->getFromDB((int) $_POST['runs_id'])) {
        $orchestrator->pauseRun($run);
    }
    Html::back();
}

if (isset($_POST['resume_run'])) {
    Session::checkRight(PluginExperiencekitRun::$rightname, UPDATE);
    $run = new PluginExperiencekitRun();
    if ($run->getFromDB((int) $_POST['runs_id'])) {
        $orchestrator->resumeRun($run);
    }
    Html::back();
}

if (isset($_POST['cancel_run'])) {
    Session::checkRight(PluginExperiencekitRun::$rightname, UPDATE);
    $run = new PluginExperiencekitRun();
    if ($run->getFromDB((int) $_POST['runs_id'])) {
        $orchestrator->cancelRun($run);
    }
    Html::back();
}

if (isset($_POST['start_purge'])) {
    Session::checkRight(PluginExperiencekitRun::$rightname, PURGE);
    $run = new PluginExperiencekitRun();
    if ($run->getFromDB((int) $_POST['runs_id'])) {
        $purgeOrchestrator->startPurge($run);
        // Bounded first pass, same reasoning as run_now: visible progress
        // without a synchronous-request timeout risk; the cron task (or
        // clicking "Purge now" again) carries the rest.
        for ($i = 0; $i < 10; $i++) {
            $run->getFromDB($run->getID());
            if ($run->fields['status'] !== PluginExperiencekitRun::STATUS_PURGING) {
                break;
            }
            $purgeOrchestrator->purgeNextBatch($run, 100);
        }
    }
    Html::back();
}

if (isset($_POST['purge_now'])) {
    Session::checkRight(PluginExperiencekitRun::$rightname, PURGE);
    $run = new PluginExperiencekitRun();
    if ($run->getFromDB((int) $_POST['runs_id'])) {
        for ($i = 0; $i < 10; $i++) {
            $run->getFromDB($run->getID());
            if ($run->fields['status'] !== PluginExperiencekitRun::STATUS_PURGING) {
                break;
            }
            $purgeOrchestrator->purgeNextBatch($run, 100);
        }
    }
    Html::back();
}

if (isset($_POST['health_check'])) {
    Session::checkRight(PluginExperiencekitRun::$rightname, READ);
    $runsId = (int) $_POST['runs_id'];
    $healthCheckService->run($runsId > 0 ? $runsId : null);
    $_SESSION['ek_last_health_check_run'] = $runsId > 0 ? $runsId : 'all';
    Html::back();
}

Html::header(PluginExperiencekitRun::getTypeName(2), '', 'tools', 'PluginExperiencekitRun');

$runRepo = new PluginExperiencekitRun();
$activeRuns = $runRepo->find([
    'status' => [
        PluginExperiencekitRun::STATUS_RUNNING,
        PluginExperiencekitRun::STATUS_PAUSED,
        PluginExperiencekitRun::STATUS_PENDING,
        PluginExperiencekitRun::STATUS_PURGING,
    ],
], ['date_creation DESC']);

$recentRuns = $runRepo->find([], ['date_creation DESC'], 10);

$progressRepository = new PhaseProgressRepository();
$registryRepository = new RegistryRepository($GLOBALS['DB']);

// NOT $_SERVER['PHP_SELF']: GLPI 11 serves legacy plugin files through a
// Symfony route (LegacyFileLoadController), which rewrites PHP_SELF to the
// router's own script (index.php) rather than this file's actual path -
// every form on this page would silently post to the wrong URL.
$selfUrl = Plugin::getWebDir('experiencekit', true) . '/front/config.php';

echo "<div class='container-fluid' style='max-width:1100px;margin:0 auto;'>";

// --- Active run(s) -------------------------------------------------------
if (count($activeRuns) > 0) {
    foreach ($activeRuns as $row) {
        $run = new PluginExperiencekitRun();
        $run->getFromDB($row['id']);
        echo "<div class='card mt-3'><div class='card-body'>";
        echo '<h3>' . htmlescape($run->fields['name']) . ' <span class="badge bg-blue-lt">' . htmlescape($run->getStatusLabel()) . '</span></h3>';
        echo '<p class="text-muted">' . htmlescape(sprintf(
            __('Organization: %1$s — Profile: %2$s', 'experiencekit'),
            $run->fields['organization_name'],
            $run->fields['volume_profile']
        )) . '</p>';

        if ($run->fields['status'] === PluginExperiencekitRun::STATUS_PURGING) {
            $remaining = array_sum($purgeOrchestrator->preview($run->getID()));
            echo "<div class='mb-2'>";
            echo '<p>' . htmlescape(sprintf(__('%d record(s) remaining to remove.', 'experiencekit'), $remaining)) . '</p>';
            echo '</div>';

            echo "<form method='post' action='" . htmlescape($selfUrl) . "' class='d-inline'>";
            echo Html::hidden('runs_id', ['value' => $run->getID()]);
            echo Html::submit(__('Purge now', 'experiencekit'), ['name' => 'purge_now', 'class' => 'btn btn-primary btn-sm']);
            Html::closeForm();

            echo '</div></div>';
            continue;
        }

        echo "<div id='ek-progress-" . $run->getID() . "' data-runs-id='" . $run->getID() . "'>";
        foreach ($progressRepository->allForRun($run) as $phaseValue => $progress) {
            $phase = \GlpiPlugin\Experiencekit\Domain\GenerationPhase::from($phaseValue);
            $total = max(1, (int) $progress->fields['total_units']);
            $completed = (int) $progress->fields['completed_units'];
            $pct = (int) min(100, round($completed / $total * 100));
            $barClass = match ($progress->fields['status']) {
                'done'    => 'bg-success',
                'failed'  => 'bg-danger',
                'running' => 'bg-blue',
                default   => 'bg-secondary',
            };
            echo "<div class='mb-2'>";
            echo '<div class="d-flex justify-content-between"><small>' . htmlescape($phase->label()) . '</small><small>' . $completed . ' / ' . (int) $progress->fields['total_units'] . '</small></div>';
            echo "<div class='progress' style='height:8px;'><div class='progress-bar {$barClass}' style='width:{$pct}%'></div></div>";
            echo '</div>';
        }
        echo '</div>';

        echo "<form method='post' action='" . htmlescape($selfUrl) . "' class='d-inline'>";
        echo Html::hidden('runs_id', ['value' => $run->getID()]);
        if ($run->fields['status'] === PluginExperiencekitRun::STATUS_RUNNING) {
            echo Html::submit(__('Run now', 'experiencekit'), ['name' => 'run_now', 'class' => 'btn btn-primary btn-sm']);
            echo ' ' . Html::submit(__('Pause', 'experiencekit'), ['name' => 'pause_run', 'class' => 'btn btn-secondary btn-sm']);
        } elseif ($run->fields['status'] === PluginExperiencekitRun::STATUS_PAUSED) {
            echo Html::submit(__('Resume', 'experiencekit'), ['name' => 'resume_run', 'class' => 'btn btn-primary btn-sm']);
        }
        echo ' ' . Html::submit(__('Cancel', 'experiencekit'), ['name' => 'cancel_run', 'class' => 'btn btn-danger btn-sm']);
        Html::closeForm();

        echo '</div></div>';
    }
} else {
    // --- Start a new run --------------------------------------------------
    echo "<div class='card mt-3'><div class='card-body'>";
    echo '<h3>' . htmlescape(__('Generate a new environment', 'experiencekit')) . '</h3>';
    echo "<form method='post' action='" . htmlescape($selfUrl) . "'>";

    echo "<div class='mb-3'>";
    echo '<label class="form-label">' . htmlescape(__('Volume profile', 'experiencekit')) . '</label>';
    echo Html::select('volume_profile', array_combine(VolumeProfileFactory::names(), array_map('ucfirst', VolumeProfileFactory::names())), [
        'value' => VolumeProfileFactory::MEDIUM,
    ]);
    echo '</div>';

    echo "<div class='mb-3'>";
    echo '<label class="form-label">' . htmlescape(__('Organization name', 'experiencekit')) . '</label>';
    echo Html::input('organization_name', ['value' => 'MarifeX']);
    echo '</div>';

    echo "<div class='mb-3'>";
    echo '<label class="form-label">' . htmlescape(__('Run name (optional)', 'experiencekit')) . '</label>';
    echo Html::input('run_name', ['value' => '']);
    echo '</div>';

    echo Html::submit(__('Generate', 'experiencekit'), ['name' => 'start_run', 'class' => 'btn btn-primary']);
    Html::closeForm();
    echo '</div></div>';
}

// --- Health check results (just-triggered, shown once) ---------------------
if (isset($_SESSION['ek_last_health_check_run'])) {
    $checkedRunsId = $_SESSION['ek_last_health_check_run'];
    unset($_SESSION['ek_last_health_check_run']);

    // latestForRun() only fetches rows scoped to one run; an "all runs"
    // check is persisted with runs_id NULL, so fetch by recency instead.
    $results = [];
    if ($checkedRunsId === 'all') {
        foreach ($GLOBALS['DB']->request([
            'FROM'  => 'glpi_plugin_experiencekit_healthchecks',
            'WHERE' => ['runs_id' => null],
            'ORDER' => 'date_creation DESC',
            'LIMIT' => 10,
        ]) as $checkRow) {
            $item = new PluginExperiencekitHealthcheck();
            $item->getFromDB($checkRow['id']);
            $results[] = $item;
        }
    } else {
        $results = (new HealthCheckRepository())->latestForRun((int) $checkedRunsId);
    }

    echo "<div class='card mt-3'><div class='card-body'>";
    echo '<h3>' . htmlescape(__('Health check results', 'experiencekit')) . '</h3>';
    if (count($results) === 0) {
        echo '<p class="text-muted">' . htmlescape(__('No results.', 'experiencekit')) . '</p>';
    } else {
        foreach ($results as $result) {
            $badgeClass = match ($result->fields['status']) {
                PluginExperiencekitHealthcheck::STATUS_PASS => 'bg-success',
                PluginExperiencekitHealthcheck::STATUS_WARN => 'bg-warning',
                default => 'bg-danger',
            };
            $details = json_decode($result->fields['details_json'] ?? '[]', true) ?: [];
            $summary = $details['summary'] ?? '';
            $label = $details['label'] ?? $result->fields['check_key'];
            unset($details['summary'], $details['label']);

            echo '<div class="mb-2"><span class="badge ' . $badgeClass . '">' . htmlescape(strtoupper($result->fields['status'])) . '</span> ';
            echo '<strong>' . htmlescape($label) . '</strong>';
            if ($summary !== '') {
                echo ' — ' . htmlescape($summary);
            }
            if (!empty($details)) {
                echo ' <small class="text-muted">' . htmlescape(json_encode($details)) . '</small>';
            }
            echo '</div>';
        }
    }
    echo '</div></div>';
}

// --- Recent runs -----------------------------------------------------------
echo "<div class='card mt-3'><div class='card-body'>";
echo '<h3>' . htmlescape(__('Recent runs', 'experiencekit')) . '</h3>';
if (count($recentRuns) === 0) {
    echo '<p class="text-muted">' . htmlescape(__('No runs yet.', 'experiencekit')) . '</p>';
} else {
    echo "<table class='table table-sm'><thead><tr>";
    foreach ([__('Name', 'experiencekit'), __('Profile', 'experiencekit'), __('Status', 'experiencekit'), __('Records', 'experiencekit'), __('Created', 'experiencekit'), __('Actions', 'experiencekit')] as $header) {
        echo '<th>' . htmlescape($header) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($recentRuns as $row) {
        $run = new PluginExperiencekitRun();
        $run->getFromDB($row['id']);
        $counts = $registryRepository->countsByItemtypeForRun($run->getID());
        $total = array_sum($counts);
        echo '<tr>';
        echo '<td>' . htmlescape($run->fields['name']) . '</td>';
        echo '<td>' . htmlescape($run->fields['volume_profile']) . '</td>';
        echo '<td>' . htmlescape($run->getStatusLabel()) . '</td>';
        echo '<td>' . $total . '</td>';
        echo '<td>' . htmlescape($run->fields['date_creation']) . '</td>';
        echo '<td>';
        if ($total > 0) {
            echo "<form method='post' action='" . htmlescape($selfUrl) . "' class='d-inline'>";
            echo Html::hidden('runs_id', ['value' => $run->getID()]);
            echo Html::submit(__('Health check', 'experiencekit'), ['name' => 'health_check', 'class' => 'btn btn-outline-primary btn-sm']);
            Html::closeForm();
        }
        if ($run->fields['status'] === PluginExperiencekitRun::STATUS_COMPLETED || $run->fields['status'] === PluginExperiencekitRun::STATUS_FAILED) {
            echo " <form method='post' action='" . htmlescape($selfUrl) . "' class='d-inline' onsubmit=\"return confirm(" . htmlescape(json_encode(sprintf(
                __('Permanently delete all %d record(s) this run generated? This cannot be undone.', 'experiencekit'),
                $total
            ))) . ")\">";
            echo Html::hidden('runs_id', ['value' => $run->getID()]);
            echo Html::submit(__('Purge', 'experiencekit'), ['name' => 'start_purge', 'class' => 'btn btn-outline-danger btn-sm']);
            Html::closeForm();
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
echo '</div></div>';

echo '</div>';

if (count($activeRuns) > 0) {
    $pollTarget = $activeRuns[array_key_first($activeRuns)]['id'];
    $pollTargetRun = new PluginExperiencekitRun();
    $pollTargetRun->getFromDB($pollTarget);
    $initialCompleted = 0;
    foreach ($progressRepository->allForRun($pollTargetRun) as $progress) {
        $initialCompleted += (int) $progress->fields['completed_units'];
    }
    // Lightweight "live progress": poll the JSON endpoint, and reload the
    // whole page as soon as it reports more work done (or a status change)
    // than what was rendered - simpler than granular DOM patching, and
    // this page is cheap enough to re-render every few seconds.
    echo <<<HTML
    <script>
    (function () {
        const runsId = {$pollTarget};
        const initialCompleted = {$initialCompleted};
        const poll = () => {
            fetch('/plugins/experiencekit/ajax/run_status.php?runs_id=' + runsId)
                .then((r) => r.ok ? r.json() : null)
                .then((data) => {
                    if (!data) {
                        return;
                    }
                    if (data.status !== 'running') {
                        window.location.reload();
                        return;
                    }
                    const completed = (data.phases || []).reduce((sum, p) => sum + p.completed, 0);
                    if (completed > initialCompleted) {
                        window.location.reload();
                        return;
                    }
                    setTimeout(poll, 3000);
                })
                .catch(() => {});
        };
        setTimeout(poll, 3000);
    })();
    </script>
    HTML;
}

Html::footer();
