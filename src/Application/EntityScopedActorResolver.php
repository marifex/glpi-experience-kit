<?php

declare(strict_types=1);

namespace GlpiPlugin\Experiencekit\Application;

use Change_User;
use CommonITILActor;
use DBmysql;
use GlpiPlugin\Experiencekit\Domain\Exception\GenerationException;
use Problem_User;
use Ticket_User;

/**
 * The only code path in this plugin allowed to compute entities_id for a
 * Ticket/Problem/Change, and the only one allowed to attach actor links
 * outside GLPI's normal updateActors() flow.
 *
 * Why this exists: GLPI 11's CommonITILObject::updateActors() gates every
 * User actor through User::isValidUserForEntity($userId, $entitiesId),
 * which only succeeds if the ITIL object's entity is the user's own entity
 * or a *descendant* of it (recursive rights cascade downward only). A
 * hardcoded/shared entities_id on the ITIL object - e.g. always the root
 * entity - silently fails this check for virtually every user, so the
 * requester actor link never gets created and no error is raised. See
 * docs/reference/GLPI_DEMO_DATASET_DNA.md §5 for the full story.
 *
 * The fix has two halves, both live here:
 *  - entityForRequester() - always derive entities_id from the chosen
 *    requester, never hardcode it. Used on the normal creation path.
 *  - addActorDirectly() - for remediating already-existing objects (which
 *    may be closed, so updateActors() would additionally block on
 *    canUpdateItem()), bypass updateActors() entirely and insert the raw
 *    *_User/*_Group link row. Confirmed empirically (§5) that the raw link
 *    classes do not replicate the entity-validity gate.
 */
final class EntityScopedActorResolver
{
    public function __construct(private readonly DBmysql $db)
    {
    }

    /**
     * The entity an ITIL object should be created in, derived from the
     * requester's own (first) profile/entity assignment.
     */
    public function entityForRequester(int $usersId): int
    {
        $row = $this->db->request([
            'SELECT' => 'entities_id',
            'FROM'   => 'glpi_profiles_users',
            'WHERE'  => ['users_id' => $usersId],
            'ORDER'  => 'id ASC',
            'LIMIT'  => 1,
        ])->current();

        if ($row === null) {
            throw new GenerationException("User #{$usersId} has no profile/entity assignment; cannot derive entities_id.");
        }

        return (int) $row['entities_id'];
    }

    /**
     * Convenience wrapper for the common case: attach $usersId as the
     * requester on an already-existing ITIL object, bypassing
     * updateActors().
     */
    public function addRequesterDirectly(string $itilItemtype, int $itemsId, int $usersId): void
    {
        $this->addActorDirectly($itilItemtype, $itemsId, $usersId, CommonITILActor::REQUESTER);
    }

    /**
     * Inserts one actor link row directly via Ticket_User/Problem_User/
     * Change_User, skipping CommonITILObject::updateActors() and its
     * entity-validity + canUpdateItem() gates entirely.
     */
    public function addActorDirectly(string $itilItemtype, int $itemsId, int $usersId, int $type): void
    {
        [$linkClass, $fkField] = match ($itilItemtype) {
            'Ticket'  => [Ticket_User::class, 'tickets_id'],
            'Problem' => [Problem_User::class, 'problems_id'],
            'Change'  => [Change_User::class, 'changes_id'],
            default   => throw new GenerationException("Unsupported ITIL itemtype \"{$itilItemtype}\" for actor linking."),
        };

        $link = new $linkClass();
        $link->add([
            $fkField   => $itemsId,
            'users_id' => $usersId,
            'type'     => $type,
        ]);
    }
}
