<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Builder;

use Change;
use CommonITILObject;
use GlpiPlugin\Experiencekit\Application\EntityScopedActorResolver;
use GlpiPlugin\Experiencekit\Application\RunContext;
use GlpiPlugin\Experiencekit\Domain\Exception\GenerationException;
use GlpiPlugin\Experiencekit\Domain\GenerationPhase;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\Support\ActiveUserFinder;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\Support\RandomDataProvider;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\Support\SequentialPhaseBuilder;
use Problem;
use Ticket;

/**
 * Phase 5: statistical fill of the remaining Incidents/Requests/Problems/
 * Changes needed to reach the volume profile's totals, after the 7
 * narrative scenarios already accounted for some of each. Unlike
 * ScenarioBuilder, there is no cross-object correlation here - each record
 * is independent, generic "background noise" ticket volume, matching the
 * doc's own framing ("130 planned" Problems, generic Changes).
 *
 * By far the most expensive phase per doc §8 (~150-400ms per Ticket due to
 * full rule-engine evaluation) - this is exactly why batching/resumability
 * matters most here, not a phase to ever run unchunked.
 */
final class BulkTicketBuilder extends SequentialPhaseBuilder
{
    private const TICKET_SUBJECTS = [
        'Cannot access shared drive', 'Application freezes on startup', 'Need software license renewed',
        'Monitor flickering intermittently', 'Request for additional storage space', 'Slow computer performance',
        'Email not syncing on mobile device', 'Keyboard not responding', 'Request access to reporting dashboard',
        'Browser crashes when opening attachments', 'Need new employee badge printed', 'Printer toner replacement needed',
        'VPN disconnects randomly during meetings', 'Password reset for shared mailbox', 'Software update causing errors',
        'Request for standing desk', 'Conference room AV not working', 'Unable to print to network printer',
        'Laptop battery not charging', 'Request for second monitor', 'Phone system not receiving calls',
        'File permissions incorrect on shared folder', 'Outlook rules not triggering', 'Request software installation',
        'Wifi signal weak in east wing', 'Timesheet system showing error', 'Need report exported to PDF',
        'Account locked out after failed logins', 'Request temporary contractor access', 'Upgrade request for laptop RAM',
    ];

    private const PROBLEM_SUBJECTS = [
        'Recurring authentication failures across multiple accounts', 'Intermittent network latency affecting several sites',
        'Database performance degradation during peak hours', 'Repeated application crashes traced to a shared dependency',
        'Email delivery delays affecting multiple mailboxes', 'File server intermittently unreachable',
    ];

    private const CHANGE_SUBJECTS = [
        'Server operating system upgrade', 'Network switch firmware update', 'Storage array capacity expansion',
        'Email platform migration phase', 'Backup software version upgrade', 'Load balancer configuration update',
        'Database server maintenance window', 'Wireless access point firmware rollout',
    ];

    public function __construct(
        private readonly EntityScopedActorResolver $actors,
        private readonly ActiveUserFinder $users,
    ) {
    }

    public function getPhase(): GenerationPhase
    {
        return GenerationPhase::BULK_TICKETS;
    }

    protected function stages(RunContext $context): array
    {
        $profile = $context->profile;

        $scenarioIncidents = $context->registeredCount('Ticket', GenerationPhase::SCENARIOS, 'printer_failure')
            + $context->registeredCount('Ticket', GenerationPhase::SCENARIOS, 'vpn_outage');
        $scenarioRequests = $context->registeredCount('Ticket', GenerationPhase::SCENARIOS, 'onboarding')
            + $context->registeredCount('Ticket', GenerationPhase::SCENARIOS, 'offboarding')
            + $context->registeredCount('Ticket', GenerationPhase::SCENARIOS, 'laptop_replacement');
        $scenarioProblems = $context->registeredCount('Problem', GenerationPhase::SCENARIOS, 'printer_failure')
            + $context->registeredCount('Problem', GenerationPhase::SCENARIOS, 'vpn_outage');
        $scenarioChanges = $context->registeredCount('Change', GenerationPhase::SCENARIOS, 'patching')
            + $context->registeredCount('Change', GenerationPhase::SCENARIOS, 'firewall')
            + $context->registeredCount('Change', GenerationPhase::SCENARIOS, 'vpn_outage')
            + $context->registeredCount('Change', GenerationPhase::SCENARIOS, 'laptop_replacement');

        return [
            ['itemtype' => 'Ticket', 'target' => max(0, $profile->ticketsIncidents - $scenarioIncidents), 'tag' => 'bulk_incident', 'create' => fn (int $seq) => $this->createTicket($context, $seq, Ticket::INCIDENT_TYPE)],
            ['itemtype' => 'Ticket', 'target' => max(0, $profile->ticketsRequests - $scenarioRequests), 'tag' => 'bulk_request', 'create' => fn (int $seq) => $this->createTicket($context, $seq, Ticket::DEMAND_TYPE)],
            ['itemtype' => 'Problem', 'target' => max(0, $profile->problems - $scenarioProblems), 'tag' => 'bulk_problem', 'create' => fn (int $seq) => $this->createProblem($context, $seq)],
            ['itemtype' => 'Change', 'target' => max(0, $profile->changes - $scenarioChanges), 'tag' => 'bulk_change', 'create' => fn (int $seq) => $this->createChange($context, $seq)],
        ];
    }

