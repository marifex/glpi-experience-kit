<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Domain;

use GlpiPlugin\Experiencekit\Domain\Exception\GenerationException;

/**
 * Small/Medium/Large presets. Medium reproduces the original dataset's
 * inventory (§3) exactly - it is the authoritative reference. Small and
 * Large are this plugin's own deliberately-chosen sets (the source dataset
 * only ever documents one volume), scaled for a quick smoke-test versus a
 * stress-test respectively. Structural/taxonomy counts that describe an
 * organization's *shape* rather than its *size* (entity count, calendar
 * count, ITIL category taxonomy, SLA tier structure, the fixed 14-brand
 * manufacturer list) are intentionally held constant across all three -
 * scaling those would produce a less realistic, not a "smaller", org.
 */
final class VolumeProfileFactory
{
    public const SMALL  = 'small';
    public const MEDIUM = 'medium';
    public const LARGE  = 'large';

    public static function names(): array
    {
        return [self::SMALL, self::MEDIUM, self::LARGE];
    }

    public static function make(string $name): VolumeProfile
    {
        return match ($name) {
            self::SMALL  => self::small(),
            self::MEDIUM => self::medium(),
            self::LARGE  => self::large(),
            default      => throw new GenerationException("Unknown volume profile \"{$name}\"."),
        };
    }

    public static function medium(): VolumeProfile
    {
        return new VolumeProfile(
            name: self::MEDIUM,
            entities: 4,
            locations: 18,
            groups: 21,
            usersTotal: 500,
            usersOnboardingCohort: 40,
            usersExited: 27,
            assetCounts: [
                'Computer'         => 400,
                'Monitor'          => 300,
                'NetworkEquipment' => 50,
                'Printer'          => 50,
                'Phone'            => 150,
                'Peripheral'       => 30,
            ],
            manufacturers: 14,
            software: 90,
            softwareLicenses: 130,
            contracts: 30,
            suppliers: 20,
            itilCategories: 26,
            calendars: 3,
            slas: 16,
            ticketsIncidents: 4800,
            ticketsRequests: 2700,
            problems: 131,
            changes: 250,
            kbArticles: 180,
            attachmentRate: 0.30,
            surveyRate: 0.30,
        );
    }

    public static function small(): VolumeProfile
    {
        return new VolumeProfile(
            name: self::SMALL,
            entities: 4,
            locations: 10,
            groups: 12,
            usersTotal: 60,
            usersOnboardingCohort: 6,
            usersExited: 4,
            assetCounts: [
                'Computer'         => 40,
                'Monitor'          => 30,
                'NetworkEquipment' => 8,
                'Printer'          => 8,
                'Phone'            => 15,
                'Peripheral'       => 4,
            ],
            manufacturers: 14,
            software: 20,
            softwareLicenses: 25,
            contracts: 8,
            suppliers: 8,
            itilCategories: 26,
            calendars: 3,
            slas: 16,
            ticketsIncidents: 480,
            ticketsRequests: 270,
            problems: 14,
            changes: 25,
            kbArticles: 30,
            attachmentRate: 0.30,
            surveyRate: 0.30,
        );
    }

    public static function large(): VolumeProfile
    {
        return new VolumeProfile(
            name: self::LARGE,
            entities: 4,
            locations: 18,
            groups: 21,
            usersTotal: 1500,
            usersOnboardingCohort: 100,
            usersExited: 80,
            assetCounts: [
                'Computer'         => 1200,
                'Monitor'          => 900,
                'NetworkEquipment' => 150,
                'Printer'          => 150,
                'Phone'            => 450,
                'Peripheral'       => 90,
            ],
            manufacturers: 14,
            software: 200,
            softwareLicenses: 300,
            contracts: 60,
            suppliers: 40,
            itilCategories: 26,
            calendars: 3,
            slas: 16,
            ticketsIncidents: 14400,
            ticketsRequests: 8100,
            problems: 390,
            changes: 750,
            kbArticles: 300,
            attachmentRate: 0.30,
            surveyRate: 0.30,
        );
    }
}
