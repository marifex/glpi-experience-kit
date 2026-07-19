<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Builder;

use Calendar;
use CalendarSegment;
use GlpiPlugin\Experiencekit\Application\RunContext;
use GlpiPlugin\Experiencekit\Domain\Exception\GenerationException;
use GlpiPlugin\Experiencekit\Domain\GenerationPhase;
use GlpiPlugin\Experiencekit\Infrastructure\Builder\Support\SequentialPhaseBuilder;
use ITILCategory;
use RuleCommonITILObject;
use RuleTicket;
use SLA;
use SLM;
use Ticket;

/**
 * Phase 3: ITIL categories, calendars, SLM/SLA, and the 22 RuleTicket
 * business rules. Depends on OrgStructure's entities/groups.
 *
 * The exact category tree, calendar hours, SLA tiers, and every rule's
 * criteria/actions were extracted directly from the original hand-built
 * dataset still present in this project's dev database (the same source
 * docs/reference/GLPI_DEMO_DATASET_DNA.md §6 describes) - this is the
 * ground-truth reference implementation, not a re-derivation from the
 * doc's prose alone. Only the technician group names differ, mapped onto
 * OrgStructureBuilder's own taxonomy instead of the original's.
 *
 * Like CmdbBuilder's taxonomy, every resource here is resolved
 * idempotently (find-by-name-or-create) and only registered for purge
 * when this run actually created it - a fresh install has none of these
 * yet and everything is created fresh, but re-running against an
 * environment that already has matching names (as this dev DB does, from
 * the original dataset) must not crash or duplicate.
 */
final class ItsmConfigBuilder extends SequentialPhaseBuilder
{
    /** @var array<string,array<int,string>> parent category name => child names */
    private const CATEGORY_TREE = [
        'Hardware' => ['Laptop/Desktop', 'Monitor', 'Printer', 'Mobile Device', 'Network Equipment'],
        'Software' => ['Installation Request', 'License/Access Issue', 'Bug/Error', 'Upgrade Request'],
        'Network & Connectivity' => ['VPN', 'Wifi', 'Internet/Circuit', 'Firewall/Security'],
        'Access & Account' => ['New Account/Onboarding', 'Password Reset', 'Permission Change', 'Account Deactivation/Offboarding'],
        'Facilities' => ['Office/Building', 'Furniture', 'Badge/Access Card', 'Cleaning/Maintenance'],
    ];

    /** @var array<string,array{begin:string,end:string,days:int[]}> */
    private const CALENDARS = [
        'Standard Business Hours' => ['begin' => '08:00:00', 'end' => '18:00:00', 'days' => [1, 2, 3, 4, 5]],
        '24/7 Support'            => ['begin' => '00:00:00', 'end' => '23:59:00', 'days' => [1, 2, 3, 4, 5, 6, 7]],
        'EMEA Business Hours'     => ['begin' => '08:00:00', 'end' => '17:00:00', 'days' => [1, 2, 3, 4, 5]],
    ];

    private const SLM_NAME = 'Default SLM';
    private const SLM_CALENDAR = 'Standard Business Hours';

    /** @var array<string,array{tto:array{number:int,unit:string},ttr:array{number:int,unit:string}}> */
    private const SLA_TIERS = [
        'Gold'   => ['tto' => ['number' => 1, 'unit' => 'hour'], 'ttr' => ['number' => 4, 'unit' => 'hour']],
        'Silver' => ['tto' => ['number' => 4, 'unit' => 'hour'], 'ttr' => ['number' => 24, 'unit' => 'hour']],
        'Bronze' => ['tto' => ['number' => 8, 'unit' => 'hour'], 'ttr' => ['number' => 72, 'unit' => 'hour']],
        'VIP'    => ['tto' => ['number' => 15, 'unit' => 'minute'], 'ttr' => ['number' => 2, 'unit' => 'hour']],
    ];

    /** name => id, resolved by ensureFoundation(). */
    private ?array $categoryIds = null;
    private ?array $calendarIds = null;
    private ?int $slmId = null;
    private ?array $slaIds = null; // "{Tier}-{Incident|Request}-{TTO|TTR}" => id

    public function getPhase(): GenerationPhase
    {
        return GenerationPhase::ITSM_CONFIG;
    }

