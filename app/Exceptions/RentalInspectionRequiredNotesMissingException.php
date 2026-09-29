<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Property 5792, Johan: 46 empty "Notes (required)" fields, and the
 * inspection still reached awaiting_signature. Thrown by
 * RentalInspection::startAwaitingSignature()/markCompleted() when the
 * agency's own require_notes_blocks_progression setting is true (the
 * default) and at least one item's current observation on THIS
 * inspection has a condition that requires a note
 * (RentalInspectionSetting::conditionRequiresNotesFor()) but the note is
 * empty. Carries the structured list (not just a message string) so the
 * controller/frontend can name exactly which rooms and items are
 * outstanding — "not a silent block."
 *
 * @param array<int, array{room_label: string, item_label: string, item_id: int, room_id: ?int}> $missingNotes
 */
final class RentalInspectionRequiredNotesMissingException extends \LogicException
{
    public function __construct(public readonly string $action, public readonly array $missingNotes)
    {
        $list = collect($missingNotes)
            ->map(fn (array $m) => $m['room_label'] . ': ' . $m['item_label'])
            ->implode('; ');

        parent::__construct(
            'Cannot ' . $action . ': ' . count($missingNotes) . ' required note(s) are missing — ' . $list . '.'
        );
    }
}
