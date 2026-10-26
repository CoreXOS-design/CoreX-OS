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
        'signing_mode',
    ];

    // .ai/specs/esign-signing-modes.md — how this agency's signers sign. Fixed per envelope when it is created.
    // Johan, 26 Oct 2026: two choices. "Adopt once" = adopt a signature + initial once, then click each place to apply
    // AND/OR press "Apply to all my places" on the same document.
    public const MODE_SIGN_EACH = 'sign_each';
    public const MODE_ADOPT_ONCE = 'adopt_once';
    public const SIGNING_MODES = [self::MODE_SIGN_EACH, self::MODE_ADOPT_ONCE];

    // The two adoption modes that existed before the ruling. Rows and envelopes may still carry them; both mean adopt_once.
    public const LEGACY_ADOPT_MODES = ['adopt_then_stamp', 'adopt_and_fill_all'];

    /** Map any stored value (including the two retired adoption modes) onto one of SIGNING_MODES; anything unknown is sign_each. */
    public static function normaliseMode(?string $mode): string
    {
        if ($mode === self::MODE_ADOPT_ONCE || in_array($mode, self::LEGACY_ADOPT_MODES, true)) {
            return self::MODE_ADOPT_ONCE;
        }

        return self::MODE_SIGN_EACH;
    }

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
                'signing_mode' => self::MODE_SIGN_EACH,
            ]);
        }

        return static::firstOrNew(
            ['agency_id' => $agencyId],
            [
                'async_completion_enabled' => true,
                'finalization_stuck_threshold_minutes' => 15,
                'whatsapp_resend_enabled' => true,
                'signing_mode' => self::MODE_SIGN_EACH,
            ]
        );
    }

    /**
     * The signing mode an agency's NEW envelopes are created in. Anything unknown, an agency that never saved the
     * setting, or no agency context at all, is sign_each — today's behaviour (esign-signing-modes.md rule 1).
     */
    public static function signingModeFor(?int $agencyId): string
    {
        if (! $agencyId || $agencyId <= 0) {
            return self::MODE_SIGN_EACH;
        }

        $mode = static::query()->where('agency_id', $agencyId)->value('signing_mode');

        return self::normaliseMode(is_string($mode) ? $mode : null);
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