    /**
     * NOTE: unlike OrgStructureBuilder/CmdbBuilder, this phase reports no
     * per-record stages at all - everything (categories, calendars, SLM,
     * SLA, and all 22 rules) is resolved unchunked inside ensureFoundation().
     * This is deliberate, not an oversight: SequentialPhaseBuilder's
     * resumability tracks "how many of target N are registered", but a
     * rule whose name collides with a pre-existing one (as several do in
     * this dev DB, and could on any GLPI instance with prior data) is
     * correctly never registered - so registeredCount can never reach the
     * target, and the phase would spin forever re-attempting the same
     * already-satisfied rules on every batch tick. Confirmed empirically:
     * completed_units reached 367 against a total_units of 22 before this
     * was fixed. The whole phase is small and fixed-size regardless of
     * volume profile (68 items, not thousands of tickets), so doing it in
     * one synchronous pass - like CmdbBuilder's taxonomy - is safe.
     */
    protected function stages(RunContext $context): array
    {
        $this->ensureFoundation($context);
        return [];
    }

    private function ensureFoundation(RunContext $context): void
    {
        if ($this->categoryIds !== null) {
            return;
        }

        $rootId = $this->orgRootEntityId($context);

        $this->categoryIds = [];
        foreach (self::CATEGORY_TREE as $parent => $children) {
            $parentId = $this->findOrCreateCategory($parent, 0, $rootId);
            $this->categoryIds[$parent] = $parentId;
            foreach ($children as $child) {
                $this->categoryIds[$child] = $this->findOrCreateCategory($child, $parentId, $rootId);
            }
        }

        $this->calendarIds = [];
        foreach (self::CALENDARS as $name => $spec) {
            $this->calendarIds[$name] = $this->findOrCreateCalendar($name, $spec, $rootId);
        }

        $this->slmId = $this->findOrCreateSlm($rootId);

        $this->slaIds = [];
        foreach (self::SLA_TIERS as $tier => $times) {
            foreach (['Incident', 'Request'] as $ticketType) {
                foreach (['tto' => 'TTO', 'ttr' => 'TTR'] as $key => $label) {
                    $name = "{$tier}-{$ticketType}-{$label}";
                    $this->slaIds[$name] = $this->findOrCreateSla($name, $times[$key], $key === 'tto' ? SLM::TTO : SLM::TTR, $rootId);
                }
            }
        }

        foreach (array_keys($this->ruleDefinitions($context)) as $seq) {
            $this->createRule($context, $seq);
        }
    }

    private function findOrCreateCategory(string $name, int $parentId, int $entitiesId): int
    {
        $category = new ITILCategory();
        $rows = $category->find(['name' => $name, 'itilcategories_id' => $parentId], [], 1);
        if (count($rows) > 0) {
            return (int) array_key_first($rows);
        }

        $id = $category->add([
            'name'              => $name,
            'entities_id'       => $entitiesId,
            'is_recursive'      => 1,
            'itilcategories_id' => $parentId,
            'is_helpdeskvisible' => 1,
        ]);
        $this->assertCreated($id, 'ITILCategory', $name);
        return (int) $id;
    }

    /** @param array{begin:string,end:string,days:int[]} $spec */
    private function findOrCreateCalendar(string $name, array $spec, int $entitiesId): int
    {
        $calendar = new Calendar();
        $rows = $calendar->find(['name' => $name], [], 1);
        if (count($rows) > 0) {
            return (int) array_key_first($rows);
        }

        $id = $calendar->add(['name' => $name, 'entities_id' => $entitiesId, 'is_recursive' => 1]);
        $this->assertCreated($id, 'Calendar', $name);

        foreach ($spec['days'] as $day) {
            $segment = new CalendarSegment();
            $segment->add([
                'calendars_id' => (int) $id,
                'entities_id'  => $entitiesId,
                'is_recursive' => 1,
                'day'          => $day,
                'begin'        => $spec['begin'],
                'end'          => $spec['end'],
            ]);
        }

        return (int) $id;
    }

    private function findOrCreateSlm(int $entitiesId): int
    {
        $slm = new SLM();
        $rows = $slm->find(['name' => self::SLM_NAME], [], 1);
        if (count($rows) > 0) {
            return (int) array_key_first($rows);
        }

        $id = $slm->add([
            'name'               => self::SLM_NAME,
            'entities_id'        => $entitiesId,
            'is_recursive'       => 1,
            'calendars_id'       => $this->calendarIds[self::SLM_CALENDAR],
            'use_ticket_calendar' => 0,
        ]);
        $this->assertCreated($id, 'SLM', self::SLM_NAME);
        return (int) $id;
    }

