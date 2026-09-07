<?php

namespace App\Models\Docuperfect;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-agency e-sign settings. One row per agency, created lazily on first
 * save from the E-Sign → Finalisation Settings screen.
 *
 * Resolution order (Johan, 2026-08-31): the agency's own saved row wins; the
 * `DOCUPERFECT_ASYNC_COMPLETION` env value (via config('docuperfect.async_completion'))
 * is the fallback ONLY when no row exists yet for that agency — never deleted,
 * kept so nothing breaks for an agency that has never opened this settings
 * screen.
 */
class EsignSettings extends Model
{
    protected $table = 'docuperfect_esign_settings';

    protected $fillable = [
        'agency_id',
        'async_completion_enabled',
        'finalization_stuck_threshold_minutes',
        'require_identity_before_send',
        'strict_reauthorisation_binding',
    ];

    protected $casts = [
        'async_completion_enabled' => 'boolean',
        'finalization_stuck_threshold_minutes' => 'integer',
        'require_identity_before_send' => 'boolean',
        'strict_reauthorisation_binding' => 'boolean',
    ];

    /**
     * Resolve (never persist) the settings for an agency. Rule 17 guard:
     * agencyId <= 0 (no tenant context — console/webhook/owner-no-agency)
     * returns in-memory defaults, never a DB row.
     */
    public static function forAgency(int $agencyId): self
    {
        if ($agencyId <= 0) {
            return new self([
                'agency_id' => 0,
                'async_completion_enabled' => (bool) config('docuperfect.async_completion'),
                'finalization_stuck_threshold_minutes' => 15,
                'require_identity_before_send' => true,
                'strict_reauthorisation_binding' => true,
            ]);
        }

        return static::firstOrNew(
            ['agency_id' => $agencyId],
            [
                'async_completion_enabled' => true,
                'finalization_stuck_threshold_minutes' => 15,
                'require_identity_before_send' => true,
                'strict_reauthorisation_binding' => true,
            ]
        );
    }

    /**
     * True/false the agency explicitly chose, once they've ever saved this
     * screen; otherwise the env fallback (config('docuperfect.async_completion')),
     * per the resolution order above.
     */
    public function asyncCompletionEnabled(): bool
    {
        return $this->exists
            ? (bool) $this->async_completion_enabled
            : (bool) config('docuperfect.async_completion');
    }

    public function finalizationStuckThresholdMinutes(): int
    {
        $minutes = (int) ($this->finalization_stuck_threshold_minutes ?? 15);

        return $minutes > 0 ? $minutes : 15;
    }

    /**
     * AT-385 — Fill & Review blocks send if any signing party has no
     * ID/passport number. Default true — Johan: "no id is a massive
     * problem... we have to gate against it properly." No env fallback,
     * this setting never existed as an env flag.
     */
    public function requireIdentityBeforeSend(): bool
    {
        return $this->exists ? (bool) $this->require_identity_before_send : true;
    }

    /**
     * AT-332 — after a recipient amends a document, re-authorisation must
     * come from the same user who authorised the original. Default true —
     * Johan: "re-auth only allowed by original auth party."
     */
    public function strictReauthorisationBinding(): bool
    {
        return $this->exists ? (bool) $this->strict_reauthorisation_binding : true;
    }
}
