<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Builder;

use Change;
use Change_Group;
use Change_Problem;
use Change_Ticket;
use ChangeValidation;
use CommonITILActor;
use CommonITILObject;
use CommonITILValidation;
use Computer;
use GlpiPlugin\Experiencekit\Application\EntityScopedActorResolver;
use GlpiPlugin\Experiencekit\Application\RunContext;
use GlpiPlugin\Experiencekit\Domain\Exception\GenerationException;
use GlpiPlugin\Experiencekit\Domain\GenerationPhase;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\Support\RandomDataProvider;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\Support\SequentialPhaseBuilder;
use Item_Ticket;
use Peripheral;
use Phone;
use Problem;
use Problem_Ticket;
use Ticket;

/**
 * Phase 4: the 7 narrative ITIL scenarios (§4) - the demo's "story",
 * layered on top of org_structure/cmdb/itsm_config. Business-rule
 * categorization/routing is left to the rule engine (tickets are named/
 * worded to match the keyword rules from ItsmConfigBuilder) rather than
 * set manually, exactly like the original dataset.
 *
 * Every ITIL object here gets an explicit requester via
 * EntityScopedActorResolver - not just Tickets. The original dataset's §5
 * bug affected Problems and Changes too ("Ticket/Problem/Change requester
 * actor links | 7,500 / 131 / 250 | 100% coverage after remediation"),
 * so this phase is exactly where that permanent fix has to prove itself.
 */
final class ScenarioBuilder extends SequentialPhaseBuilder
{
    private const PATCHING_TARGET = 24;
    private const FIREWALL_TARGET = 8;
    private const PRINTER_INCIDENTS = 16;
    private const VPN_TOTAL_INCIDENTS = 29;

    public function __construct(private readonly EntityScopedActorResolver $actors)
    {
    }

    public function getPhase(): GenerationPhase
    {
        return GenerationPhase::SCENARIOS;
    }

    protected function stages(RunContext $context): array
    {
        $laptopTarget = $context->registeredCount('Computer', GenerationPhase::CMDB, 'retired_computer');

        return [
            ['itemtype' => 'Change', 'target' => self::PATCHING_TARGET, 'tag' => 'patching', 'create' => fn (int $seq) => $this->createPatchingChange($context, $seq)],
            ['itemtype' => 'Change', 'target' => self::FIREWALL_TARGET, 'tag' => 'firewall', 'create' => fn (int $seq) => $this->createFirewallChange($context, $seq)],
            ['itemtype' => 'Ticket', 'target' => self::PRINTER_INCIDENTS, 'tag' => 'printer_failure', 'create' => fn (int $seq) => $this->createPrinterIncident($context, $seq)],
            ['itemtype' => 'Ticket', 'target' => self::VPN_TOTAL_INCIDENTS, 'tag' => 'vpn_outage', 'create' => fn (int $seq) => $this->createVpnIncident($context, $seq)],
            ['itemtype' => 'Ticket', 'target' => $context->profile->usersOnboardingCohort, 'tag' => 'onboarding', 'create' => fn (int $seq) => $this->createOnboardingRequest($context, $seq)],
            ['itemtype' => 'Ticket', 'target' => $context->profile->usersExited, 'tag' => 'offboarding', 'create' => fn (int $seq) => $this->createOffboardingRequest($context, $seq)],
            ['itemtype' => 'Ticket', 'target' => $laptopTarget, 'tag' => 'laptop_replacement', 'create' => fn (int $seq) => $this->createLaptopReplacement($context, $seq)],
        ];
    }

    // --- Scenario 1: Monthly Windows patching -------------------------------