    /** @param array{number:int,unit:string} $time */
    private function findOrCreateSla(string $name, array $time, int $type, int $entitiesId): int
    {
        $sla = new SLA();
        $rows = $sla->find(['name' => $name], [], 1);
        if (count($rows) > 0) {
            return (int) array_key_first($rows);
        }

        $id = $sla->add([
            'name'               => $name,
            'entities_id'        => $entitiesId,
            'is_recursive'       => 1,
            'slms_id'            => $this->slmId,
            'type'               => $type,
            'number_time'        => $time['number'],
            'definition_time'    => $time['unit'],
            'calendars_id'       => $this->calendarIds[self::SLM_CALENDAR],
            'use_ticket_calendar' => 0,
        ]);
        $this->assertCreated($id, 'SLA', $name);
        return (int) $id;
    }

    private function createRule(RunContext $context, int $seq): void
    {
        $definitions = $this->ruleDefinitions($context);
        if (!isset($definitions[$seq])) {
            throw new GenerationException("No rule definition for sequence {$seq}.");
        }

        [$name, $criteria, $actions] = $definitions[$seq];

        // NOT Migration::createRule(): its internal duplicate/validity
        // guard is `is_a($rule['sub_type'], Rule::class)`, and PHP's is_a()
        // returns false for a class-name string unless $allow_string=true
        // is passed - which createRule() never does. Confirmed by testing:
        // calling it with 'sub_type' => RuleTicket::class (a string, the
        // only sensible way to call it) makes that check always fail,
        // silently no-op'ing every rule after the first name collision.
        // Building the rule directly via RuleTicket/RuleCriteria/RuleAction
        // ->add() sidesteps the bug and is more in line with this plugin's
        // "always use GLPI's own business-logic classes" principle anyway.
        $existingRows = (new RuleTicket())->find(['name' => $name, 'sub_type' => RuleTicket::class], [], 1);
        if (count($existingRows) > 0) {
            // Already exists (e.g. this dev DB has the original dataset's
            // rules) - this run did not create it, so it must not be
            // registered for purge.
            return;
        }

        $rule = new RuleTicket();
        $rid = $rule->add([
            'name'      => $name,
            'match'     => 'AND',
            'condition' => RuleCommonITILObject::ONADD,
            'is_active' => 1,
            'comment'   => 'Generated by Experience Kit for GLPI.',
        ]);
        $this->assertCreated($rid, 'RuleTicket', $name);

        foreach ($criteria as $criterion) {
            $ruleCriteria = new \RuleCriteria();
            $ruleCriteria->add(['rules_id' => (int) $rid] + $criterion);
        }
        foreach ($actions as $action) {
            $ruleAction = new \RuleAction();
            $ruleAction->add(['rules_id' => (int) $rid] + $action);
        }

        $context->register($this->getPhase(), 'RuleTicket', (int) $rid);
    }

