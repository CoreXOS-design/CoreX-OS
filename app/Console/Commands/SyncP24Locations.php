<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\P24City;
use App\Models\P24Country;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Services\Syndication\Property24\Property24ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncP24Locations extends Command
{
    /**
     * Cache key holding the live progress payload for the UI poller.
     * Shape: { status: idle|running|complete|failed, provinces_total, provinces_done,
     *          cities_done, suburbs_done, current: 'Western Cape › Cape Town', error, started_at, finished_at }
     */
    public const PROGRESS_KEY = 'p24:sync:progress';
    public const PROGRESS_TTL = 7200; // 2h

    protected $signature = 'p24:sync-locations
                            {--agency= : Sync using a specific agency\'s P24 credentials (id)}
                            {--country=South+Africa : Restrict sync to this country name}
                            {--no-prune : Refresh/stamp only; do not soft-delete locations P24 no longer returns}';

    protected $description = 'Pulls the P24 location tree (countries → provinces → cities → suburbs) into local cache tables, stamping p24_verified_at and sweeping rows P24 no longer returns.';

    /** @var array<string,mixed> */
    private array $progress = [];

    /**
     * Captured BEFORE any stamping. Every row P24 returns this run is stamped
     * `p24_verified_at = now()` (> this instant); the post-sync sweep prunes any
     * row whose stamp is older (P24 stopped returning it). Strict "<" so rows
     * touched during the run always survive.
     */
    private \Illuminate\Support\Carbon $runStart;

    public function handle(): int
    {
        $this->runStart = now();

        $this->progress = [
            'status'           => 'running',
            'provinces_total'  => 0,
            'provinces_done'   => 0,
            'cities_done'      => 0,
            'suburbs_done'     => 0,
            'current'          => 'Starting…',
            'error'            => null,
            'started_at'       => now()->toIso8601String(),
            'finished_at'      => null,
        ];
        $this->writeProgress();

        $agency = null;
        if ($this->option('agency')) {
            $agency = Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                ->find($this->option('agency'));
            if (!$agency) {
                $this->error("Agency {$this->option('agency')} not found.");
                return self::FAILURE;
            }
            if (empty($agency->p24_username) || empty($agency->p24_password)) {
                $this->error("Agency {$agency->name} has no P24 credentials configured.");
                return self::FAILURE;
            }
        } else {
            // No agency specified — pick the first enabled one with creds.
            $agency = Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                ->whereNotNull('p24_username')
                ->where('p24_enabled', true)
                ->first();
            if (!$agency) {
                $this->warn('No agency with P24 credentials found. Falling back to .env config.');
            }
        }

        try {
            $this->syncTree($agency);
        } catch (\Throwable $e) {
            if ($agency) {
                $agency->forceFill(['p24_last_sync_error' => $e->getMessage()])->save();
            }
            $this->progress['status']      = 'failed';
            $this->progress['error']       = $e->getMessage();
            $this->progress['finished_at'] = now()->toIso8601String();
            $this->writeProgress();
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Stamp-and-sweep: the full P24 walk above succeeded, so anything whose
        // p24_verified_at is older than this run is a location P24 stopped
        // returning — soft-delete it (guarded by a sanity floor below).
        if (!$this->option('no-prune')) {
            $this->pruneStale();
        }

        if ($agency) {
            $agency->forceFill([
                'p24_locations_synced_at' => now(),
                'p24_last_sync_error'     => null,
            ])->save();
        }

        $this->progress['status']      = 'complete';
        $this->progress['current']     = 'Sync complete.';
        $this->progress['finished_at'] = now()->toIso8601String();
        $this->writeProgress();

        $this->info('P24 location sync complete.');
        return self::SUCCESS;
    }

    private function writeProgress(): void
    {
        Cache::put(self::PROGRESS_KEY, $this->progress, self::PROGRESS_TTL);
    }

    /**
     * Soft-delete provinces/cities/suburbs P24 did not return in this run
     * (p24_verified_at older than runStart), and remediate any property pinned
     * to a pruned location. Suburbs first (the routing-critical tier), then
     * cities, then provinces.
     *
     * Safety floor: skip the sweep entirely if this run re-stamped fewer than
     * half the rows that currently exist in a tier — that signals a partial /
     * shrunken P24 response, and we will not let an API blip wipe the tree. The
     * next healthy run prunes normally.
     */
    /**
     * Absolute minimum rows P24 must have returned this run before we trust the
     * response enough to sweep. These guard a truncated / empty / auth-broken
     * P24 reply (the danger case) — NOT a ratio against the current table, which
     * is deliberately bloated with stale rows the first sweep is meant to clear
     * (a ratio floor would refuse that legitimate cleanup forever). The full
     * walk also aborts on ANY fetch error before reaching here, so a partial
     * tree only slips through if P24 returns success with a short list — which
     * these floors catch. Healthy SA tree: ~9 provinces, hundreds of cities,
     * tens of thousands of suburbs.
     */
    private const SWEEP_MIN_PROVINCES = 5;
    private const SWEEP_MIN_CITIES    = 50;
    private const SWEEP_MIN_SUBURBS   = 3000;

    private function pruneStale(): void
    {
        $provDone = (int) ($this->progress['provinces_done'] ?? 0);
        $cityDone = (int) ($this->progress['cities_done'] ?? 0);
        $subDone  = (int) ($this->progress['suburbs_done'] ?? 0);

        if ($provDone < self::SWEEP_MIN_PROVINCES
            || $cityDone < self::SWEEP_MIN_CITIES
            || $subDone < self::SWEEP_MIN_SUBURBS) {
            $this->progress['prune_skipped'] = true;
            $this->writeProgress();
            $this->warn(sprintf(
                'Sweep skipped (sanity floor): P24 returned only P/C/S = %d/%d/%d (need ≥ %d/%d/%d). Tree left intact.',
                $provDone, $cityDone, $subDone,
                self::SWEEP_MIN_PROVINCES, self::SWEEP_MIN_CITIES, self::SWEEP_MIN_SUBURBS
            ));
            return;
        }

        // --- Suburbs: remediate dependent properties, then soft-delete. ---
        $staleSuburbIds = P24Suburb::query()
            ->where(fn ($q) => $q->where('p24_verified_at', '<', $this->runStart)->orWhereNull('p24_verified_at'))
            ->pluck('id');

        $propsRemediated = 0;
        if ($staleSuburbIds->isNotEmpty()) {
            $propUpdate = ['p24_suburb_id' => null, 'p24_city_id' => null, 'p24_province_id' => null];
            if (Schema::hasColumn('properties', 'p24_suburb_mismatch')) {
                $propUpdate['p24_suburb_mismatch'] = 1;
            }
            $propsRemediated = DB::table('properties')
                ->whereIn('p24_suburb_id', $staleSuburbIds)
                ->update($propUpdate);

            P24Suburb::whereIn('id', $staleSuburbIds)->delete();
        }

        // --- Cities: null any property still pinned to a pruned city, then soft-delete. ---
        $staleCityIds = P24City::query()
            ->where(fn ($q) => $q->where('p24_verified_at', '<', $this->runStart)->orWhereNull('p24_verified_at'))
            ->pluck('id');
        if ($staleCityIds->isNotEmpty()) {
            DB::table('properties')->whereIn('p24_city_id', $staleCityIds)->update(['p24_city_id' => null]);
            P24City::whereIn('id', $staleCityIds)->delete();
        }

        // --- Provinces. ---
        $staleProvinceIds = P24Province::query()
            ->where(fn ($q) => $q->where('p24_verified_at', '<', $this->runStart)->orWhereNull('p24_verified_at'))
            ->pluck('id');
        if ($staleProvinceIds->isNotEmpty()) {
            DB::table('properties')->whereIn('p24_province_id', $staleProvinceIds)->update(['p24_province_id' => null]);
            P24Province::whereIn('id', $staleProvinceIds)->delete();
        }

        $this->progress['pruned_suburbs']   = $staleSuburbIds->count();
        $this->progress['pruned_cities']    = $staleCityIds->count();
        $this->progress['pruned_provinces'] = $staleProvinceIds->count();
        $this->progress['props_remediated'] = $propsRemediated;
        $this->writeProgress();

        $this->info(sprintf(
            'Swept stale P24 locations — provinces:%d cities:%d suburbs:%d (properties remediated:%d).',
            $staleProvinceIds->count(), $staleCityIds->count(), $staleSuburbIds->count(), $propsRemediated
        ));
    }

    private function syncTree(?Agency $agency): void
    {
        $client = new Property24ApiClient($agency);

        $countryFilter = $this->option('country');

        // Countries
        $this->info('Fetching countries…');
        $resp = $client->getCountries();
        $this->guard($resp, 'countries');
        $countries = $this->extractList($resp['data']);

        $countryCount = 0;
        foreach ($countries as $c) {
            $name = $c['Name'] ?? $c['name'] ?? null;
            $pid  = $c['Id'] ?? $c['id'] ?? null;
            if (!$name || !$pid) continue;
            if ($countryFilter && stripos(str_replace('+', ' ', $countryFilter), $name) === false
                && stripos($name, str_replace('+', ' ', $countryFilter)) === false) {
                continue;
            }

            $country = P24Country::updateOrCreate(
                ['p24_id' => $pid],
                ['name' => $name]
            );
            $countryCount++;

            $this->progress['current'] = "Fetching provinces for {$country->name}…";
            $this->writeProgress();
            $provResp = $client->getProvinces($country->p24_id);
            $this->guard($provResp, 'provinces');
            $provList = $this->extractList($provResp['data']);

            $this->progress['provinces_total'] = ($this->progress['provinces_total'] ?? 0) + count($provList);
            $this->writeProgress();

            foreach ($provList as $p) {
                $pname = $p['Name'] ?? $p['name'] ?? null;
                $ppid  = $p['Id'] ?? $p['id'] ?? null;
                if (!$pname || !$ppid) continue;

                $province = P24Province::withTrashed()->updateOrCreate(
                    ['p24_id' => $ppid],
                    [
                        'name'            => $pname,
                        'p24_country_id'  => $country->id,
                        'p24_verified_at' => now(),
                        'deleted_at'      => null,
                    ]
                );

                $this->progress['current'] = $province->name;
                $this->writeProgress();
                $this->syncCities($client, $province);

                $this->progress['provinces_done'] = ($this->progress['provinces_done'] ?? 0) + 1;
                $this->writeProgress();
            }
        }

        $this->info("Synced {$countryCount} country/countries.");
    }

    private function syncCities(Property24ApiClient $client, P24Province $province): void
    {
        $resp = $client->getCities($province->p24_id);
        $this->guard($resp, 'cities');
        $list = $this->extractList($resp['data']);

        foreach ($list as $c) {
            $name = $c['Name'] ?? $c['name'] ?? null;
            $pid  = $c['Id'] ?? $c['id'] ?? null;
            if (!$name || !$pid) continue;

            $city = P24City::withTrashed()->updateOrCreate(
                ['p24_id' => $pid],
                [
                    'name'            => $name,
                    'p24_province_id' => $province->id,
                    'p24_verified_at' => now(),
                    'deleted_at'      => null,
                ]
            );

            $this->progress['cities_done'] = ($this->progress['cities_done'] ?? 0) + 1;
            $this->progress['current']     = $province->name . ' › ' . $city->name;
            $this->writeProgress();

            $this->syncSuburbs($client, $city);
        }
    }

    private function syncSuburbs(Property24ApiClient $client, P24City $city): void
    {
        $resp = $client->getSuburbs($city->p24_id);
        $this->guard($resp, 'suburbs');
        $list = $this->extractList($resp['data']);

        $countBefore = $this->progress['suburbs_done'] ?? 0;
        foreach ($list as $s) {
            $name = $s['Name'] ?? $s['name'] ?? null;
            $pid  = $s['Id'] ?? $s['id'] ?? null;
            if (!$name || !$pid) continue;

            P24Suburb::withTrashed()->updateOrCreate(
                ['p24_id' => $pid],
                [
                    'name'            => $name,
                    'p24_city_id'     => $city->id,
                    'slug'            => \Illuminate\Support\Str::slug($name),
                    'deleted_at'      => null,
                    // Authoritative stamp: P24 returned this p24_id under this
                    // city in this sync. Drives AppliesP24Location + the cascade.
                    'p24_verified_at' => now(),
                ]
            );
            $this->progress['suburbs_done'] = ($this->progress['suburbs_done'] ?? 0) + 1;
        }
        // Flush progress once per city (avoids 50k cache writes for 27k suburbs).
        $this->writeProgress();
    }

    private function guard(array $resp, string $what): void
    {
        if (empty($resp['success'])) {
            throw new \RuntimeException("P24 fetch {$what} failed: " . ($resp['message'] ?? 'unknown error'));
        }
    }

    private function extractList(mixed $data): array
    {
        if (is_array($data) && array_is_list($data)) return $data;
        if (is_array($data) && isset($data[0])) return $data;
        if (is_array($data)) {
            foreach (['Items', 'items', 'Data', 'data', 'Result', 'result'] as $k) {
                if (isset($data[$k]) && is_array($data[$k])) return $data[$k];
            }
        }
        return [];
    }
}
