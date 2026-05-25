<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Phase 11a — daily rate limiter for paid geocoding calls (Google primarily;
 * Nominatim included for fairness even though it's free, so the cap shapes
 * total daily traffic, not just spend).
 *
 * Two counters, both keyed by SAST calendar day:
 *   environment-wide: geocode_counter:{env}:{YYYY-MM-DD}
 *   per-user:         geocode_counter:{env}:{YYYY-MM-DD}:user:{id}
 *
 * Uses the default Laravel cache driver (no new driver introduced). TTL is
 * set to end-of-day SAST so the counter naturally resets at midnight.
 *
 * canGeocode() short-circuits true when GEOCODING_ADMIN_OVERRIDE=true — the
 * backfill command sets this temporarily for one-shot runs (see PART D).
 *
 * recordCall() is intentionally split from canGeocode(): the resolver may
 * choose to call only after checking the cache (cache hits don't consume
 * quota). The contract is: caller does check → cache miss → callGoogle →
 * recordCall on completion (success OR failure both consume the call).
 */
final class GeocodeRateLimiter
{
    /**
     * Per-call admin override that lasts for the lifetime of the current
     * request/job. Used by GeocodingBackfillTrackedPropertiesCommand. Static
     * so a service method can short-circuit without the caller threading
     * the flag through.
     */
    private static bool $runtimeAdminOverride = false;

    public function canGeocode(?User $user = null): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        if ($this->adminOverride()) {
            return true;
        }

        $env = $this->environment();
        $envCap  = $this->getEnvironmentCap();
        $userCap = $this->getUserCap();

        $envCount = (int) Cache::get($this->envKey($env), 0);
        if ($envCount >= $envCap) {
            $this->logCapTripped('environment', $env, $envCount, $envCap);
            return false;
        }

        $u = $user ?? Auth::user();
        if ($u) {
            $userCount = (int) Cache::get($this->userKey($env, (int) $u->id), 0);
            if ($userCount >= $userCap) {
                $this->logCapTripped('user', $env, $userCount, $userCap);
                return false;
            }
        }

        return true;
    }

    /**
     * Throw-style helper for call sites that want to fail loud rather than
     * branch on a boolean. Used inside AddressResolverService.
     */
    public function assertCanGeocode(?User $user = null): void
    {
        if ($this->canGeocode($user)) {
            return;
        }

        $env = $this->environment();
        $envCount = (int) Cache::get($this->envKey($env), 0);
        $envCap   = $this->getEnvironmentCap();
        if ($envCount >= $envCap) {
            throw new GeocodeRateLimitException('environment', $envCount, $envCap, $env);
        }
        $u = $user ?? Auth::user();
        if ($u) {
            $userCount = (int) Cache::get($this->userKey($env, (int) $u->id), 0);
            $userCap   = $this->getUserCap();
            if ($userCount >= $userCap) {
                throw new GeocodeRateLimitException('user', $userCount, $userCap, $env);
            }
        }
        // enabled=false or unknown reason — generic message.
        throw new GeocodeRateLimitException('environment', 0, 0, $env);
    }

    public function recordCall(?User $user = null): void
    {
        if ($this->adminOverride()) {
            // We still record under admin override so the counter reflects
            // actual API spend; the cap just doesn't apply.
        }

        $env = $this->environment();
        $ttl = $this->secondsUntilEndOfDay();

        Cache::add($this->envKey($env), 0, $ttl);
        Cache::increment($this->envKey($env));

        $u = $user ?? Auth::user();
        if ($u) {
            Cache::add($this->userKey($env, (int) $u->id), 0, $ttl);
            Cache::increment($this->userKey($env, (int) $u->id));
        }
    }

    /**
     * @return array{env_used:int, env_remaining:int, env_cap:int, user_used:?int, user_remaining:?int, user_cap:int, environment:string}
     */
    public function getRemainingToday(?User $user = null): array
    {
        $env = $this->environment();
        $envCap   = $this->getEnvironmentCap();
        $userCap  = $this->getUserCap();
        $envUsed  = (int) Cache::get($this->envKey($env), 0);

        $u = $user ?? Auth::user();
        $userUsed = null; $userRemaining = null;
        if ($u) {
            $userUsed      = (int) Cache::get($this->userKey($env, (int) $u->id), 0);
            $userRemaining = max(0, $userCap - $userUsed);
        }

        return [
            'env_used'       => $envUsed,
            'env_remaining'  => max(0, $envCap - $envUsed),
            'env_cap'        => $envCap,
            'user_used'      => $userUsed,
            'user_remaining' => $userRemaining,
            'user_cap'       => $userCap,
            'environment'    => $env,
        ];
    }

    public function getEnvironmentCap(): int
    {
        return (int) config('geo.geocoding.environment_daily_cap', 100);
    }

    public function getUserCap(): int
    {
        return (int) config('geo.geocoding.user_daily_cap', 30);
    }

    /**
     * True when admin override is on: either the config flag is set, OR a
     * runtime override has been engaged for the current request/job
     * (typically by the backfill command — see ::engageRuntimeOverride()).
     */
    public function adminOverride(): bool
    {
        if (self::$runtimeAdminOverride) return true;
        return filter_var(config('geo.geocoding.admin_override_enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Engage the runtime override for the duration of the current PHP
     * request/CLI process. Use sparingly — backfill commands only.
     */
    public static function engageRuntimeOverride(): void
    {
        self::$runtimeAdminOverride = true;
    }

    public static function releaseRuntimeOverride(): void
    {
        self::$runtimeAdminOverride = false;
    }

    // ── Internals ──────────────────────────────────────────────────────────

    private function enabled(): bool
    {
        return filter_var(config('geo.geocoding.enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    private function environment(): string
    {
        return (string) (config('app.env') ?? 'local');
    }

    private function envKey(string $env): string
    {
        return sprintf('geocode_counter:%s:%s', $env, $this->todayKey());
    }

    private function userKey(string $env, int $userId): string
    {
        return sprintf('geocode_counter:%s:%s:user:%d', $env, $this->todayKey(), $userId);
    }

    private function todayKey(): string
    {
        return CarbonImmutable::now('Africa/Johannesburg')->toDateString();
    }

    private function secondsUntilEndOfDay(): int
    {
        $now = CarbonImmutable::now('Africa/Johannesburg');
        $eod = $now->endOfDay();
        return max(60, (int) $now->diffInSeconds($eod, false));
    }

    private function logCapTripped(string $which, string $env, int $current, int $cap): void
    {
        try {
            Log::channel('geocoding')->warning('Geocoding cap tripped', [
                'which_cap'    => $which,
                'environment'  => $env,
                'current'      => $current,
                'cap'          => $cap,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Geocoding cap tripped (fallback channel)', [
                'which_cap' => $which, 'env' => $env, 'current' => $current, 'cap' => $cap,
            ]);
        }
    }
}
