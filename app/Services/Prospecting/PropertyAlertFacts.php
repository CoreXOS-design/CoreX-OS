<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\P24Listing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Scopes\AgencyScope;
use Illuminate\Support\Facades\DB;

/**
 * What the agency's e-mail alerts say about one property file (property-identity.md section 15, C1): current price, price changes, first and
 * last seen, mandated. Read from the agency's OWN alert rows only - an alert row is per agency, and this reads with the agency named, so another
 * agency's alerts for the same advert can never appear.
 *
 * An alert belongs to the property file when it was linked to it, or when its advert number is one of the file's adverts (so an alert that
 * arrived before the property file existed still shows once the advert is on it, without waiting for a relink).
 */
final class PropertyAlertFacts
{
    /**
     * @return list<array{portal:string, number:string, url:?string, price:float, original_price:?float, change_percent:?float, changes:list<array{date:string, old:float, new:float}>, first_seen:?string, last_seen:?string, times_seen:int, mandated:bool, status:?string, days_listed:?int}>
     */
    public function for(int $agencyId, TrackedProperty $tp): array
    {
        abort_unless((int) $tp->agency_id === $agencyId, 404);

        $refs = DB::table('tracked_property_external_refs')->where('agency_id', $agencyId)->where('tracked_property_id', $tp->id)
            ->whereNull('deleted_at')->whereNotNull('canonical_ref')->pluck('canonical_ref')->unique()->values()->all();

        $out = [];
        foreach (AlertPropertyLinker::PORTALS as $portal => $cfg) {
            $model = $cfg['model'];
            $rows = $model::withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $agencyId)
                ->where(function ($q) use ($tp, $refs) {
                    $q->where('tracked_property_id', $tp->id);
                    if ($refs) {
                        $q->orWhereIn('canonical_ref', $refs);
                    }
                })
                ->with(['priceChanges' => fn ($q) => $q->orderBy('change_date')->orderBy('id')])
                ->orderByDesc('last_seen_date')->get();

            foreach ($rows as $a) {
                $original = $a->original_price !== null ? (float) $a->original_price : null;
                $price = (float) $a->asking_price;
                $out[] = [
                    'portal' => $portal === 'p24' ? 'Property24' : 'Private Property',
                    'number' => (string) $a->{$cfg['number']},
                    'url' => $portal === 'p24' ? $a->p24_url : $a->pp_url,
                    'price' => $price,
                    'original_price' => $original,
                    'change_percent' => $original && $original != $price ? round((($price - $original) / $original) * 100, 1) : null,
                    'changes' => $a->priceChanges->map(fn ($c) => ['date' => $c->change_date->format('j M Y'), 'old' => (float) $c->old_price, 'new' => (float) $c->new_price])->all(),
                    'first_seen' => $a->first_seen_date?->format('j M Y'),
                    'last_seen' => $a->last_seen_date?->format('j M Y'),
                    'times_seen' => (int) $a->times_seen,
                    'mandated' => (bool) $a->is_mandated,
                    'status' => $a->listing_status,
                    'days_listed' => $a->first_seen_date ? (int) $a->first_seen_date->diffInDays(now()) : null,
                ];
            }
        }

        return $out;
    }
}
