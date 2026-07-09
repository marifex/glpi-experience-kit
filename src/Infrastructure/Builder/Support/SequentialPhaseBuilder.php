<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Infrastructure\Builder\Support;

use GlpiPlugin\Experiencekit\Application\BatchResult;
use GlpiPlugin\Experiencekit\Application\PhaseBuilderInterface;
use GlpiPlugin\Experiencekit\Application\RunContext;

/**
 * Shared plan()/runBatch() implementation for phase builders that create a
 * fixed, ordered list of "N records of itemtype X" stages, each one only
 * starting once the previous stage has reached its target count. Handles
 * batching (only process up to the remaining budget) and resumability
 * (each call re-derives "how far along" purely from the registry, so it's
 * safe to call across separate cron ticks/processes).
 */
abstract class SequentialPhaseBuilder implements PhaseBuilderInterface
{
    /**
     * @return array<int,array{itemtype:string,target:int,create:callable(int):void,tag?:string}>
     *         Ordered list of stages. $create is called with the stage-local
     *         sequence index (0-based) for each unit still needed. Building
     *         this array must be cheap and must not eagerly resolve data
     *         that only exists once an earlier stage has finished - do that
     *         lazily inside the $create closure itself. Set 'tag' when two
     *         stages register the same itemtype under this phase (e.g. two
     *         scenarios both creating Change records) - without it their
     *         progress would be conflated and neither target tracked
     *         correctly.
     */
    abstract protected function stages(RunContext $context): array;

    public function plan(RunContext $context): int
    {
        $total = 0;
        foreach ($this->stages($context) as $stage) {
            $total += $stage['target'];
        }
        return $total;
    }

    public function runBatch(RunContext $context, int $batchSize): BatchResult
    {
        $processed = 0;
        $remaining = $batchSize;
        $allDone = true;

        foreach ($this->stages($context) as $stage) {
            $tag = $stage['tag'] ?? null;
            if ($remaining > 0) {
                $remaining = $this->processStage($context, $remaining, $processed, $stage['itemtype'], $stage['target'], $stage['create'], $tag);
            }
            if ($context->registeredCount($stage['itemtype'], $this->getPhase(), $tag) < $stage['target']) {
                $allDone = false;
            }
        }

        return new BatchResult($processed, $allDone);
    }

    /** Processes up to $remaining units of one stage. Returns the batch budget left. */
    private function processStage(RunContext $context, int $remaining, int &$processed, string $itemtype, int $target, callable $createOne, ?string $tag): int
    {
        $existing = $context->registeredCount($itemtype, $this->getPhase(), $tag);
        $need = max(0, $target - $existing);
        $count = min($need, $remaining);

        for ($i = 0; $i < $count; $i++) {
            $createOne($existing + $i);
        }

        $processed += $count;
        return $remaining - $count;
    }
}
