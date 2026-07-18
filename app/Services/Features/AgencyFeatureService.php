<?php

namespace App\Services\Features;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\PerformanceSetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * The universal per-agency feature gate (spec: corex-feature-registry.md §6.1).
 *
 * The feature gate is ORTHOGONAL to permissions (§3.1): nav/route needs
 * permission AND feature. This service answers only "does THIS AGENCY use this
 * module?" — never "may this USER touch it" (that stays PermissionService).
 *
 * Resolution order (§3.5), short-circuiting on the first decisive answer:
 *   1. unknown key                → false (fail-closed; logged)
 *   2. global env kill-switch off → false (config/features.php, outer AND)
 *   3. core: true                 → true (before any store read)
 *   4. any depends_on parent off  → false (dependency cascade)
 *   5. agency_features row exists  → row.enabled
 *   6. no row                     → registry default
 *
 * Request-cached: the full resolved key=>bool map for an agency is computed once
 * (one query) and memoised. Registered as a singleton (AppServiceProvider) so the
 * memo lives for the request; AgencyFeatureToggled busts it via forget().
 *
 * Bind: registered as a container singleton so the per-request memo is shared.
 */
class AgencyFeatureService
{
    /** @var array<int|string, array<string,bool>> resolved map per agency id ('_none' for null-agency) */
    private array $cache = [];

    /**
     * Switchboard-origin keys (spec §7.2): these SIX resolve through their
     * EXISTING store, NOT agency_features — so the settings switchboard, the
     * onboarding wizard, and this gate can never disagree about them, and the
     * already-shipped canonical savers stay the single write path.
     *   type 'perf'   → PerformanceSetting key (currently GLOBAL, not per-agency —
     *                   preserving the shipped behaviour; noted quirk).
     *   type 'agency' → a column on the agencies row (genuinely per-agency).
     */
    public const SWITCHBOARD_STORES = [
        'marketing'       => ['type' => 'perf',   'key' => 'marketing_enabled',        'default' => true],
        'syndication-p24' => ['type' => 'perf',   'key' => 'syndication_p24_enabled',  'default' => false],
        'syndication-pp'  => ['type' => 'perf',   'key' => 'syndication_pp_enabled',   'default' => false],
        'core-matches'    => ['type' => 'perf',   'key' => 'matches_enabled',          'default' => true],
        'multi-branch'    => ['type' => 'agency', 'key' => 'split_branches_enabled',   'default' => false],
        'public-website'  => ['type' => 'agency', 'key' => 'website_enabled',           'default' => false],
    ];

    /**
     * Is a feature enabled for the given agency (or the current effective agency)?
     */
    public function enabled(string $key, ?Agency $agency = null): bool
    {
        $registry = $this->registry();

        if (!array_key_exists($key, $registry)) {
            // Fail-closed: a typo in @feature/feature:/config must hide the
            // feature loudly-in-logs, never silently pass.
            Log::warning('AgencyFeatureService: unknown feature key requested.', ['key' => $key]);
            return false;
        }

        return $this->resolvedMap($agency)[$key] ?? false;
    }

    /**
     * The full resolved key=>bool map for an agency — drives the Settings page
     * and the onboarding wizard's auto-derived toggle list.
     *
     * @return array<string,bool>
     */
    public function all(?Agency $agency = null): array
    {
        return $this->resolvedMap($agency);
    }

    /** The raw registry catalogue. */
    public function catalogue(): array
    {
        return $this->registry();
    }

    /** Is this a core (always-on, never toggleable) feature? */
    public function isCore(string $key): bool
    {
        return !empty($this->registry()[$key]['core']);
    }

    /** Is this one of the six switchboard-origin keys (managed by its own store)? */
    public function isSwitchboard(string $key): bool
    {
        return array_key_exists($key, self::SWITCHBOARD_STORES);
    }

    /**
     * The MODULE feature keys — non-core AND non-switchboard. These are the ones
     * the Settings → Features page toggles into agency_features (the six
     * switchboard keys keep their existing settings homes; core is always on).
     *
     * @return list<string>
     */
    public function moduleFeatureKeys(): array
    {
        return array_values(array_filter(
            array_keys($this->registry()),
            fn (string $k) => !$this->isCore($k) && !$this->isSwitchboard($k)
        ));
    }

    /**
     * The full catalogue grouped by category for display, each entry annotated
     * with its resolved state + kind (core/switchboard/module) + whether a
     * depends_on parent is off (so the UI can disable a blocked child).
     *
     * @return array<string, list<array<string,mixed>>>  category => rows
     */
    public function groupedForDisplay(?Agency $agency = null): array
    {
        $map = $this->all($agency);
        $registry = $this->registry();
        $grouped = [];

        foreach ($registry as $key => $def) {
            $kind = $this->isCore($key) ? 'core' : ($this->isSwitchboard($key) ? 'switchboard' : 'module');

            // A child is "blocked" when a depends_on parent resolves off.
            $blockedBy = null;
            foreach (($def['depends_on'] ?? []) as $parent) {
                if (!($map[$parent] ?? false)) {
                    $blockedBy = $parent;
                    break;
                }
            }

            $grouped[$def['category']][] = [
                'key'        => $key,
                'label'      => $def['label'],
                'explain'    => $def['explain'] ?? '',
                'affects'    => $def['affects'] ?? '',
                'enabled'    => (bool) ($map[$key] ?? false),
                'kind'       => $kind,
                'blocked_by' => $blockedBy,
                'depends_on' => $def['depends_on'] ?? [],
            ];
        }

        return $grouped;
    }

