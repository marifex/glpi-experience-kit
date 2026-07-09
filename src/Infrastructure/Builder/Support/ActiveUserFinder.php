<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Builder\Support;

use DBmysql;
use Glpi\DBAL\QueryExpression;
use GlpiPlugin\Experiencekit\Application\RunContext;
use GlpiPlugin\Experiencekit\Domain\GenerationPhase;

/**
 * Restricts a run's registered Users to the ones that will actually pass
 * User::isValidUserForEntity() as an ITIL actor - not just the entity/
 * recursion check docs/reference §5 is about, but also is_active=1 and a
 * valid begin_date/end_date window. Picking an exited or not-yet-active
 * user as a requester produces the exact same silently-missing-actor-link
 * symptom via a different root cause; confirmed empirically (1 of 58
 * scenario tickets had no requester link before every requester-picking
 * call site in this plugin was routed through this class).
 */
final class ActiveUserFinder
{
    /** @var array<int,int[]> runId => user ids, cached per request. */
    private array $cache = [];

    public function __construct(private readonly DBmysql $db)
    {
    }

    /** @return int[] */
    public function activeUserIds(RunContext $context): array
    {
        $runId = $context->runId();
        if (isset($this->cache[$runId])) {
            return $this->cache[$runId];
        }

        $userIds = $context->registeredIds('User', GenerationPhase::ORG_STRUCTURE);
        if (count($userIds) === 0) {
            return $this->cache[$runId] = [];
        }

        $ids = [];
        foreach ($this->db->request([
            'SELECT' => 'id',
            'FROM'   => 'glpi_users',
            'WHERE'  => [
                'id'         => $userIds,
                'is_deleted' => 0,
                'is_active'  => 1,
                ['OR' => [['begin_date' => null], ['begin_date' => ['<', new QueryExpression('NOW()')]]]],
                ['OR' => [['end_date' => null], ['end_date' => ['>', new QueryExpression('NOW()')]]]],
            ],
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $this->cache[$runId] = $ids;
    }

    public function random(RunContext $context, int $seq): int
    {
        $ids = $this->activeUserIds($context);
        if (count($ids) === 0) {
            throw new \GlpiPlugin\Experiencekit\Domain\Exception\GenerationException('No active Users exist yet.');
        }
        $rng = new RandomDataProvider($context->seed());
        return $ids[$rng->intBetween(0, count($ids) - 1, $seq)];
    }

    /** @return int[] Active user ids that also hold $profileName. */
    public function idsByProfile(RunContext $context, string $profileName): array
    {
        $activeIds = $this->activeUserIds($context);
        if (count($activeIds) === 0) {
            return [];
        }

        $matched = [];
        foreach ($this->db->request([
            'SELECT' => 'glpi_profiles_users.users_id',
            'FROM'   => 'glpi_profiles_users',
            'INNER JOIN' => [
                'glpi_profiles' => ['ON' => ['glpi_profiles_users' => 'profiles_id', 'glpi_profiles' => 'id']],
            ],
            'WHERE' => [
                'glpi_profiles.name' => $profileName,
                'glpi_profiles_users.users_id' => $activeIds,
            ],
        ]) as $row) {
            $matched[] = (int) $row['users_id'];
        }
        return $matched;
    }

    public function randomByProfile(RunContext $context, string $profileName, int $seq): int
    {
        static $cache = [];
        $cacheKey = $context->runId() . '|' . $profileName;
        if (!isset($cache[$cacheKey])) {
            $cache[$cacheKey] = $this->idsByProfile($context, $profileName);
        }
        $ids = $cache[$cacheKey];
        if (count($ids) === 0) {
            throw new \GlpiPlugin\Experiencekit\Domain\Exception\GenerationException("No active users with profile \"{$profileName}\".");
        }
        $rng = new RandomDataProvider($context->seed());
        return $ids[$rng->intBetween(0, count($ids) - 1, $seq)];
    }
}
