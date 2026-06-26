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
                            {--country=South+Africa : Restrict sync to this country name}';

    protected $description = 'Pulls the P24 location tree (countries → provinces → cities → suburbs) into local cache tables.';

    /** @var array<string,mixed> */
    private array $progress = [];

    public function handle(): int
    {
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

                $province = P24Province::updateOrCreate(
                    ['p24_id' => $ppid],
                    ['name' => $pname, 'p24_country_id' => $country->id]
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

            $city = P24City::updateOrCreate(
                ['p24_id' => $pid],
                ['name' => $name, 'p24_province_id' => $province->id]
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
