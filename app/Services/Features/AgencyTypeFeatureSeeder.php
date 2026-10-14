<?php

namespace App\Services\Features;

use App\Events\AgencyFeatureToggled;
use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\PerformanceSetting;
use Illuminate\Support\Facades\DB;

/**
 * Turns "what does this agency do?" (sales / rentals / both) into the agency's feature switches.
 * Lists: config/agency-types.php. Spec: .ai/specs/agency-onboarding-setup.md §5.2.
 *
 * Two entry points, deliberately different:
 *
 *  - seedNewAgency(): a brand-new agency. Writes the type's switches AND the opt-in list (syndication,
 *    payroll — off until the agency chooses them), and pins the switchboard-origin switches that live in
 *    performance_settings to an explicit row of the agency's OWN. Without that last part a new agency
 *    inherits the platform-wide (NULL-agency) row — which today carries Home Finders Coastal's own choices
 *    (syndication on, marketing off) — so a new agency started life with someone else's switches.
 *
 *  - applyType(): an admin changes the type later, on purpose. Re-applies only the type's own on/off
 *    (sales tools, rentals area); everything outside those lists is left exactly as the admin set it.
 *
 * Existing agencies are never touched by this class unless an admin calls applyType() for their own agency.
 */
class AgencyTypeFeatureSeeder
{
    public const TYPES = ['sales', 'rentals', 'both'];

    public function __construct(private readonly AgencyFeatureService $features)
    {
    }

    public static function normalise(?string $type): string
    {
        $type = strtolower(trim((string) $type));

        return in_array($type, self::TYPES, true) ? $type : (string) config('agency-types.default', 'both');
    }

    /** @return array<string,string> value => label, in display order */
    public static function options(): array
    {
        $out = [];
        foreach ((array) config('agency-types.types', []) as $value => $def) {
            $out[$value] = $def['label'];
        }

        return $out;
    }

    /** feature key => desired on/off for the type's own lists (no opt-in list). */
    public function desiredForType(string $type): array
    {
        $type = self::normalise($type);
        $want = [];
        foreach ((array) config('agency-types.sales_features', []) as $key) {
            $want[$key] = $type !== 'rentals';
        }
        foreach ((array) config('agency-types.rentals_features', []) as $key) {
            $want[$key] = $type !== 'sales';
        }

        return $want;
    }

    /** Switches for a brand-new agency. Creation only — emits no toggle events (nothing to audit yet). */
    public function seedNewAgency(Agency $agency, ?string $type = null): void
    {
        $type = self::normalise($type ?? $agency->agency_type);
        $want = $this->desiredForType($type);
        foreach ((array) config('agency-types.opt_in_features', []) as $key) {
            $want[$key] = false;
        }

        DB::transaction(function () use ($agency, $type, $want) {
            if ($agency->agency_type !== $type) {
                $agency->forceFill(['agency_type' => $type])->saveQuietly();
            }
            $this->write($agency, $want, null, false);
            $this->pinSwitchboardDefaults($agency, $want);
        });

        $this->features->forget((int) $agency->id);
    }

    /**
     * An admin changed the agency's type. Stores it and re-applies the type's own switches.
     *
     * @return list<string> the feature keys whose state actually changed
     */
    public function applyType(Agency $agency, string $type, ?int $userId = null): array
    {
        $type = self::normalise($type);
        $changed = [];
        DB::transaction(function () use ($agency, $type, $userId, &$changed) {
            $agency->forceFill(['agency_type' => $type])->saveQuietly();
            $changed = $this->write($agency, $this->desiredForType($type), $userId, true);
        });

        $this->features->forget((int) $agency->id);

        return $changed;
    }

    /**
     * @param  array<string,bool> $want
     * @return list<string> keys whose resolved state changed
     */
    private function write(Agency $agency, array $want, ?int $userId, bool $emit): array
    {
        $before = $this->features->all($agency);
        $registry = $this->features->catalogue();
        $changed = [];

        foreach ($want as $key => $enabled) {
            if (! isset($registry[$key]) || $this->features->isCore($key)) {
                continue; // a stale list entry must never break agency creation, and core is never switchable
            }

            if ($this->features->isSwitchboard($key)) {
                $this->writeSwitchboard($agency, $key, $enabled);
            } else {
                AgencyFeature::withoutAgencyStamping(function () use ($agency, $key, $enabled, $userId) {
                    $row = AgencyFeature::withoutGlobalScopes()->withTrashed()
                        ->firstOrNew(['agency_id' => $agency->id, 'feature_key' => $key]);
                    $row->agency_id  = $agency->id;
                    $row->deleted_at = null;
                    $row->enabled    = $enabled;
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

    /**
     * Give the agency its OWN row for every switchboard setting that lives in performance_settings and was
     * not decided above, at the registry's neutral default — so it can never fall back to another agency's
     * platform-wide row.
     *
     * @param array<string,bool> $decided
     */
    private function pinSwitchboardDefaults(Agency $agency, array $decided): void
    {
        foreach (AgencyFeatureService::SWITCHBOARD_STORES as $key => $store) {
            if ($store['type'] !== 'perf' || array_key_exists($key, $decided)) {
                continue;
            }
            PerformanceSetting::set($store['key'], $store['default'] ? 1 : 0, (int) $agency->id);
        }
    }
}
