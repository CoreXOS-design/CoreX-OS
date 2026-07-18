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
 * counts, company_*) work unchanged (they only ever have a NULL-agency row),
 * while tenant-scoped keys (the switchboard toggles) resolve per agency.
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
     * Resolve a setting, preferring the agency's own row over the global default.
     *
     * @param  int|null  $agencyId  explicit agency (required where there is no auth,
     *                              e.g. queued jobs / console); when null, resolves
     *                              the current user's effective agency; when still
     *                              null, reads only the global (NULL-agency) row.
     */
    public static function get(string $key, $default = null, ?int $agencyId = null)
    {
        $agencyId = $agencyId ?? self::currentAgencyId();

        if ($agencyId !== null) {
            // Agency row OR the global fallback, agency-first, in a single query.
            // orderByRaw: `agency_id IS NULL` is 0 for the agency row (first) and
            // 1 for the global row (last), so the override wins when present.
            $value = static::where('key', $key)
                ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereNull('agency_id'))
                ->orderByRaw('agency_id IS NULL')
                ->value('value');
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
