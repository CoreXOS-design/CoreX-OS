<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\RentalTakeOnImportRun;

/**
 * AT-481 — the review screen's "for all" choices: the agent for all leases and the suburb for all new properties. Kept on the run
 * (counts_json.defaults, beside the other per-batch facts) so they survive a reload; ReosDecisionService::setDefaults gives them to the
 * leases and keeps them in step.
 */
final class ReosRunDefaults
{
    /** @return array{agent_user_id:?int, suburb_id:?int, suburb_label:?string} */
    public static function get(RentalTakeOnImportRun $run): array
    {
        $d = ($run->counts_json ?? [])['defaults'] ?? [];

        return [
            'agent_user_id' => ! empty($d['agent_user_id']) ? (int) $d['agent_user_id'] : null,
            'suburb_id' => ! empty($d['suburb_id']) ? (int) $d['suburb_id'] : null,
            'suburb_label' => $d['suburb_label'] ?? null,
        ];
    }

    /** @param array<string,mixed> $defaults */
    public static function store(RentalTakeOnImportRun $run, array $defaults): void
    {
        $counts = $run->fresh()->counts_json ?? [];
        $counts['defaults'] = $defaults;
        $run->forceFill(['counts_json' => $counts])->save();
    }
}
