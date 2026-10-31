<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Suggested from your work" on the MIC Settings page: the suburbs where the agency already has real activity - properties of its own, claims, leads -
 * counted per suburb from cc5's per-file figures (`mic_file_stats`, own agency only). A suburb is suggested at 3 or more of those together. These are the
 * same signals cc4 uses for "working suburbs" (RefreshStatusPanelProvider::workingSuburbs), here with their counts. The figures are as of the last stats
 * run (claims and leads are not windowed to 12 months - cc4/cc5 can swap the source for a 12-month count behind this one method).
 */
final class SuggestedAreas
{
    public const MIN_TOTAL = 3;

    /** @return list<array{id:int, name:string, stock:int, claims:int, leads:int, total:int}> biggest first */
    public function forAgency(int $agencyId): array
    {
        if (! Schema::hasTable('mic_file_stats')) {
            return [];
        }

        return DB::table('mic_file_stats as f')->join('p24_suburbs as s', 's.id', '=', 'f.p24_suburb_id')->whereNull('s.deleted_at')
            ->where('f.agency_id', $agencyId)->groupBy('s.id', 's.name')
            ->havingRaw('SUM(f.own_stock) + SUM(f.claimed) + SUM(f.leads_total) >= ?', [self::MIN_TOTAL])
            ->selectRaw('s.id, s.name, SUM(f.own_stock) AS stock, SUM(f.claimed) AS claims, SUM(f.leads_total) AS leads')
            ->orderByRaw('SUM(f.own_stock) + SUM(f.claimed) + SUM(f.leads_total) DESC')->limit(30)->get()
            ->map(fn ($r) => ['id' => (int) $r->id, 'name' => MicWorkList::titleSuburb($r->name), 'stock' => (int) $r->stock, 'claims' => (int) $r->claims, 'leads' => (int) $r->leads,
                'total' => (int) $r->stock + (int) $r->claims + (int) $r->leads])->all();
    }
}
