<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Domain;

/**
 * The full set of target counts for one generation run. Every phase builder
 * reads its own slice of this; nothing about "how many of X to create" is
 * ever hardcoded inside a builder. Field names and Medium's values mirror
 * the original dataset's inventory table (§3) exactly, since that document
 * is the authoritative reference for what a "realistic enterprise demo"
 * looks like.
 */
final class VolumeProfile
{
    /**
     * @param array<string,int> $assetCounts itemtype => count, e.g.
     *                                        ['Computer' => 400, 'Monitor' => 300, ...]
     */
    public function __construct(
        public readonly string $name,
        public readonly int $entities,
        public readonly int $locations,
        public readonly int $groups,
        public readonly int $usersTotal,
        public readonly int $usersOnboardingCohort,
        public readonly int $usersExited,
        public readonly array $assetCounts,
        public readonly int $manufacturers,
        public readonly int $software,
        public readonly int $softwareLicenses,
        public readonly int $contracts,
        public readonly int $suppliers,
        public readonly int $itilCategories,
        public readonly int $calendars,
        public readonly int $slas,
        public readonly int $ticketsIncidents,
        public readonly int $ticketsRequests,
        public readonly int $problems,
        public readonly int $changes,
        public readonly int $kbArticles,
        public readonly float $attachmentRate,
        public readonly float $surveyRate,
    ) {
    }

    public function assetsTotal(): int
    {
        return array_sum($this->assetCounts);
    }

    public function ticketsTotal(): int
    {
        return $this->ticketsIncidents + $this->ticketsRequests;
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            entities: $data['entities'],
            locations: $data['locations'],
            groups: $data['groups'],
            usersTotal: $data['usersTotal'],
            usersOnboardingCohort: $data['usersOnboardingCohort'],
            usersExited: $data['usersExited'],
            assetCounts: $data['assetCounts'],
            manufacturers: $data['manufacturers'],
            software: $data['software'],
            softwareLicenses: $data['softwareLicenses'],
            contracts: $data['contracts'],
            suppliers: $data['suppliers'],
            itilCategories: $data['itilCategories'],
            calendars: $data['calendars'],
            slas: $data['slas'],
            ticketsIncidents: $data['ticketsIncidents'],
            ticketsRequests: $data['ticketsRequests'],
            problems: $data['problems'],
            changes: $data['changes'],
            kbArticles: $data['kbArticles'],
            attachmentRate: $data['attachmentRate'],
            surveyRate: $data['surveyRate'],
        );
    }
}
