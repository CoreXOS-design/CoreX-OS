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
        'whatsapp_resend_enabled',
    ];

    protected $casts = [
        'async_completion_enabled' => 'boolean',
        'finalization_stuck_threshold_minutes' => 'integer',
        'whatsapp_resend_enabled' => 'boolean',
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
                'whatsapp_resend_enabled' => true,
            ]);
        }

        return static::firstOrNew(
            ['agency_id' => $agencyId],
            [
                'async_completion_enabled' => true,
                'finalization_stuck_threshold_minutes' => 15,
                'whatsapp_resend_enabled' => true,
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
     * AT-385/AT-332 — WhatsApp is a secondary, agent-clicked resend method
     * (Johan: email stays primary, nothing routes through WhatsApp). Default
     * true for an agency that has never saved this screen — no env fallback
     * needed, this setting never existed as an env flag.
     */
    public function whatsappResendEnabled(): bool
    {
        return $this->exists ? (bool) $this->whatsapp_resend_enabled : true;
    }
}
