<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A mixed global / per-agency key-value settings store.
 *
 * A row with `agency_id = NULL` is the PLATFORM DEFAULT (global fallback); a row
 * with an `agency_id` is that agency's override. `get()` resolves the agency row
 * first, then the global row — so genuinely-global keys (vat_rate, per-page
 * counts) work unchanged (they only ever have a NULL-agency row), while
 * tenant-scoped keys (the switchboard toggles) resolve per agency.
 *
 * 2026-08-15 (Johan, HFC tenant-isolation fix) — `company_*` keys
 * (company_name/company_address/company_tel/company_ffc/company_logo_url)
 * were WRONGLY documented and treated as "genuinely global" here: a single
 * NULL-agency row held Home Finders Coastal's own real business details,
 * and any agency without its own override silently inherited HFC's name/
 * address/phone/FFC number onto printed documents (CMA, commission
 * calculator, deal settlements). These are tenant-scoped, full stop — see
 * AGENCY_ONLY_KEYS below. They resolve to the agency's own row if one
 * exists, and to the caller's $default otherwise (every call site already
 * passes the current agency's own Agency-model fields as $default) — NEVER
 * to the global row, even though rows with agency_id=NULL still exist in
 * the table for these keys (harmless, orphaned, kept for audit history).
 *
 * Tenant-scoped writes MUST go through `set($key, $value, $agencyId)` (or pass an
 * explicit agency_id to `get()` in queue/console contexts that have no auth) —
 * never a bare `updateOrCreate(['key' => ...])`, which would ignore the agency.
 */
class PerformanceSetting extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'key',
        'value',
    ];

    /**
     * Keys that must NEVER fall back to the global (agency_id=NULL) row —
     * see the 2026-08-15 class docblock note. Matched by exact key or by
     * the 'company_' prefix so any future company_* addition is covered
     * without another code change.
     */
    private static function isAgencyOnlyKey(string $key): bool
    {
        return str_starts_with($key, 'company_');
    }

    /**
     * Resolve a setting, preferring the agency's own row over the global default.
     *
     * @param  int|null  $agencyId  explicit agency (required where there is no auth,
     *                              e.g. queued jobs / console); when null, resolves
     *                              the current user's effective agency; when still
     *                              null, reads only the global (NULL-agency) row
     *                              (or nothing at all for an agency-only key).
     */
    public static function get(string $key, $default = null, ?int $agencyId = null)
    {
        $agencyId = $agencyId ?? self::currentAgencyId();
        $agencyOnly = self::isAgencyOnlyKey($key);

        if ($agencyId !== null) {
            if ($agencyOnly) {
                // Agency's own row ONLY — the global row is never consulted,
                // no matter what it holds. Falls straight to $default below.
                $value = static::where('key', $key)->where('agency_id', $agencyId)->value('value');
            } else {
                // Agency row OR the global fallback, agency-first, in a single
                // query. orderByRaw: `agency_id IS NULL` is 0 for the agency
                // row (first) and 1 for the global row (last), so the
                // override wins when present.
                $value = static::where('key', $key)
                    ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereNull('agency_id'))
                    ->orderByRaw('agency_id IS NULL')
                    ->value('value');
            }
        } elseif ($agencyOnly) {
            $value = null; // no authenticated agency context — nothing safe to read
        } else {
            $value = static::where('key', $key)->whereNull('agency_id')->value('value');
        }

        return $value ?? $default;
    }

    /**
     * Write a setting for an agency (or globally when $agencyId resolves to null).
     * The single per-agency write path.
     */
    public static function set(string $key, $value, ?int $agencyId = null): void
    {
        $agencyId = $agencyId ?? self::currentAgencyId();

        static::updateOrCreate(
            ['agency_id' => $agencyId, 'key' => $key],
            ['value' => $value]
        );
    }

    private static function currentAgencyId(): ?int
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        return method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);
    }
}
