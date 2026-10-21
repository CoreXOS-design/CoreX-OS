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

    /**
     * True while the row is still going to use a CoreX contact that was matched under a different name (owner or tenant). Once the
     * user creates a new contact, leaves the person out or links another contact, there is nothing left to warn about.
     */
    public static function nameDiffers(RentalTakeOnImportRow $row, array $flags = ReosNameCompare::AMBER): bool
    {
        $d = $row->decisions_json ?? [];
        foreach (['owner' => $row->landlord_match_json, 'tenant' => $row->tenant_match_json] as $party => $match) {
            $m = $match[1] ?? null;
            // A difference the user has answered "same person" to (decisions_json.<party>.confirmed) is settled, not outstanding.
            if ($m && ! empty($m['name_flag']) && in_array($m['name_flag'], $flags, true) && empty($d[$party]['confirmed'])
                && ReosQuestions::usesMatched($d[$party] ?? [], $m)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stores the blockers on the row, and with them what is still open (spec: reOS review screen rebuild): `needs` / `needs_count` /
     * `hard` on decisions_json, and `selected` = the lease is READY (nothing open, nothing wrong) — the import takes exactly the
     * selected, unblocked, pending rows, so "ready" is what gets imported.
     */
    public static function refresh(RentalTakeOnImportRow $row): RentalTakeOnImportRow
    {
        $blockers = self::compute($row);
        $open = ReosQuestions::open($row, $blockers);
        $d = $row->decisions_json ?? [];
        $d['name_differs'] = self::nameDiffers($row, ReosNameCompare::AMBER);
        $d['spelling_differs'] = self::nameDiffers($row, [ReosNameCompare::SPELLING]);
        $d['needs'] = array_column($open, 'key');
        $d['needs_count'] = count($open);
        $d['hard'] = ReosQuestions::isHard($blockers);

        $imported = $row->status === RentalTakeOnImportRow::STATUS_CONFIRMED;
        $ready = ! $imported && $row->status !== RentalTakeOnImportRow::STATUS_ERROR && ! $row->import_error && $d['hard'] === false && $open === [] && $blockers === [];
        $row->forceFill([
            'blockers_json' => $blockers ?: null,
            'is_blocked' => $blockers !== [],
            'decisions_json' => $d,
            'selected' => $imported ? $row->selected : $ready,
        ])->save();

        return $row;
    }
}
