<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\P24City;
use App\Models\P24Country;
use App\Models\P24Province;
use App\Models\P24Suburb;
use App\Services\Syndication\Property24\Property24ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncP24Locations extends Command
{
    protected $signature = 'p24:sync-locations
                            {--agency= : Sync using a specific agency\'s P24 credentials (id)}
                            {--country=South+Africa : Restrict sync to this country name}';

    protected $description = 'Pulls the P24 location tree (countries → provinces → cities → suburbs) into local cache tables.';

    public function handle(): int
    {
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
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        if ($agency) {
            $agency->forceFill([
                'p24_locations_synced_at' => now(),
                'p24_last_sync_error'     => null,
            ])->save();
        }

        $this->info('P24 location sync complete.');
        return self::SUCCESS;
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
            $this->syncProvinces($client, $country);
        }

        $this->info("Synced {$countryCount} country/countries.");
    }

    private function syncProvinces(Property24ApiClient $client, P24Country $country): void
    {
        $this->line("  Provinces for {$country->name}…");
        $resp = $client->getProvinces($country->p24_id);
        $this->guard($resp, 'provinces');
        $list = $this->extractList($resp['data']);

        foreach ($list as $p) {
            $name = $p['Name'] ?? $p['name'] ?? null;
            $pid  = $p['Id'] ?? $p['id'] ?? null;
            if (!$name || !$pid) continue;

            $province = P24Province::updateOrCreate(
                ['p24_id' => $pid],
                ['name' => $name, 'p24_country_id' => $country->id]
            );
            $this->syncCities($client, $province);
        }
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
            $this->syncSuburbs($client, $city);
        }
    }

    private function syncSuburbs(Property24ApiClient $client, P24City $city): void
    {
        $resp = $client->getSuburbs($city->p24_id);
        $this->guard($resp, 'suburbs');
        $list = $this->extractList($resp['data']);

        foreach ($list as $s) {
            $name = $s['Name'] ?? $s['name'] ?? null;
            $pid  = $s['Id'] ?? $s['id'] ?? null;
            if (!$name || !$pid) continue;

            P24Suburb::withTrashed()->updateOrCreate(
                ['p24_id' => $pid],
                [
                    'name'        => $name,
                    'p24_city_id' => $city->id,
                    'slug'        => \Illuminate\Support\Str::slug($name),
                    'deleted_at'  => null,
                ]
            );
        }
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
