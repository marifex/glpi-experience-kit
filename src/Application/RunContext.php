<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Application;

use GlpiPlugin\Experiencekit\Domain\GenerationPhase;
use GlpiPlugin\Experiencekit\Domain\VolumeProfile;
use GlpiPlugin\Experiencekit\Infrastructure\Persistence\RegistryRepository;
use PluginExperiencekitRun;

/**
 * Everything a phase builder needs for one batch: the run, its volume
 * profile, and read/write access to the registry of what's already been
 * created. Replaces the original scripts' per-phase state_phaseN.json
 * files - "what IDs exist so far" is answered by querying the registry
 * (memoized for this request only), not by re-deriving or caching it across
 * requests, so it stays correct even if a batch is retried after a crash.
 */
final class RunContext
{
    /** @var array<string,int[]> */
    private array $cache = [];

    public function __construct(
        public readonly PluginExperiencekitRun $run,
        public readonly VolumeProfile $profile,
        private readonly RegistryRepository $registry,
    ) {
    }

    public function runId(): int
    {
        return (int) $this->run->fields['id'];
    }

    public function seed(): int
    {
        return (int) $this->run->fields['seed'];
    }

    /** @return int[] items_id values already created by this run for $itemtype, in creation order. */
    public function registeredIds(string $itemtype, ?GenerationPhase $phase = null): array
    {
        $cacheKey = $itemtype . '|' . ($phase?->value ?? '*');
        return $this->cache[$cacheKey] ??= $this->registry->findItemsIdsForRun($this->runId(), $itemtype, $phase);
    }

    public function registeredCount(string $itemtype, ?GenerationPhase $phase = null): int
    {
        return count($this->registeredIds($itemtype, $phase));
    }

    /**
     * Registers a just-created GLPI object as belonging to this run. Every
     * object a builder creates must go through this before its batch
     * returns - it is the only thing that makes the object purge-safe.
     */
    public function register(GenerationPhase $phase, string $itemtype, int $itemsId, ?string $scenarioTag = null): void
    {
        $this->registry->register($this->runId(), $phase, $itemtype, $itemsId, $scenarioTag);
        unset($this->cache[$itemtype . '|' . $phase->value], $this->cache[$itemtype . '|*']);
    }
}
