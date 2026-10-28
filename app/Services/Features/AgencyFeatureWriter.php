<?php

namespace App\Services\Features;

use App\Events\AgencyFeatureToggled;
use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\PerformanceSetting;

/**
 * Writes an agency's feature switches — the one write path shared by the agency-type seeder
 * (AgencyTypeFeatureSeeder) and the gradual feature rollout (FeatureRolloutService).
 *
 * Switchboard-origin keys keep their existing store (AgencyFeatureService::SWITCHBOARD_STORES);
 * every other key is an agency_features row. Core keys and unknown keys are skipped, so a stale
 * list entry can never break the caller. Emits AgencyFeatureToggled for every key whose RESOLVED
 * state actually changed (dependency cascade included), when $emit is true.
 */
class AgencyFeatureWriter
{
    public function __construct(private readonly AgencyFeatureService $features)
    {
    }

    /**
     * @param  array<string,bool> $want feature key => desired on/off
     * @return list<string> keys whose resolved state changed
     */
    public function write(Agency $agency, array $want, ?int $userId = null, bool $emit = true): array
    {
        $before = $this->features->all($agency);
        $registry = $this->features->catalogue();
        $changed = [];

        foreach ($want as $key => $enabled) {
            if (! isset($registry[$key]) || $this->features->isCore($key)) {
                continue; // a stale list entry must never break the caller, and core is never switchable
            }

            if ($this->features->isSwitchboard($key)) {
                $this->writeSwitchboard($agency, $key, (bool) $enabled);
            } else {
                AgencyFeature::withoutAgencyStamping(function () use ($agency, $key, $enabled, $userId) {
                    $row = AgencyFeature::withoutGlobalScopes()->withTrashed()
                        ->firstOrNew(['agency_id' => $agency->id, 'feature_key' => $key]);
                    $row->agency_id  = $agency->id;
                    $row->deleted_at = null;
                    $row->enabled    = (bool) $enabled;
                    $row->updated_by = $userId;
                    $row->save();
                });
            }
        }

        $this->features->forget((int) $agency->id);
        $after = $this->features->all($agency);
        foreach ($after as $key => $state) {
            if (($before[$key] ?? null) !== $state) {
                $changed[] = $key;
                if ($emit) {
                    event(new AgencyFeatureToggled((int) $agency->id, $key, $state, $userId));
                }
            }
        }

        return $changed;
    }

    /** Switchboard-origin keys keep their existing store (AgencyFeatureService::SWITCHBOARD_STORES). */
    private function writeSwitchboard(Agency $agency, string $key, bool $enabled): void
    {
        $store = AgencyFeatureService::SWITCHBOARD_STORES[$key];
        if ($store['type'] === 'perf') {
            PerformanceSetting::set($store['key'], $enabled ? 1 : 0, (int) $agency->id);

            return;
        }
        $agency->forceFill([$store['key'] => $enabled])->saveQuietly();
    }
}
