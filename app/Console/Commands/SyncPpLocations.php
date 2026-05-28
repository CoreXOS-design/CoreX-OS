<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\PpCity;
use App\Models\PpProvince;
use App\Models\PpSuburb;
use App\Services\PrivateProperty\PrivatePropertySoapClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncPpLocations extends Command
{
    public const PROGRESS_KEY = 'pp:sync-locations:progress';
    public const PROGRESS_TTL = 7200;

    protected $signature = 'pp:sync-locations
                            {--agency= : Sync using a specific agency context (id)}';

    protected $description = 'Pulls the Private Property location tree (countries → provinces → cities → suburbs) into local cache tables.';

    private array $progress = [];

    public function handle(PrivatePropertySoapClient $client): int
    {
        $this->progress = [
            'status'          => 'running',
            'provinces_total' => 0,
            'provinces_done'  => 0,
            'cities_done'     => 0,
            'suburbs_done'    => 0,
            'current'         => 'Starting…',
            'error'           => null,
            'started_at'      => now()->toIso8601String(),
            'finished_at'     => null,
        ];
        $this->writeProgress();

        $agency = null;
        if ($this->option('agency')) {
            $agency = Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                ->find($this->option('agency'));
            if (!$agency) {
                $this->error("Agency {$this->option('agency')} not found.");
                $this->progress['status'] = 'failed';
                $this->progress['error']  = "Agency {$this->option('agency')} not found.";
                $this->writeProgress();
                return self::FAILURE;
            }
        } else {
            // No agency specified — pick the first enabled one with PP creds.
            $agency = Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)
                ->whereNotNull('pp_username')
                ->whereNotNull('pp_branch_guid')
                ->where('pp_enabled', true)
                ->first();
            if (!$agency) {
                $msg = 'No agency with PP credentials configured. Set pp_username, pp_password, pp_branch_guid and enable PP for an agency, then re-run.';
                $this->error($msg);
                $this->progress['status'] = 'failed';
                $this->progress['error']  = $msg;
                $this->progress['finished_at'] = now()->toIso8601String();
                $this->writeProgress();
                return self::FAILURE;
            }
            $this->info("Using agency #{$agency->id} ({$agency->name}) for PP credentials.");
        }
        $client->forAgency($agency);

        try {
            $this->syncTree($client);
        } catch (\Throwable $e) {
            $agency->forceFill(['pp_locations_last_error' => $e->getMessage()])->save();
            $this->progress['status']      = 'failed';
            $this->progress['error']       = $e->getMessage();
            $this->progress['finished_at'] = now()->toIso8601String();
            $this->writeProgress();
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $agency->forceFill([
            'pp_locations_synced_at'  => now(),
            'pp_locations_last_error' => null,
        ])->save();

        $this->progress['status']      = 'complete';
        $this->progress['current']     = 'Sync complete.';
        $this->progress['finished_at'] = now()->toIso8601String();
        $this->writeProgress();

        $this->info('PP location sync complete.');
        return self::SUCCESS;
    }

    private function writeProgress(): void
    {
        Cache::put(self::PROGRESS_KEY, $this->progress, self::PROGRESS_TTL);
    }

    private function syncTree(PrivatePropertySoapClient $client): void
    {
        $this->progress['current'] = 'Fetching countries…';
        $this->writeProgress();
        $resp = $client->getCountries();
        $this->guardSoap($resp, 'GetCountries');
        $countries = $this->extractList($resp, 'CountryModel');

        $saCountryId = null;
        foreach ($countries as $c) {
            $name = $c['Name'] ?? null;
            $id   = ($c['CityId'] ?? $c['Id'] ?? null);
            if (!$id || !$name) continue;
            if (stripos($name, 'south africa') !== false) {
                $saCountryId = (int) $id;
                break;
            }
        }
        if ($saCountryId === null && !empty($countries)) {
            $first = $countries[0];
            $saCountryId = (int) ($first['Id'] ?? 0);
        }
        if ($saCountryId === null || $saCountryId === 0) {
            throw new \RuntimeException('No country returned by GetCountries.');
        }

        $this->progress['current'] = 'Fetching provinces…';
        $this->writeProgress();
        $resp = $client->getProvinces($saCountryId);
        $this->guardSoap($resp, 'GetProvinces');
        $provinces = $this->extractList($resp, 'ProvinceModel');

        $this->progress['provinces_total'] = count($provinces);
        $this->writeProgress();

        $enumMap = $this->provinceEnumMap();

        foreach ($provinces as $p) {
            $pname = $p['Name'] ?? null;
            $pid   = $p['ProvinceId'] ?? $p['Id'] ?? null;
            if (!$pname || !$pid) continue;

            $province = PpProvince::updateOrCreate(
                ['pp_province_id' => (int) $pid],
                [
                    'name'             => $pname,
                    'pp_province_enum' => $enumMap[strtolower(trim($pname))] ?? null,
                ]
            );

            $this->progress['current'] = $province->name;
            $this->writeProgress();
            $this->syncCities($client, $province);

            $this->progress['provinces_done']++;
            $this->writeProgress();
        }
    }

    private function syncCities(PrivatePropertySoapClient $client, PpProvince $province): void
    {
        $resp = $client->getCities($province->pp_province_id);
        $this->guardSoap($resp, 'GetCities');
        $list = $this->extractList($resp, 'CityModel');

        foreach ($list as $c) {
            $name = $c['Name'] ?? null;
            $cid  = ($c['CityId'] ?? $c['Id'] ?? null);
            if (!$name || !$cid) continue;

            $city = PpCity::updateOrCreate(
                ['pp_city_id' => (int) $cid],
                ['name' => $name, 'pp_province_id' => $province->id]
            );

            $this->progress['cities_done']++;
            $this->progress['current'] = $province->name . ' › ' . $city->name;
            $this->writeProgress();

            $this->syncSuburbs($client, $city);
        }
    }

    private function syncSuburbs(PrivatePropertySoapClient $client, PpCity $city): void
    {
        $resp = $client->getSuburbs($city->pp_city_id);
        if (isset($resp['error']) && $resp['error'] === true) {
            throw new \RuntimeException("PP GetSuburbs failed for CityID={$city->pp_city_id} ({$city->name}): " . ($resp['message'] ?? 'unknown'));
        }
        $list = $this->extractList($resp, 'SuburbModel');

        foreach ($list as $s) {
            $name = $s['Name'] ?? null;
            $sid  = $s['SuburbId'] ?? $s['Id'] ?? null;
            if (!$name || !$sid) continue;

            PpSuburb::updateOrCreate(
                ['pp_suburb_id' => (int) $sid],
                [
                    'pp_city_id'      => $city->id,
                    'name'            => $name,
                    'normalised_name' => PpSuburb::normalise($name),
                ]
            );
            $this->progress['suburbs_done']++;
        }
        $this->writeProgress();
    }

    private function guardSoap(array $resp, string $op): void
    {
        if (isset($resp['error']) && $resp['error'] === true) {
            throw new \RuntimeException("PP {$op} failed: " . ($resp['message'] ?? 'unknown error'));
        }
    }

    /**
     * Unwrap PP's typical SOAP response: { <Op>Result: { <Model>: [ {Id,Name}, ... ] } }
     */
    private function extractList(array $resp, string $modelName): array
    {
        $candidates = [];
        foreach ($resp as $k => $v) {
            if (str_ends_with($k, 'Result') && is_array($v)) {
                $candidates[] = $v;
            }
        }
        $candidates[] = $resp;

        foreach ($candidates as $c) {
            if (isset($c[$modelName])) {
                $inner = $c[$modelName];
                if (is_array($inner) && isset($inner['Id'])) return [$inner];
                if (is_array($inner)) return $inner;
            }
        }
        return [];
    }

    /**
     * Map PP's province display names → the listing-field enum value
     * required by UpdateListing.
     */
    private function provinceEnumMap(): array
    {
        return [
            'kwazulu-natal'  => 'KwaZuluNatal',
            'kwazulu natal'  => 'KwaZuluNatal',
            'gauteng'        => 'Gauteng',
            'western cape'   => 'WesternCape',
            'eastern cape'   => 'EasternCape',
            'free state'     => 'FreeState',
            'limpopo'        => 'Limpopo',
            'mpumalanga'     => 'Mpumalanga',
            'north west'     => 'NorthWest',
            'northern cape'  => 'NorthernCape',
        ];
    }
}
