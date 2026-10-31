<?php

declare(strict_types=1);

namespace App\Services\Prospecting\MicWork;

use App\Models\ProspectingListing;
use App\Models\User;
use App\Services\Prospecting\MicWork\Contracts\PanelProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tile 10 "Neighbours of a sale" (contract 11.3.F / 11.9). The trigger list: a house REGISTERED at the deeds office (this agency's own deeds captures),
 * or a sale advert that went SOLD / UNDER OFFER, newest first, within the time filter (this month, or the last 1-6 months - then it drops off).
 * `around()`: the homes around one trigger - on the same side of the street up to N each side (default 5), plus the homes across - from this agency's
 * tracked properties and, when cc3's street register exists, the house numbers P24 lists (`p24_street_numbers`); a flat in a complex lists the complex's
 * other units. Owner shown only from THIS agency's own deeds capture. Every read is bound to the agency.
 *
 * Not built here: "our own sale" triggers - they come from deals, whose visibility is scoped per user; they need the deal scope first (reported).
 */
final class NeighboursPanelProvider implements PanelProvider
{
    public function tile(): int
    {
        return 10;
    }

    public function rows(int $agencyId, User $user, array $filters): array
    {
        $months = max(0, min(MicTileSettings::neighbourMonths($agencyId), (int) ($filters['months'] ?? 0)));
        $since = $months === 0 ? now()->startOfMonth() : now()->subMonths($months);
        $type = $filters['type'] ?? null;
        $suburb = mb_strtolower(trim((string) ($filters['suburb'] ?? '')));
        $rows = [];

        if ($type === null || $type === 'registered') {
            foreach (DB::table('tracked_properties as t')->where('t.agency_id', $agencyId)->whereNull('t.deleted_at')->whereIn('t.status', ['active', 'promoted'])
                ->whereNotNull('t.deeds_registered_date')->where('t.deeds_registered_date', '>=', $since->toDateString())->orderByDesc('t.deeds_registered_date')->limit(300)
                ->get(['t.id', 't.street_number', 't.street_name', 't.unit_number', 't.complex_name', 't.suburb', 't.property_type', 't.last_known_sold_price', 't.deeds_registered_date']) as $t) {
                $rows[] = ['id' => 'registered:' . $t->id, 'type' => 'registered', 'address' => $this->line($t), 'suburb' => (string) $t->suburb, 'property_type' => $t->property_type,
                    'price' => $t->last_known_sold_price !== null ? (int) $t->last_known_sold_price : null, 'date' => (string) $t->deeds_registered_date];
            }
        }
        foreach (['sold' => ProspectingListing::PORTAL_STATUS_SOLD, 'under_offer' => ProspectingListing::PORTAL_STATUS_UNDER_OFFER] as $key => $status) {
            if ($type !== null && $type !== $key) {
                continue;
            }
            foreach (DB::table('prospecting_listings as l')->join('tracked_properties as t', function ($j) use ($agencyId) {
                $j->on('t.id', '=', 'l.tracked_property_id')->where('t.agency_id', $agencyId)->whereNull('t.deleted_at')->whereIn('t.status', ['active', 'promoted']);
            })->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->where('l.listing_type', 'sale')->where('l.portal_status', $status)
                ->whereRaw('COALESCE(l.portal_status_changed_at, l.off_market_at) >= ?', [$since])->orderByRaw('COALESCE(l.portal_status_changed_at, l.off_market_at) DESC')->limit(300)
                ->get(['t.id', 't.street_number', 't.street_name', 't.unit_number', 't.complex_name', 't.suburb', 'l.address as advert_address', 'l.suburb as advert_suburb', 'l.property_type', 'l.price',
                    DB::raw('COALESCE(l.portal_status_changed_at, l.off_market_at) as at')]) as $t) {
                $rows[] = ['id' => $key . ':' . $t->id, 'type' => $key, 'address' => $this->line($t) ?: ((string) $t->advert_address ?: 'Address not shown'), 'suburb' => (string) ($t->suburb ?: $t->advert_suburb),
                    'property_type' => $t->property_type, 'price' => $t->price !== null ? (int) $t->price : null, 'date' => Carbon::parse($t->at)->toDateString()];
            }
        }
        if ($suburb !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => mb_strtolower($r['suburb']) === $suburb));
        }
        usort($rows, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return array_slice($rows, 0, 300);
    }

    public function around(int $agencyId, User $user, string $triggerId): array
    {
        [$kind, $fileId] = array_pad(explode(':', $triggerId, 2), 2, null);
        if (! in_array($kind, ['registered', 'sold', 'under_offer'], true) || ! ctype_digit((string) $fileId)) {
            return [];
        }
        $t = DB::table('tracked_properties')->where('agency_id', $agencyId)->where('id', (int) $fileId)->whereNull('deleted_at')->first();
        if ($t === null) {
            return [];
        }

        // a flat in a complex: the other units of the complex
        if (trim((string) $t->street_number) === '' && $t->complex_id) {
            return $this->rowsFor($agencyId, DB::table('tracked_properties')->where('agency_id', $agencyId)->where('complex_id', $t->complex_id)->where('id', '!=', $t->id)
                ->whereNull('deleted_at')->whereIn('status', ['active', 'promoted'])->limit(40)->get(), 'complex');
        }
        $num = (int) preg_replace('/\D.*/', '', trim((string) $t->street_number));
        $street = mb_strtolower(trim((string) $t->street_name));
        if ($num <= 0 || $street === '') {
            return [];
        }

        // the house numbers we know on this street: this agency's files, plus P24's own register when cc3's table exists
        $known = [];
        foreach (DB::table('tracked_properties')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('status', ['active', 'promoted'])
            ->whereRaw('LOWER(TRIM(street_name)) = ?', [$street])->whereRaw('LOWER(TRIM(suburb)) = ?', [mb_strtolower(trim((string) $t->suburb))])
            ->whereRaw("TRIM(COALESCE(street_number,'')) <> ''")->get() as $f) {
            $n = (int) preg_replace('/\D.*/', '', trim((string) $f->street_number));
            if ($n > 0 && $n !== $num) {
                $known[$n] ??= $f;
            }
        }
        $register = [];
        if (Schema::hasTable('p24_street_numbers') && $t->p24_suburb_id) {
            foreach (DB::table('p24_street_numbers')->where('p24_suburb_id', $t->p24_suburb_id)->whereRaw('LOWER(TRIM(street_name)) = ?', [$street])->get(['house_number', 'address_text']) as $r) {
                $n = (int) preg_replace('/\D.*/', '', trim((string) $r->house_number));
                if ($n > 0 && $n !== $num) {
                    $register[$n] = (string) $r->address_text;
                }
            }
        }
        $numbers = array_unique(array_merge(array_keys($known), array_keys($register)));
        $each = MicTileSettings::neighbourEachSide($agencyId);
        $parity = $num % 2;
        $sameSide = array_values(array_filter($numbers, fn ($n) => $n % 2 === $parity));
        $across = array_values(array_filter($numbers, fn ($n) => $n % 2 !== $parity));
        $lower = array_values(array_filter($sameSide, fn ($n) => $n < $num));
        $higher = array_values(array_filter($sameSide, fn ($n) => $n > $num));
        rsort($lower);
        sort($higher);
        usort($across, fn ($a, $b) => [abs($a - $num), $a] <=> [abs($b - $num), $b]);
        $picked = [];
        foreach (array_slice($lower, 0, $each) as $n) {
            $picked[$n] = 'before';
        }
        foreach (array_slice($higher, 0, $each) as $n) {
            $picked[$n] = 'after';
        }
        foreach (array_slice($across, 0, $each) as $n) {
            $picked[$n] = 'across';
        }
        ksort($picked);

        $out = [];
        foreach ($picked as $n => $side) {
            $f = $known[$n] ?? null;
            $row = $f ? $this->rowsFor($agencyId, collect([$f]), $side)[0] : ['address' => $register[$n] ?: ($n . ' ' . $t->street_name . ', ' . $t->suburb), 'owner' => null, 'on_market' => false,
                'last_contact' => null, 'property_id' => null, 'side' => $side];
            $out[] = $row + ['number' => $n];
        }

        return $out;
    }

    /** @param \Illuminate\Support\Collection<int,object> $files */
    private function rowsFor(int $agencyId, $files, string $side): array
    {
        $ids = $files->pluck('id')->all();
        $onMarket = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('is_active', 1)->where('listing_type', 'sale')
            ->whereIn('tracked_property_id', $ids)->distinct()->pluck('tracked_property_id')->flip();
        $contact = DB::table('tracked_property_agency_work_events')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $ids)
            ->selectRaw('tracked_property_id, MAX(occurred_at) as at')->groupBy('tracked_property_id')->pluck('at', 'tracked_property_id');
        $owners = DB::table('contacts')->where('agency_id', $agencyId)->whereIn('id', $files->pluck('owner_contact_id')->filter()->all())->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return $files->map(function ($f) use ($onMarket, $contact, $owners, $side) {
            $o = $f->owner_contact_id && $f->deeds_captured_at ? ($owners[$f->owner_contact_id] ?? null) : null;

            return [
                'address'      => $this->line($f) ?: 'Address not shown',
                'owner'        => $o ? trim($o->first_name . ' ' . $o->last_name) : null,
                'on_market'    => isset($onMarket[$f->id]),
                'last_contact' => isset($contact[$f->id]) ? Carbon::parse($contact[$f->id])->format('j M Y') : null,
                'property_id'  => (int) $f->id,
                'side'         => $side,
            ];
        })->values()->all();
    }

    private function line(object $t): string
    {
        $street = trim(trim((string) ($t->street_number ?? '')) . ' ' . trim((string) ($t->street_name ?? '')));
        $unit = trim((string) ($t->unit_number ?? '')) !== '' ? 'Unit ' . trim((string) $t->unit_number) . ', ' : '';
        $core = $street !== '' ? $street : trim((string) ($t->complex_name ?? ''));

        return $core === '' ? '' : $unit . $core . (trim((string) ($t->suburb ?? '')) !== '' ? ', ' . trim((string) $t->suburb) : '');
    }
}
