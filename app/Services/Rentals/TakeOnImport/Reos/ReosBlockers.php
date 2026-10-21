<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\Lease;
use App\Models\RentalTakeOnImportRow;

/**
 * AT-479 / rental-takeon-import.md R5 — what stops a row being imported, worked out from the row and the user's choices.
 * Each blocker names the control that fixes it (`field` = the suffix of the element id `row-{id}-{field}`), so the review
 * screen can link the message to it. No database writes here.
 */
final class ReosBlockers
{
    public const F_SUBURB = 'suburb';
    public const F_AGENT = 'agent';
    public const F_PROPERTY_CREATE = 'property-create';
    public const F_PROPERTY_CHOICE = 'property-choice';

    /** @return list<array{code:string,message:string,field:?string}> */
    public static function compute(RentalTakeOnImportRow $row): array
    {
        $out = [];

        foreach (($row->errors_json ?? []) as $message) {
            $out[] = ['code' => 'file', 'message' => (string) $message . ' Fix it in the file and upload it again.', 'field' => null];
        }

        // An archived lease with this reOS id still holds the key in the database: it must be restored, not duplicated.
        $existing = $row->existing_lease_id ? Lease::withoutGlobalScopes()->withTrashed()->find($row->existing_lease_id) : null;
        if ($existing && $existing->trashed()) {
            $out[] = ['code' => 'archived_lease', 'message' => "This lease is already in CoreX but archived (lease #{$existing->id}). Restore it from the Leases list first — it cannot be imported a second time.", 'field' => null];
        }

        $updating = $existing !== null && ! $existing->trashed();
        $d = $row->decisions_json ?? [];

        if (! $updating && $row->status !== RentalTakeOnImportRow::STATUS_ERROR) {
            $matched = $row->property_match_action === RentalTakeOnImportRow::ACTION_MATCH;
            $createProperty = (bool) ($d['property']['create'] ?? true);

            if ($row->property_match_action === RentalTakeOnImportRow::ACTION_CHOOSE) {
                // Several CoreX properties fit: the user must say which (or that it is none of them) before anything else is asked.
                $out[] = ['code' => 'property_choice_required', 'message' => 'Several properties in CoreX fit this address — pick the right one, or "none of these".', 'field' => self::F_PROPERTY_CHOICE];
            } elseif (! $matched) {
                if (! $createProperty) {
                    $out[] = ['code' => 'property_required', 'message' => 'A lease needs a property — tick "Create this property".', 'field' => self::F_PROPERTY_CREATE];
                } elseif (empty($d['property']['suburb_id']) && empty($d['property']['suburb'])) {
                    $out[] = ['code' => 'suburb_required', 'message' => 'Pick the suburb for this new property.', 'field' => self::F_SUBURB];
                }
            }

            if (empty($d['agent_user_id'])) {
                $out[] = ['code' => 'agent_required', 'message' => 'Pick the agent for this lease.', 'field' => self::F_AGENT];
            }
        }

        return $out;
    }

    /** Stores the blockers on the row (no other column touched). */
    public static function refresh(RentalTakeOnImportRow $row): RentalTakeOnImportRow
    {
        $blockers = self::compute($row);
        $row->forceFill(['blockers_json' => $blockers ?: null, 'is_blocked' => $blockers !== []])->save();

        return $row;
    }
}
