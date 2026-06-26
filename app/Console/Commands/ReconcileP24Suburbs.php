<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\P24City;
use App\Models\P24Suburb;
use App\Services\Syndication\Property24\Property24ApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-pass reconciliation of the local `p24_suburbs` cache against P24's live
 * per-city suburb lists.
 *
 * For every locally-known P24 city we fetch the live suburb list and:
 *   - stamp `p24_verified_at = now()` (and correct `p24_city_id`) on every row
 *     whose `p24_id` P24 still returns — these are authoritative;
 *   - soft-delete every row assigned to a successfully-fetched city whose
 *     `p24_id` P24 did NOT return — these are stale/phantom (e.g. Addington/5997,
 *     which P24 rejects as an invalid SuburbId — see AT-104 audit). Any property
 *     pinned to a pruned suburb is remediated: FK chain nulled + p24_suburb_mismatch
 *     set so the agent re-picks a real P24 suburb.
 *
 * A city whose live fetch FAILS is skipped entirely — its suburbs are never
 * pruned on the strength of a network error.
 *
 * No hard deletes (non-negotiable #1). Dry-run by default — pass --apply to
 * mutate. Run this on the host that holds the live P24 credentials.
 */
class ReconcileP24Suburbs extends Command
{
    protected $signature = 'p24:reconcile-suburbs
                            {--apply : Persist changes (default is a dry-run report)}
                            {--agency= : Use a specific agency\'s P24 credentials (id)}';

    protected $description = 'Reconcile local p24_suburbs against P24\'s live per-city lists; stamp verified rows, soft-delete stale/phantom rows.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $agency = $this->resolveAgency();
        if ($agency === false) {
            return self::FAILURE;
        }

        $client = new Property24ApiClient($agency);

        $cities = P24City::query()
            ->whereNotNull('p24_id')
            ->orderBy('name')
            ->get(['id', 'name', 'p24_id']);

        $this->info(($apply ? 'APPLY' : 'DRY-RUN') . " — reconciling {$cities->count()} P24 cities…");

        $stats = [
            'cities_ok'        => 0,
            'cities_failed'    => 0,
            'rows_verified'    => 0,
            'rows_pruned'      => 0,
            'props_remediated' => 0,
        ];
        $prunedSample = [];

        foreach ($cities as $city) {
            $resp = $client->getSuburbs($city->p24_id);
            if (empty($resp['success'])) {
                $stats['cities_failed']++;
                $this->warn("  ! {$city->name} (p24 {$city->p24_id}) — fetch failed, skipped: " . ($resp['message'] ?? 'unknown'));
                continue;
            }
            $stats['cities_ok']++;

            $liveIds = [];
            foreach ($this->extractList($resp['data']) as $s) {
                $sid = $s['Id'] ?? $s['id'] ?? null;
                if ($sid) {
                    $liveIds[(int) $sid] = true;
                }
            }

            // Stamp / correct the rows P24 still returns for this city.
            if ($apply && !empty($liveIds)) {
                $verified = P24Suburb::withTrashed()
                    ->whereIn('p24_id', array_keys($liveIds))
                    ->update([
                        'p24_city_id'     => $city->id,
                        'p24_verified_at' => now(),
                        'deleted_at'      => null,
                    ]);
                $stats['rows_verified'] += $verified;
            } else {
                $stats['rows_verified'] += P24Suburb::withTrashed()
                    ->whereIn('p24_id', array_keys($liveIds) ?: [0])
                    ->count();
            }

            // Prune rows assigned to THIS (successfully-fetched) city whose
            // p24_id P24 no longer returns.
            $staleQuery = P24Suburb::query()
                ->where('p24_city_id', $city->id)
                ->whereNull('deleted_at');
            if (!empty($liveIds)) {
                $staleQuery->whereNotIn('p24_id', array_keys($liveIds));
            }
            $staleRows = $staleQuery->get(['id', 'name', 'p24_id']);

            foreach ($staleRows as $row) {
                $stats['rows_pruned']++;
                if (count($prunedSample) < 50) {
                    $prunedSample[] = "{$row->name} (p24 {$row->p24_id}, city {$city->name})";
                }
                $stats['props_remediated'] += $this->pruneSuburb($row->id, $apply);
            }
        }

        $this->table(['metric', 'count'], collect($stats)->map(fn ($v, $k) => [$k, $v])->values()->all());

        if (!empty($prunedSample)) {
            $this->line(($apply ? 'Pruned' : 'Would prune') . ':');
            foreach ($prunedSample as $line) {
                $this->line("  - {$line}");
            }
            if ($stats['rows_pruned'] > count($prunedSample)) {
                $this->line('  … and ' . ($stats['rows_pruned'] - count($prunedSample)) . ' more.');
            }
        }

        if (!$apply) {
            $this->newLine();
            $this->comment('Dry-run only — re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }

    /**
     * Soft-delete a suburb and remediate any property pinned to it. Returns the
     * number of properties remediated.
     */
    private function pruneSuburb(int $suburbId, bool $apply): int
    {
        $propIds = DB::table('properties')->where('p24_suburb_id', $suburbId)->pluck('id');

        if ($apply) {
            if ($propIds->isNotEmpty()) {
                DB::table('properties')->whereIn('id', $propIds)->update([
                    'p24_suburb_id'       => null,
                    'p24_city_id'         => null,
                    'p24_province_id'     => null,
                    'p24_suburb_mismatch' => 1,
                ]);
            }
            DB::table('p24_suburbs')->where('id', $suburbId)->update([
                'deleted_at'      => now(),
                'p24_verified_at' => null,
            ]);
        }

        return $propIds->count();
    }

    /**
     * @return Agency|null|false  false = hard failure (caller should abort)
     */
    private function resolveAgency()
    {
        $scope = \App\Models\Scopes\AgencyScope::class;

        if ($this->option('agency')) {
            $agency = Agency::withoutGlobalScope($scope)->find($this->option('agency'));
            if (!$agency) {
                $this->error("Agency {$this->option('agency')} not found.");
                return false;
            }
            if (empty($agency->p24_username) || empty($agency->p24_password)) {
                $this->error("Agency {$agency->name} has no P24 credentials configured.");
                return false;
            }
            return $agency;
        }

        $agency = Agency::withoutGlobalScope($scope)
            ->whereNotNull('p24_username')
            ->where('p24_enabled', true)
            ->first();
        if (!$agency) {
            $this->warn('No agency with P24 credentials found. Falling back to .env config.');
        }
        return $agency;
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
