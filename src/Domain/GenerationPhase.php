<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Domain;

/**
 * The six data-generating phases, in the order they must run (each depends
 * on state - entity/group/user/category/SLA IDs - registered by the ones
 * before it). Matches the phase sequence from the original dataset's
 * generation recipe (§2.2), minus the one-off bootstrap step (this plugin
 * runs inside GLPI's own already-booted request) and the actor-link
 * retrofit (§5's fix is now permanent behavior in EntityScopedActorResolver
 * rather than a numbered phase).
 */
enum GenerationPhase: string
{
    case ORG_STRUCTURE          = 'org_structure';
    case CMDB                   = 'cmdb';
    case ITSM_CONFIG            = 'itsm_config';
    case SCENARIOS               = 'scenarios';
    case BULK_TICKETS           = 'bulk_tickets';
    case KB_ATTACHMENTS_SURVEYS = 'kb_attachments_surveys';

    /** @return self[] Phases in required execution order. */
    public static function ordered(): array
    {
        return self::cases();
    }

    public function label(): string
    {
        return match ($this) {
            self::ORG_STRUCTURE          => __('Organization structure', 'experiencekit'),
            self::CMDB                   => __('CMDB', 'experiencekit'),
            self::ITSM_CONFIG            => __('ITSM configuration', 'experiencekit'),
            self::SCENARIOS               => __('Narrative scenarios', 'experiencekit'),
            self::BULK_TICKETS           => __('Bulk tickets', 'experiencekit'),
            self::KB_ATTACHMENTS_SURVEYS => __('Knowledge base, attachments & surveys', 'experiencekit'),
        };
    }

    public function next(): ?self
    {
        $ordered = self::ordered();
        $index = array_search($this, $ordered, true);
        return $ordered[$index + 1] ?? null;
    }
}
