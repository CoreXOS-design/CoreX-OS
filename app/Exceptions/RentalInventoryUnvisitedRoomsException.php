<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Johan, 2026-09-28, on property 5294/inventory 8 — "the red completion
 * warning must list the unchecked rooms by name, each clickable to jump to
 * that space." Thrown by RentalInventory::markCompleted() when
 * unvisitedRooms() (§12) is non-empty. Same shape as
 * RentalInspectionRequiredNotesMissingException — carries the structured
 * list (not just a message string) so the controller/frontend can build a
 * real, clickable list instead of parsing room names back out of a
 * human-readable sentence. Message text is UNCHANGED from before this
 * exception existed (a bare \LogicException with the same wording) — the
 * existing test suite already asserts it verbatim.
 *
 * @param array<int, array{id: int, label: string}> $rooms
 */
final class RentalInventoryUnvisitedRoomsException extends \LogicException
{
    public function __construct(public readonly array $rooms)
    {
        $names = collect($rooms)->pluck('label')->implode(', ');
        $verb = count($rooms) === 1 ? 'has' : 'have';

        parent::__construct("Cannot complete: {$names} {$verb} not been checked yet. Add items to it, or mark it as having nothing in it, before completing this inventory.");
    }
}
