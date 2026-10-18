<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * .ai/specs/rental-inspections.md §45.3 (Build I-1) — thrown by RentalInspection::startAwaitingSignature()
 * / markCompleted() when the agency's own all_items_required_to_complete setting is true (the default)
 * and at least one checklist item has not been graded on THIS inspection ("N/A" counts as graded).
 *
 * Carries the structured list, never just a message, so the screen can name every room and item and
 * jump to them — "not a bare 422". Same shape as RentalInspectionRequiredNotesMissingException.
 *
 * @param array<int, array{room_label: string, item_label: string, item_id: int, room_id: ?int}> $ungradedItems
 */
final class RentalInspectionItemsUngradedException extends \LogicException
{
    /** The plain-language message names rooms and counts only — the full item list is the structured payload. */
    public function __construct(public readonly string $action, public readonly array $ungradedItems)
    {
        $perRoom = collect($ungradedItems)
            ->groupBy('room_label')
            ->map(fn ($rows, $room) => $room . ' (' . count($rows) . ')')
            ->implode(', ');

        $count = count($ungradedItems);

        parent::__construct(
            'Cannot ' . $action . ': ' . $count . ($count === 1 ? ' checklist item has' : ' checklist items have')
            . ' not been recorded yet — ' . $perRoom . '. Record each one (Not applicable counts) and try again.'
        );
    }
}