    /**
     * The 22 rules, in order. Group names reference OrgStructureBuilder's
     * own taxonomy (see its SUPPORT_GROUPS/VIP_GROUP constants) - not the
     * original dataset's differently-named teams.
     *
     * @return array<int,array{0:string,1:array,2:array}>
     */
    private function ruleDefinitions(RunContext $context): array
    {
        $cat = fn (string $name) => $this->categoryIds[$name];
        $sla = fn (string $name) => $this->slaIds[$name];
        $group = fn (string $name) => $this->groupIdByName($context, $name);
        $vipGroup = fn () => $this->groupIdByName($context, 'VIP Requesters');

        $CONTAIN = 2; // Rule::PATTERN_CONTAIN
        $IS = 0;      // Rule::PATTERN_IS
        $UNDER = 11;  // Rule::PATTERN_UNDER

        $keywordRules = [
            ['printer', 'Printer', 'Service Desk - Tier 1', 3],
            ['vpn', 'VPN', 'Network Operations', 4],
            ['wifi', 'Wifi', 'Network Operations', 3],
            ['password', 'Password Reset', 'Service Desk - Tier 1', 2],
            ['install', 'Installation Request', 'Applications Support', 2],
            ['error', 'Bug/Error', 'Applications Support', 3],
            ['laptop', 'Laptop/Desktop', 'Service Desk - Tier 2', 3],
            ['no internet', 'Internet/Circuit', 'Network Operations', 5],
            ['onboarding', 'New Account/Onboarding', 'Service Desk - Tier 2', 3],
            ['offboarding', 'Account Deactivation/Offboarding', 'Service Desk - Tier 2', 3],
            ['security', 'Firewall/Security', 'Systems & Infrastructure Team', 5],
            ['badge', 'Badge/Access Card', 'Field Services', 2],
            ['phone', 'Mobile Device', 'Service Desk - Tier 1', 2],
            ['upgrade', 'Upgrade Request', 'Applications Support', 2],
        ];

        $definitions = [];
        foreach ($keywordRules as [$keyword, $category, $groupName, $priority]) {
            $definitions[] = [
                ucfirst(trim($keyword)) . ' issue routing',
                [['criteria' => 'name', 'condition' => $CONTAIN, 'pattern' => $keyword]],
                [
                    ['action_type' => 'assign', 'field' => 'itilcategories_id', 'value' => $cat($category)],
                    ['action_type' => 'assign', 'field' => '_groups_id_assign', 'value' => $group($groupName)],
                    ['action_type' => 'assign', 'field' => 'priority', 'value' => $priority],
                ],
            ];
        }

        $definitions[] = [
            'Urgent keyword escalation',
            [['criteria' => 'content', 'condition' => $CONTAIN, 'pattern' => 'urgent']],
            [['action_type' => 'assign', 'field' => 'priority', 'value' => 5]],
        ];

        $definitions[] = [
            'VIP requester - Incident SLA',
            [
                ['criteria' => '_groups_id_of_requester', 'condition' => $IS, 'pattern' => $vipGroup()],
                ['criteria' => 'type', 'condition' => $IS, 'pattern' => Ticket::INCIDENT_TYPE],
            ],
            [
                ['action_type' => 'assign', 'field' => 'priority', 'value' => 5],
                ['action_type' => 'assign', 'field' => 'slas_id_tto', 'value' => $sla('VIP-Incident-TTO')],
                ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => $sla('VIP-Incident-TTR')],
            ],
        ];
        $definitions[] = [
            'VIP requester - Request SLA',
            [
                ['criteria' => '_groups_id_of_requester', 'condition' => $IS, 'pattern' => $vipGroup()],
                ['criteria' => 'type', 'condition' => $IS, 'pattern' => Ticket::DEMAND_TYPE],
            ],
            [
                ['action_type' => 'assign', 'field' => 'priority', 'value' => 4],
                ['action_type' => 'assign', 'field' => 'slas_id_tto', 'value' => $sla('VIP-Request-TTO')],
                ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => $sla('VIP-Request-TTR')],
            ],
        ];

        $tierRules = [
            ['Hardware incident - Gold SLA', 'Hardware', 'Gold'],
            ['Network incident - Gold SLA', 'Network & Connectivity', 'Gold'],
            ['Software incident - Silver SLA', 'Software', 'Silver'],
        ];
        foreach ($tierRules as [$name, $category, $tier]) {
            $definitions[] = [
                $name,
                [
                    ['criteria' => 'itilcategories_id', 'condition' => $UNDER, 'pattern' => $cat($category)],
                    ['criteria' => 'type', 'condition' => $IS, 'pattern' => Ticket::INCIDENT_TYPE],
                ],
                [
                    ['action_type' => 'assign', 'field' => 'slas_id_tto', 'value' => $sla("{$tier}-Incident-TTO")],
                    ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => $sla("{$tier}-Incident-TTR")],
                ],
            ];
        }

        $definitions[] = [
            'Default incident - Bronze SLA',
            [['criteria' => 'type', 'condition' => $IS, 'pattern' => Ticket::INCIDENT_TYPE]],
            [
                ['action_type' => 'assign', 'field' => 'slas_id_tto', 'value' => $sla('Bronze-Incident-TTO')],
                ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => $sla('Bronze-Incident-TTR')],
            ],
        ];
        $definitions[] = [
            'Default request - Bronze SLA',
            [['criteria' => 'type', 'condition' => $IS, 'pattern' => Ticket::DEMAND_TYPE]],
            [
                ['action_type' => 'assign', 'field' => 'slas_id_tto', 'value' => $sla('Bronze-Request-TTO')],
                ['action_type' => 'assign', 'field' => 'slas_id_ttr', 'value' => $sla('Bronze-Request-TTR')],
            ],
        ];

        return $definitions;
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

    private function orgRootEntityId(RunContext $context): int
    {
        $ids = $context->registeredIds('Entity', GenerationPhase::ORG_STRUCTURE);
        if (count($ids) === 0) {
            throw new GenerationException('Org root entity has not been created yet.');
        }
        return $ids[0];
    }

    private function assertCreated($id, string $itemtype, string $name): void
    {
        if (!$id) {
            throw new GenerationException("Failed to create {$itemtype} \"{$name}\".");
        }
    }
}
