<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;

/**
 * AT-479 / rental-takeon-import.md R4 — the numbers in the review screen's sticky header and the one-sentence "next
 * action". Always worked out on the server from the saved rows, so the header cannot drift from what Import will do.
 */
final class ReosRunSummary
{
    /**
     * @return array{total:int, selected:int, blocked:int, ready:int, to_create_properties:int, to_create_contacts:int, to_update:int, to_create_leases:int, imported:int, failed:int, waiting:int, next_action:string, can_import:bool}
     */
    public static function for(RentalTakeOnImportRun $run): array
    {
        $rows = RentalTakeOnImportRow::query()
            ->where('run_id', $run->id)
            ->get(['id', 'status', 'selected', 'is_blocked', 'existing_lease_id', 'property_match_action', 'decisions_json', 'landlord_match_json', 'tenant_match_json', 'import_error', 'result_action']);

        $s = ['total' => $rows->count(), 'selected' => 0, 'blocked' => 0, 'ready' => 0, 'to_create_properties' => 0, 'to_create_contacts' => 0,
            'to_update' => 0, 'to_create_leases' => 0, 'imported' => 0, 'failed' => 0, 'waiting' => 0, 'name_differs' => 0, 'spelling_differs' => 0];
        $contactKeys = [];

        foreach ($rows as $row) {
            if ($row->status === RentalTakeOnImportRow::STATUS_CONFIRMED) {
                $s['imported']++;
                continue;
            }
            $s['waiting']++;
            if (($row->decisions_json['name_differs'] ?? false) === true) {
                $s['name_differs']++;
            }
            if (($row->decisions_json['spelling_differs'] ?? false) === true) {
                $s['spelling_differs']++;
            }
            if ($row->import_error) {
                $s['failed']++;
            }
            if (! $row->selected) {
                continue;
            }
            $s['selected']++;
            if ($row->is_blocked) {
                $s['blocked']++;
                continue;
            }
            $s['ready']++;

            if ($row->existing_lease_id) {
                $s['to_update']++;
                continue;
            }
            $s['to_create_leases']++;
            $d = $row->decisions_json ?? [];
            if ($row->property_match_action !== RentalTakeOnImportRow::ACTION_MATCH && ($d['property']['create'] ?? true)) {
                $s['to_create_properties']++;
            }
            foreach (['owner' => $row->landlord_match_json, 'tenant' => $row->tenant_match_json] as $party => $match) {
                if (($d[$party]['action'] ?? null) === 'create' && ! empty($match[1]['name'])) {
                    // One owner on five leases is one contact: count each name once.
                    $contactKeys[ReosContactNames::key($match[1]['name'])] = true;
                }
            }
        }
        $s['to_create_contacts'] = count($contactKeys);

        if ($s['total'] === 0) {
            $next = 'This batch has no rows.';
        } elseif ($s['selected'] === 0) {
            $next = $s['waiting'] === 0 ? 'Everything in this batch has been imported.' : 'Tick the leases you want to import.';
        } elseif ($s['blocked'] > 0) {
            $next = $s['blocked'] === 1 ? 'Fix 1 blocked lease (or untick it) to continue.' : "Fix {$s['blocked']} blocked leases (or untick them) to continue.";
        } else {
            $next = 'Ready — import ' . $s['ready'] . ($s['ready'] === 1 ? ' lease.' : ' leases.');
        }

        return $s + ['next_action' => $next, 'can_import' => $s['selected'] > 0 && $s['blocked'] === 0];
    }
}