    private function createTicket(RunContext $context, int $seq, int $type): void
    {
        $rng = new RandomDataProvider($context->seed());
        $salt = $type === Ticket::INCIDENT_TYPE ? 9000 : 9500;
        $requesterId = $this->users->random($context, $seq + $salt);
        $entitiesId = $this->actors->entityForRequester($requesterId);

        $subject = self::TICKET_SUBJECTS[($seq + $salt) % count(self::TICKET_SUBJECTS)];
        $date = date('Y-m-d H:i:s', strtotime('-' . $rng->intBetween(1, 730, $seq + $salt) . ' days'));
        $isClosed = $rng->boolWithProbability(0.90, $seq + $salt + 1);

        $ticket = new Ticket();
        $id = $ticket->add([
            'name'          => $subject,
            'content'       => $subject . '. Please advise on next steps.',
            'type'          => $type,
            'priority'      => $rng->intBetween(1, 4, $seq + $salt + 2),
            'status'        => $isClosed ? CommonITILObject::CLOSED : CommonITILObject::ASSIGNED,
            'entities_id'   => $entitiesId,
            'date'          => $date,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($id, 'Ticket', $seq);

        $tag = $type === Ticket::INCIDENT_TYPE ? 'bulk_incident' : 'bulk_request';
        $context->register($this->getPhase(), 'Ticket', (int) $id, $tag);
    }

    private function createProblem(RunContext $context, int $seq): void
    {
        $rng = new RandomDataProvider($context->seed());
        $requesterId = $this->users->random($context, $seq + 10000);
        $entitiesId = $this->actors->entityForRequester($requesterId);
        $subject = self::PROBLEM_SUBJECTS[$seq % count(self::PROBLEM_SUBJECTS)];

        $problem = new Problem();
        $id = $problem->add([
            'name'          => $subject,
            'content'       => $subject . '. Root cause analysis planned.',
            'priority'      => $rng->intBetween(2, 4, $seq + 10001),
            'status'        => CommonITILObject::PLANNED,
            'entities_id'   => $entitiesId,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($id, 'Problem', $seq);

        $context->register($this->getPhase(), 'Problem', (int) $id, 'bulk_problem');
    }

    private function createChange(RunContext $context, int $seq): void
    {
        $rng = new RandomDataProvider($context->seed());
        $requesterId = $this->users->random($context, $seq + 11000);
        $entitiesId = $this->actors->entityForRequester($requesterId);
        $subject = self::CHANGE_SUBJECTS[$seq % count(self::CHANGE_SUBJECTS)];

        $change = new Change();
        $id = $change->add([
            'name'          => $subject,
            'content'       => $subject . '. Standard change, no CAB required.',
            'priority'      => $rng->intBetween(1, 3, $seq + 11001),
            'status'        => CommonITILObject::PLANNED,
            'entities_id'   => $entitiesId,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($id, 'Change', $seq);

        $context->register($this->getPhase(), 'Change', (int) $id, 'bulk_change');
    }

    private function assertCreated($id, string $itemtype, int $seq): void
    {
        if (!$id) {
            throw new GenerationException("Failed to create {$itemtype} at sequence {$seq}.");
        }
    }
}