    private function createPatchingChange(RunContext $context, int $seq): void
    {
        $rng = new RandomDataProvider($context->seed());
        $requesterId = $this->randomUserByProfile($context, 'Technician', $seq);
        $entitiesId = $this->actors->entityForRequester($requesterId);

        // 1/month going back from now; evening maintenance window.
        $date = date('Y-m-d', strtotime("-{$seq} months")) . ' ' . sprintf('%02d:%02d:00', $rng->intBetween(20, 22, $seq), $rng->intBetween(0, 59, $seq));

        $change = new Change();
        $id = $change->add([
            'name'          => 'Monthly Windows Patching - ' . date('F Y', strtotime($date)),
            'content'       => 'Scheduled monthly patching window for Windows workstations and servers. Standard change, evening maintenance window.',
            'entities_id'   => $entitiesId,
            'priority'      => 2,
            'status'        => CommonITILObject::CLOSED,
            'date'          => $date,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($id, 'Change', $seq);

        $this->assignGroup((int) $id, Change_Group::class, 'changes_id', $this->groupIdByName($context, 'Systems & Infrastructure Team'));

        $context->register($this->getPhase(), 'Change', (int) $id, 'patching');
    }

    // --- Scenario 2: Quarterly firewall upgrade -----------------------------

    private function createFirewallChange(RunContext $context, int $seq): void
    {
        $requesterId = $this->randomUserByProfile($context, 'Technician', $seq + 1000);
        $entitiesId = $this->actors->entityForRequester($requesterId);
        $date = date('Y-m-d H:i:s', strtotime('-' . ($seq * 3) . ' months'));

        $change = new Change();
        $id = $change->add([
            'name'          => 'Quarterly Firewall Upgrade - Q' . (($seq % 4) + 1) . ' ' . date('Y', strtotime($date)),
            'content'       => 'Scheduled firewall firmware and ruleset upgrade across all sites. Requires CAB approval before rollout.',
            'entities_id'   => $entitiesId,
            'priority'      => 3,
            'status'        => CommonITILObject::CLOSED,
            'date'          => $date,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($id, 'Change', $seq);

        $cabGroupId = $this->groupIdByName($context, 'IT Change Advisory Board');
        $this->assignGroup((int) $id, Change_Group::class, 'changes_id', $cabGroupId);

        $this->approveChangeValidation((int) $id, $requesterId, $cabGroupId, $date);

        $context->register($this->getPhase(), 'Change', (int) $id, 'firewall');
    }

    // --- Scenario 3: Printer failure cluster --------------------------------

    private function createPrinterIncident(RunContext $context, int $seq): void
    {
        $printerIds = $context->registeredIds('Printer', GenerationPhase::CMDB);
        if (count($printerIds) < 3) {
            throw new GenerationException('Printer failure scenario needs at least 3 Printer assets from CmdbBuilder.');
        }
        $printerIds = array_slice($printerIds, 0, 3);

        // Distribute 16 incidents across exactly 3 printers, ~5-6 each.
        $printerIndex = $seq % 3;
        $printersId = $printerIds[$printerIndex];

        $rng = new RandomDataProvider($context->seed());
        $requesterId = $this->randomUser($context, $seq + 2000);
        $entitiesId = $this->actors->entityForRequester($requesterId);
        $date = date('Y-m-d H:i:s', strtotime('-' . $rng->intBetween(1, 180, $seq + 2000) . ' days'));

        $ticket = new Ticket();
        $id = $ticket->add([
            'name'          => 'Printer not working - paper jam recurring',
            'content'       => 'The printer keeps jamming and needs a technician to look at it again.',
            'type'          => Ticket::INCIDENT_TYPE,
            'priority'      => 3,
            'status'        => CommonITILObject::CLOSED,
            'entities_id'   => $entitiesId,
            'date'          => $date,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($id, 'Ticket', $seq);

        $link = new Item_Ticket();
        $link->add(['itemtype' => 'Printer', 'items_id' => $printersId, 'tickets_id' => (int) $id]);

        $context->register($this->getPhase(), 'Ticket', (int) $id, 'printer_failure');

        // Once a printer's cluster is complete, correlate its incidents into one Problem.
        $clusterSeqs = array_values(array_filter(range(0, self::PRINTER_INCIDENTS - 1), fn (int $s) => $s % 3 === $printerIndex));
        if ($seq === end($clusterSeqs)) {
            $this->correlatePrinterProblem($context, $printerIndex, $printersId, $clusterSeqs);
        }
    }

    private function correlatePrinterProblem(RunContext $context, int $printerIndex, int $printersId, array $clusterSeqs): void
    {
        $ticketIds = $context->registeredIds('Ticket', $this->getPhase(), 'printer_failure');
        // Tickets are registered in creation order; take this printer's own slice.
        $clusterTicketIds = [];
        foreach ($clusterSeqs as $i => $seqInCluster) {
            if (isset($ticketIds[$seqInCluster])) {
                $clusterTicketIds[] = $ticketIds[$seqInCluster];
            }
        }

        $requesterId = $this->randomUserByProfile($context, 'Technician', $printerIndex + 3000);
        $entitiesId = $this->actors->entityForRequester($requesterId);

        $problem = new Problem();
        $id = $problem->add([
            'name'          => 'Recurring printer failures - printer #' . ($printerIndex + 1),
            'content'       => 'Multiple recurring incidents traced to a single failing printer unit.',
            'entities_id'   => $entitiesId,
            'priority'      => 3,
            'status'        => CommonITILObject::CLOSED,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($id, 'Problem', $printerIndex);

        foreach ($clusterTicketIds as $ticketsId) {
            $link = new Problem_Ticket();
            $link->add(['problems_id' => (int) $id, 'tickets_id' => $ticketsId]);
        }

        $context->register($this->getPhase(), 'Problem', (int) $id, 'printer_failure');
    }

    // --- Scenario 4: VPN outage ----------------------------------------------

    private function createVpnIncident(RunContext $context, int $seq): void
    {
        $rng = new RandomDataProvider($context->seed());

        // 3 events: [0..9]=event0 (major+9 related), [10..19]=event1, [20..28]=event2 (major+8 related).
        [$eventIndex, $isMajor, $eventSeqs] = $this->vpnEventFor($seq);

        $requesterId = $this->randomUser($context, $seq + 4000);
        $entitiesId = $this->actors->entityForRequester($requesterId);
        $date = date('Y-m-d H:i:s', strtotime('-' . $rng->intBetween(1, 365, $seq + 4000) . ' days'));

        $ticket = new Ticket();
        $id = $ticket->add([
            'name'          => $isMajor ? 'VPN service down for entire office - urgent' : 'Cannot connect to VPN',
            'content'       => $isMajor
                ? 'VPN service is completely down, urgent, affecting all remote workers at this site.'
                : 'Unable to establish a VPN connection since this morning.',
            'type'          => Ticket::INCIDENT_TYPE,
            'priority'      => $isMajor ? 6 : 5,
            'status'        => CommonITILObject::CLOSED,
            'entities_id'   => $entitiesId,
            'date'          => $date,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($id, 'Ticket', $seq);

        $context->register($this->getPhase(), 'Ticket', (int) $id, 'vpn_outage');

        if ($seq === end($eventSeqs)) {
            $this->correlateVpnProblemAndChange($context, $eventIndex, $eventSeqs);
        }
    }

    /** @return array{0:int,1:bool,2:int[]} [eventIndex, isMajorIncident, allSeqsInThisEvent] */
    private function vpnEventFor(int $seq): array
    {
        $sizes = [10, 10, 9]; // sums to 29
        $cursor = 0;
        foreach ($sizes as $eventIndex => $size) {
            $eventSeqs = range($cursor, $cursor + $size - 1);
            if (in_array($seq, $eventSeqs, true)) {
                return [$eventIndex, $seq === $cursor, $eventSeqs];
            }
            $cursor += $size;
        }
        throw new GenerationException("VPN scenario sequence {$seq} out of range.");
    }

    private function correlateVpnProblemAndChange(RunContext $context, int $eventIndex, array $eventSeqs): void
    {
        $ticketIds = $context->registeredIds('Ticket', $this->getPhase(), 'vpn_outage');
        $clusterTicketIds = [];
        foreach ($eventSeqs as $s) {
            if (isset($ticketIds[$s])) {
                $clusterTicketIds[] = $ticketIds[$s];
            }
        }

        $requesterId = $this->randomUserByProfile($context, 'Technician', $eventIndex + 5000);
        $entitiesId = $this->actors->entityForRequester($requesterId);

        $problem = new Problem();
        $problemId = $problem->add([
            'name'          => 'VPN outage - event #' . ($eventIndex + 1),
            'content'       => 'Correlated VPN outage incidents for a single service disruption event.',
            'entities_id'   => $entitiesId,
            'priority'      => 5,
            'status'        => CommonITILObject::CLOSED,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($problemId, 'Problem', $eventIndex);

        foreach ($clusterTicketIds as $ticketsId) {
            $link = new Problem_Ticket();
            $link->add(['problems_id' => (int) $problemId, 'tickets_id' => $ticketsId]);
        }
        $context->register($this->getPhase(), 'Problem', (int) $problemId, 'vpn_outage');

        $change = new Change();
        $changeId = $change->add([
            'name'          => 'Emergency VPN infrastructure fix - event #' . ($eventIndex + 1),
            'content'       => 'Emergency change to restore VPN service, approved near-simultaneously with the outage.',
            'entities_id'   => $entitiesId,
            'priority'      => 5,
            'status'        => CommonITILObject::CLOSED,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($changeId, 'Change', $eventIndex);

        $changeProblemLink = new Change_Problem();
        $changeProblemLink->add(['changes_id' => (int) $changeId, 'problems_id' => (int) $problemId]);

        $cabGroupId = $this->groupIdByName($context, 'Security & Infrastructure CAB');
        $this->assignGroup((int) $changeId, Change_Group::class, 'changes_id', $cabGroupId);

        $this->approveChangeValidation((int) $changeId, $requesterId, $cabGroupId, date('Y-m-d H:i:s'));

        $context->register($this->getPhase(), 'Change', (int) $changeId, 'vpn_outage');
    }

    // --- Scenario 5: Onboarding ------------------------------------------------

    private function createOnboardingRequest(RunContext $context, int $seq): void
    {
        $allUserIds = $context->registeredIds('User', GenerationPhase::ORG_STRUCTURE);
        $onboardingCohortSize = $context->profile->usersOnboardingCohort;
        $onboardingIds = array_slice($allUserIds, -$onboardingCohortSize);

        if (!isset($onboardingIds[$seq])) {
            throw new GenerationException("No onboarding-cohort user at sequence {$seq}.");
        }
        $requesterId = $onboardingIds[$seq];
        $entitiesId = $this->actors->entityForRequester($requesterId);

        $user = new \User();
        $user->getFromDB($requesterId);
        $beginDate = $user->fields['begin_date'] ?? date('Y-m-d H:i:s');

        $ticket = new Ticket();
        $id = $ticket->add([
            'name'          => 'New employee onboarding setup',
            'content'       => 'New hire onboarding request: account, equipment, and access provisioning.',
            'type'          => Ticket::DEMAND_TYPE,
            'priority'      => 3,
            'status'        => CommonITILObject::CLOSED,
            'entities_id'   => $entitiesId,
            'date'          => $beginDate,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($id, 'Ticket', $seq);

        $supervisorId = $this->randomUserByProfile($context, 'Supervisor', $seq + 6000);
        $validation = new \TicketValidation();
        $validationId = $validation->add([
            'tickets_id'      => (int) $id,
            'users_id'        => $requesterId,
            'itemtype_target' => 'User',
            'items_id_target' => $supervisorId,
            'users_id_validate' => $supervisorId,
            'submission_date' => $beginDate,
        ]);
        $this->assertCreated($validationId, 'TicketValidation', $seq);
        $this->forceAcceptValidation('glpi_ticketvalidations', (int) $validationId, $beginDate);

        $context->register($this->getPhase(), 'Ticket', (int) $id, 'onboarding');
    }

    // --- Scenario 6: Offboarding ------------------------------------------------

    private function createOffboardingRequest(RunContext $context, int $seq): void
    {
        $allUserIds = $context->registeredIds('User', GenerationPhase::ORG_STRUCTURE);
        $vipCount = (int) round($context->profile->usersTotal * (18 / 500));
        $exitedIds = array_slice($allUserIds, $vipCount, $context->profile->usersExited);

        if (!isset($exitedIds[$seq])) {
            throw new GenerationException("No exited-cohort user at sequence {$seq}.");
        }
        $exitedUserId = $exitedIds[$seq];

        $exitedUser = new \User();
        $exitedUser->getFromDB($exitedUserId);
        $endDate = $exitedUser->fields['end_date'] ?? date('Y-m-d H:i:s');

        $supervisorId = $this->randomUserByProfile($context, 'Supervisor', $seq + 7000);
        $entitiesId = $this->actors->entityForRequester($supervisorId);

        $ticket = new Ticket();
        $id = $ticket->add([
            'name'          => 'Employee offboarding - account and equipment deactivation',
            'content'       => sprintf(
                'Offboarding request for %s %s: deactivate accounts and reclaim assigned equipment.',
                $exitedUser->fields['firstname'] ?? '',
                $exitedUser->fields['realname'] ?? ''
            ),
            'type'          => Ticket::DEMAND_TYPE,
            'priority'      => 3,
            'status'        => CommonITILObject::CLOSED,
            'entities_id'   => $entitiesId,
            'date'          => $endDate,
            '_users_id_requester' => $supervisorId,
        ]);
        $this->assertCreated($id, 'Ticket', $seq);

        // Briefly assign then reclaim one asset, so the "unassigned on
        // offboarding" narrative has something real to point at - CmdbBuilder
        // does not pre-assign asset ownership (assets aren't tied to a
        // specific user by default), so this scenario creates that
        // ownership itself before reversing it.
        $this->reclaimOneAsset($context, $exitedUserId, $seq);

        $context->register($this->getPhase(), 'Ticket', (int) $id, 'offboarding');
    }

    private function reclaimOneAsset(RunContext $context, int $exitedUserId, int $seq): void
    {
        $computerIds = $context->registeredIds('Computer', GenerationPhase::CMDB);
        if (count($computerIds) === 0) {
            return;
        }
        $computersId = $computerIds[$seq % count($computerIds)];

        $computer = new Computer();
        if (!$computer->getFromDB($computersId)) {
            return;
        }
        $computer->update(['id' => $computersId, 'users_id' => $exitedUserId]);
        $computer->update(['id' => $computersId, 'users_id' => 0]);
    }

    // --- Scenario 7: Laptop replacement -----------------------------------------

    private function createLaptopReplacement(RunContext $context, int $seq): void
    {
        $retiredComputerIds = $context->registeredIds('Computer', GenerationPhase::CMDB, 'retired_computer');
        if (!isset($retiredComputerIds[$seq])) {
            throw new GenerationException("No retired computer at sequence {$seq}.");
        }
        $computersId = $retiredComputerIds[$seq];

        $requesterId = $this->randomUser($context, $seq + 8000);
        $entitiesId = $this->actors->entityForRequester($requesterId);

        $ticket = new Ticket();
        $ticketId = $ticket->add([
            'name'          => 'Laptop replacement request - hardware retired',
            'content'       => 'Requesting a replacement laptop; current device has reached end of life and is being retired.',
            'type'          => Ticket::DEMAND_TYPE,
            'priority'      => 2,
            'status'        => CommonITILObject::CLOSED,
            'entities_id'   => $entitiesId,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($ticketId, 'Ticket', $seq);

        $itemLink = new Item_Ticket();
        $itemLink->add(['itemtype' => 'Computer', 'items_id' => $computersId, 'tickets_id' => (int) $ticketId]);

        $change = new Change();
        $changeId = $change->add([
            'name'          => 'Deploy replacement laptop',
            'content'       => 'Deploy and configure a replacement laptop for the requester; decommission the retired unit.',
            'entities_id'   => $entitiesId,
            'priority'      => 2,
            'status'        => CommonITILObject::CLOSED,
            '_users_id_requester' => $requesterId,
        ]);
        $this->assertCreated($changeId, 'Change', $seq);

        $changeTicketLink = new Change_Ticket();
        $changeTicketLink->add(['changes_id' => (int) $changeId, 'tickets_id' => (int) $ticketId]);

        $computer = new Computer();
        if ($computer->getFromDB($computersId)) {
            $computer->update([
                'id'      => $computersId,
                'comment' => trim(($computer->fields['comment'] ?? '') . "\nReplaced via ticket #{$ticketId} / change #{$changeId}."),
            ]);
        }

        $context->register($this->getPhase(), 'Ticket', (int) $ticketId, 'laptop_replacement');
        $context->register($this->getPhase(), 'Change', (int) $changeId, 'laptop_replacement');
    }

    // --- shared helpers ---------------------------------------------------------

    private function assignGroup(int $itemId, string $linkClass, string $fkField, int $groupsId): void
    {
        $link = new $linkClass();
        $link->add([$fkField => $itemId, 'groups_id' => $groupsId, 'type' => CommonITILActor::ASSIGN]);
    }

    /**
     * Creates a group-targeted ChangeValidation and immediately accepts it
     * (see forceAcceptValidation() for why that's a follow-up step, not
     * part of add()).
     */
    private function approveChangeValidation(int $changesId, int $requesterId, int $groupsId, string $date): void
    {
        $validation = new ChangeValidation();
        $id = $validation->add([
            'changes_id'      => $changesId,
            'users_id'        => $requesterId,
            'itemtype_target' => 'Group',
            'items_id_target' => $groupsId,
            'submission_date' => $date,
        ]);
        $this->assertCreated($id, 'ChangeValidation', $changesId);
        $this->forceAcceptValidation('glpi_changevalidations', (int) $id, $date);
    }

    /**
     * CommonITILValidation::prepareInputForUpdate() strips 'status' from
     * the input entirely unless canAnswer() is true - which checks whether
     * the *current session user* is this validation's actual target
     * (users_id_validate, or a member of the target group). Running as
     * Super-Admin to generate demo data, that's essentially never this
     * plugin's own session, so the normal update() path can never mark a
     * validation as accepted here - confirmed empirically (status stayed 2/
     * WAITING despite update() being called with status=>ACCEPTED). This is
     * a deliberate GLPI security gate (you shouldn't be able to self-approve
     * someone else's pending validation), not a bug, so working around it
     * through the object's own API isn't possible without impersonating
     * another user's session. A narrow raw UPDATE of just these two columns,
     * only ever run against a row this same method just created, is the
     * documented raw-SQL exception here.
     */
    private function forceAcceptValidation(string $table, int $id, string $date): void
    {
        global $DB;
        $DB->update($table, [
            'status'          => CommonITILValidation::ACCEPTED,
            'validation_date' => $date,
        ], ['id' => $id]);
    }

    private function groupIdByName(RunContext $context, string $name): int
    {
        static $cache = [];
        if (isset($cache[$name])) {
            return $cache[$name];
        }
        $groupIds = $context->registeredIds('Group', GenerationPhase::ORG_STRUCTURE);
        foreach ($groupIds as $groupsId) {
            $group = new \Group();
            if ($group->getFromDB($groupsId) && $group->fields['name'] === $name) {
                return $cache[$name] = $groupsId;
            }
        }
        throw new GenerationException("Group \"{$name}\" was not created by OrgStructureBuilder.");
    }

    private function randomUser(RunContext $context, int $seq): int
    {
        $ids = $this->activeUserIds($context);
        if (count($ids) === 0) {
            throw new GenerationException('No active Users exist yet.');
        }
        $rng = new RandomDataProvider($context->seed());
        return $ids[$rng->intBetween(0, count($ids) - 1, $seq)];
    }

    /**
     * @return int[] Registered User ids that will actually pass
     *               User::isValidUserForEntity() as an actor - not just
     *               the entity/recursion check §5 is about, but also
     *               is_active=1 and a valid begin_date/end_date window.
     *               Picking an exited user as a scenario requester produces
     *               the exact same silently-missing-actor-link symptom via
     *               a different root cause; confirmed empirically (1 of 58
     *               scenario tickets had no requester link before this).
     */
    private function activeUserIds(RunContext $context): array
    {
        static $cache = [];
        $cacheKey = $context->runId();
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        global $DB;
        $userIds = $context->registeredIds('User', GenerationPhase::ORG_STRUCTURE);
        if (count($userIds) === 0) {
            return [];
        }

        $ids = [];
        foreach ($DB->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_users',
            'WHERE'  => [
                'id'         => $userIds,
                'is_deleted' => 0,
                'is_active'  => 1,
                ['OR' => [['begin_date' => null], ['begin_date' => ['<', new \Glpi\DBAL\QueryExpression('NOW()')]]]],
                ['OR' => [['end_date' => null], ['end_date' => ['>', new \Glpi\DBAL\QueryExpression('NOW()')]]]],
            ],
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $cache[$cacheKey] = $ids;
    }

    /** @return array<int,int> profile name => cached user id list, keyed internally */
    private function randomUserByProfile(RunContext $context, string $profileName, int $seq): int
    {
        static $cache = [];
        $cacheKey = $context->runId() . '|' . $profileName;
        if (!isset($cache[$cacheKey])) {
            $cache[$cacheKey] = $this->userIdsByProfile($context, $profileName);
        }
        $ids = $cache[$cacheKey];
        if (count($ids) === 0) {
            throw new GenerationException("No registered users with profile \"{$profileName}\".");
        }
        $rng = new RandomDataProvider($context->seed());
        return $ids[$rng->intBetween(0, count($ids) - 1, $seq)];
    }

    /** @return int[] Restricted to activeUserIds() - see its docblock. */
    private function userIdsByProfile(RunContext $context, string $profileName): array
    {
        global $DB;

        $userIds = $this->activeUserIds($context);
        if (count($userIds) === 0) {
            return [];
        }

        $matched = [];
        foreach ($DB->request([
            'SELECT' => 'glpi_profiles_users.users_id',
            'FROM'   => 'glpi_profiles_users',
            'INNER JOIN' => [
                'glpi_profiles' => ['ON' => ['glpi_profiles_users' => 'profiles_id', 'glpi_profiles' => 'id']],
            ],
            'WHERE' => [
                'glpi_profiles.name' => $profileName,
                'glpi_profiles_users.users_id' => $userIds,
            ],
        ]) as $row) {
            $matched[] = (int) $row['users_id'];
        }
        return $matched;
    }

    private function assertCreated($id, string $itemtype, int $seq): void
    {
        if (!$id) {
            throw new GenerationException("Failed to create {$itemtype} at sequence {$seq}.");
        }
    }
}