    /** Bust the request memo (called by the toggle saver / AgencyFeatureToggled). */
    public function forget(?int $agencyId = null): void
    {
        if ($agencyId === null) {
            $this->cache = [];
            return;
        }
        unset($this->cache[$agencyId]);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @return array<string,bool> */
    private function resolvedMap(?Agency $agency): array
    {
        $agencyId = $agency?->id ?? $this->resolveAgencyId();
        $cacheKey = $agencyId ?? '_none';

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $registry    = $this->registry();
        $overrides   = $this->overridesFor($agencyId);
        $switchboard = $this->switchboardStates($agencyId);

        // Pass 1 — each feature's SELF state (before dependency cascade).
        $self = [];
        foreach ($registry as $key => $def) {
            $self[$key] = $this->selfEnabled($key, $def, $overrides, $switchboard);
        }

        // Pass 2 — apply the depends_on cascade (a child is off if any parent is
        // off), memoised with a cycle guard.
        $final = [];
        foreach ($registry as $key => $def) {
            $final[$key] = $this->cascade($key, $registry, $self, $final, []);
        }

        return $this->cache[$cacheKey] = $final;
    }

    /** A feature's own on/off, ignoring dependencies. */
    private function selfEnabled(string $key, array $def, array $overrides, array $switchboard): bool
    {
        // 2. global env kill-switch (outer AND). Missing config key => treated as
        //    enabled, so a stale mapping never accidentally disables a feature.
        $flag = $def['global_flag'] ?? null;
        if ($flag !== null && !(bool) Config::get('features.' . $flag, true)) {
            return false;
        }

        // 3. core is always on.
        if (!empty($def['core'])) {
            return true;
        }

        // 4. switchboard-origin keys read their EXISTING store (spec §7.2),
        //    NOT agency_features — the store adapter is authoritative for them.
        if (array_key_exists($key, $switchboard)) {
            return $switchboard[$key];
        }

        // 5/6. per-agency override, else registry default.
        if (array_key_exists($key, $overrides)) {
            return (bool) $overrides[$key];
        }

        return (bool) ($def['default'] ?? false);
    }

    /**
     * Read the six switchboard-origin toggles from their existing stores.
     * PerformanceSetting keys are (currently) global; agency columns are
     * per-agency. Returns key => bool for exactly the switchboard keys.
     *
     * @return array<string,bool>
     */
    private function switchboardStates(?int $agencyId): array
    {
        $out = [];
        $agency = null;

        foreach (self::SWITCHBOARD_STORES as $key => $store) {
            if ($store['type'] === 'perf') {
                $out[$key] = (bool) PerformanceSetting::get($store['key'], $store['default'] ? 1 : 0);
                continue;
            }

            // agency column
            if ($agencyId) {
                if ($agency === null) {
                    $agency = Agency::find($agencyId) ?: false;
                }
                $out[$key] = $agency
                    ? (bool) ($agency->{$store['key']} ?? $store['default'])
                    : $store['default'];
            } else {
                $out[$key] = $store['default'];
            }
        }

        return $out;
    }

    /**
     * Resolve the final state with the dependency cascade. A feature is enabled
     * only if its self-state is on AND every depends_on parent is finally on.
     *
     * @param array<string,array>  $registry
     * @param array<string,bool>   $self
     * @param array<string,bool>   $final   (by-ref memo)
     * @param list<string>         $chain   (cycle guard)
     */
    private function cascade(string $key, array $registry, array $self, array &$final, array $chain): bool
    {
        if (array_key_exists($key, $final)) {
            return $final[$key];
        }
        if (in_array($key, $chain, true)) {
            // Dependency cycle — validated against by corex:features:validate,
            // but guard defensively so a bad config can never infinite-loop.
            Log::warning('AgencyFeatureService: dependency cycle detected.', ['key' => $key, 'chain' => $chain]);
            return false;
        }
        if (!($self[$key] ?? false)) {
            return $final[$key] = false;
        }

        foreach (($registry[$key]['depends_on'] ?? []) as $parent) {
            // Unknown parent => treat as off (fail-closed).
            if (!array_key_exists($parent, $registry)) {
                Log::warning('AgencyFeatureService: unknown depends_on parent.', ['key' => $key, 'parent' => $parent]);
                return $final[$key] = false;
            }
            if (!$this->cascade($parent, $registry, $self, $final, array_merge($chain, [$key]))) {
                return $final[$key] = false;
            }
        }

        return $final[$key] = true;
    }

    /**
     * The per-agency override rows (feature_key => enabled). Empty when there is
     * no agency in scope (console/system) — everything then resolves to default.
     *
     * Relies on AgencyScope (multi-tenancy spec: never withoutGlobalScope in
     * request code). In the request path the resolved id IS effectiveAgencyId, so
     * the scope's filter and this explicit where agree; in console/no-auth the
     * scope is skipped and the explicit where is authoritative. A future
     * owner-cross-agency phase adds a reviewed queryWithoutAgencyScope() gated on
     * isOwnerRole() per that spec's rule #5 — not needed in Phase 1.
     *
     * @return array<string,bool>
     */
    private function overridesFor(?int $agencyId): array
    {
        if (!$agencyId) {
            return [];
        }

        return AgencyFeature::query()
            ->where('agency_id', $agencyId)
            ->pluck('enabled', 'feature_key')
            ->map(fn ($v) => (bool) $v)
            ->all();
    }

    private function resolveAgencyId(): ?int
    {
        $user = Auth::user();
        if (!$user) {
            return null;
        }
        return method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);
    }

    /** @return array<string,array> */
    private function registry(): array
    {
        return Config::get('corex-features', []);
    }
}
