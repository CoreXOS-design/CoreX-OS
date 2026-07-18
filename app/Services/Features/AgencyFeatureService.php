<?php

namespace App\Services\Features;

use App\Models\Agency;
use App\Models\AgencyFeature;
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

        $registry  = $this->registry();
        $overrides = $this->overridesFor($agencyId);

        // Pass 1 — each feature's SELF state (before dependency cascade).
        $self = [];
        foreach ($registry as $key => $def) {
            $self[$key] = $this->selfEnabled($key, $def, $overrides);
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
    private function selfEnabled(string $key, array $def, array $overrides): bool
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

        // 5/6. per-agency override, else registry default.
        if (array_key_exists($key, $overrides)) {
            return (bool) $overrides[$key];
        }

        return (bool) ($def['default'] ?? false);
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
