<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * .ai/specs/rental-inspections.md §45.5 (Build I-3) — thrown by RentalInspection::markCompleted() for an
 * in/out (and later interim) inspection when an expected party has no recorded attendance outcome
 * ("attended" or "did not attend"). Carries the structured list so the screen can name every party
 * left, never a bare 422. Same shape as RentalInspectionItemsUngradedException.
 *
 * @param array<int, array{key: string, party_role: string, name: string}> $missingParties
 */
final class RentalInspectionAttendanceMissingException extends \LogicException
{
    public function __construct(public readonly string $action, public readonly array $missingParties)
    {
        $names = collect($missingParties)
            ->map(fn (array $p) => ($p['name'] !== '' ? $p['name'] : 'Unnamed') . ' (' . $p['party_role'] . ')')
            ->implode(', ');

        parent::__construct(
            'Cannot ' . $action . ': attendance has not been recorded for ' . $names
            . '. Record whether each attended or did not attend, then try again.'
        );
    }
}
