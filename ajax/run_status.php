<?php
/**
 * -------------------------------------------------------------------------
 * Experience Kit for GLPI - a plugin for GLPI 11
 * -------------------------------------------------------------------------
 * JSON progress-polling endpoint for the Generate tab's progress bars.
 */

require_once GLPI_ROOT . '/inc/includes.php';

header('Content-Type: application/json');

if (!PluginExperiencekitRun::canView()) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$runsId = (int) ($_GET['runs_id'] ?? 0);

$run = new PluginExperiencekitRun();
if ($runsId <= 0 || !$run->getFromDB($runsId)) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}

$progressRepository = new \GlpiPlugin\Experiencekit\Infrastructure\Persistence\PhaseProgressRepository();
$phases = [];
foreach ($progressRepository->allForRun($run) as $phaseValue => $progress) {
    $phase = \GlpiPlugin\Experiencekit\Domain\GenerationPhase::from($phaseValue);
    $phases[] = [
        'phase'     => $phaseValue,
        'label'     => $phase->label(),
        'status'    => $progress->fields['status'],
        'total'     => (int) $progress->fields['total_units'],
        'completed' => (int) $progress->fields['completed_units'],
        'error'     => $progress->fields['last_error'],
    ];
}

echo json_encode([
    'id'            => $run->getID(),
    'status'        => $run->fields['status'],
    'status_label'  => $run->getStatusLabel(),
    'current_phase' => $run->fields['current_phase'],
    'error_message' => $run->fields['error_message'],
    'phases'        => $phases,
]);
