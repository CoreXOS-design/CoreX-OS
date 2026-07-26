<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Agency Onboarding Setup Wizard record — one resumable, token-gated setup
 * per agency, created when a live agency + Admin are created.
 *
 * Spec: .ai/specs/agency-onboarding-setup.md
 *
 * Mirrors P24OnboardingPortal (token/slug/expiry/revoke/open-tracking) and
 * adds wizard-progress state. The login gate authenticates the emailed link
 * against admin_user_id's real CoreX credentials — see ResolveAgencySetupPortal
 * + AgencySetupGateController.
 */
class AgencyOnboardingSetup extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'agency_onboarding_setups';

    /**
     * The ordered wizard steps. Keys persist in completed_steps; the count
     * drives progressPercent(). Mirrors the settings sections in
     * resources/views/corex/settings.blade.php ($railGroups).
     */
    public const STEPS = [
        'identity',       // 1  — Welcome / agency identity
        'capabilities',   // 2  — Feature switchboard (turn features on/off)
        'branding',       // 3  — Logo & agency colours (auto-detect + preview)
        'branches',       // 4  — Branches / offices
        'commission',     // 5  — Commission & revenue share
        'properties',     // 6  — Properties & listings
        'presentations',  // 7  — Presentations / CMA
        'matches',        // 8  — Matches
        'contacts',       // 9  — Contacts
        'compliance',     // 10 — Compliance
        'notifications',  // 11 — Notifications & dashboard
        'roles',          // 12 — How roles & permissions work (explainer)
        'access',         // 13 — Access & finish
    ];

    protected $fillable = [
        'agency_id',
        'token',
        'slug',
        'created_by',
        'admin_user_id',
        'current_step',
        'completed_steps',
        'expires_at',
        'revoked_at',
        'revoked_reason',
        'last_opened_at',
        'open_count',
        'completed_at',
    ];

    protected $casts = [
        'completed_steps' => 'array',
        'current_step'    => 'integer',
        'open_count'      => 'integer',
        'expires_at'      => 'datetime',
        'revoked_at'      => 'datetime',
        'last_opened_at'  => 'datetime',
        'completed_at'    => 'datetime',
    ];

    public static function totalSteps(): int
    {
        return count(self::STEPS);
    }

    /**
     * Adaptive step-gating (switchboard spec §3.3/§4.3). A step listed here is
     * ACTIVE only when its predicate returns true for the given agency; when
     * false the step is skipped entirely — not rendered, not counted in
     * progress, and show() redirects past it. Steps absent from this map are
     * always active.
     *
     * Built generic on purpose (BUILD_STANDARD §6): a future feature-step gates
     * itself by adding one entry. v1 gates only the `matches` detail step on the
     * Core Matches master switch — the one capability today whose detail is a
     * whole standalone step. Marketing / syndication / website have no standalone
     * detail step, so nothing else gates a whole step in v1.
     *
     * @return array<string, callable(?Agency=): bool>
     */
    public static function stepGates(): array
    {
        // Generalised through the feature registry (spec: corex-feature-registry.md
        // §7.3): a detail step is gated on its parent FEATURE. Adding a feature-step
        // = adding one stepKey => featureKey entry. The service reads the same store
        // (core-matches → matches_enabled) so behaviour is unchanged. Default ON so an
        // agency that skips the switchboard still sees the Matches step.
        $svc = app(\App\Services\Features\AgencyFeatureService::class);
        // Every wizard DETAIL step whose feature can be switched off is gated on
        // that feature, so turning it off in the capabilities step skips its setup
        // (the step's own copy promises exactly this). Only non-core features with a
        // standalone detail step appear here; core steps (identity/branding/branches/
        // commission/properties/contacts/notifications/roles/access) are never gated.
        $map = [
            'matches'       => 'core-matches',
            'presentations' => 'presentations',
            'compliance'    => 'compliance',
        ];

        return array_map(
            fn (string $featureKey) => fn (?Agency $agency = null): bool => $svc->enabled($featureKey, $agency),
            $map
        );
    }

    /**
     * STEPS filtered to those active for this agency (switchboard spec §4.3).
     * prev/next navigation and the progress denominator are computed over this
     * list, not raw STEPS, so a gated-off step is invisible to the flow and
     * 100% stays reachable without ever completing it.
     *
     * @return list<string>
     */
    public static function activeSteps(?Agency $agency = null): array
    {
        $gates = self::stepGates();

        return array_values(array_filter(
            self::STEPS,
            static function (string $step) use ($gates, $agency): bool {
                $gate = $gates[$step] ?? null;
                return $gate === null ? true : (bool) $gate($agency);
            }
        ));
    }

    /**
     * Is a given step key active (not gated off) for this agency?
     */
    public static function stepIsActive(string $step, ?Agency $agency = null): bool
    {
        return in_array($step, self::activeSteps($agency), true);
    }

    public static function generateToken(): string
    {
        do {
            $t = Str::random(40);
        } while (static::withTrashed()->where('token', $t)->exists());
        return $t;
    }

    public static function generateSlug(?string $label, ?int $fallbackId = null): string
    {
        $base = Str::slug((string) $label) ?: ('agency-setup-' . ($fallbackId ?? Str::random(6)));
        $slug = $base;
        $i = 2;
        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /**
     * The URL segment we route by — prefers the human-readable slug if set,
     * else falls back to the token.
     */
    public function urlKey(): string
    {
        return $this->slug ?: $this->token;
    }

    public function publicUrl(): string
    {
        return url('/agency-setup/' . $this->urlKey());
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'agency_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }

    /**
     * A setup is "active" (link usable) while it is neither revoked nor expired.
     * Note: unlike the P24 portal, a COMPLETED setup stays re-openable in the
     * browser (spec §3.6) — completion is not an inactive state for re-entry.
     */
    public function isActive(): bool
    {
        if ($this->revoked_at) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        return true;
    }

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    public function statusLabel(): string
    {
        if ($this->revoked_at) return 'Revoked';
        if ($this->completed_at) return 'Completed';
        if ($this->expires_at && $this->expires_at->isPast()) return 'Expired';
        if (!empty($this->completed_steps)) return 'In progress';
        return 'Not started';
    }

    /**
     * Progress as a percentage. The denominator is the ACTIVE-step count when an
     * agency is supplied (switchboard spec §3.3/§4.3) — a gated-off step can never
     * be completed, so 100% would otherwise be unreachable. Falls back to the full
     * step count when no agency is given (owner tracking page / legacy callers),
     * preserving existing behaviour. The numerator counts only completed steps that
     * are still active, so re-disabling a feature whose detail step was already
     * completed keeps the bar honest.
     */
    public function progressPercent(?Agency $agency = null): int
    {
        $steps = $agency ? self::activeSteps($agency) : self::STEPS;
        $total = count($steps);
        if ($total === 0) return 0;

        $done = is_array($this->completed_steps)
            ? count(array_intersect($this->completed_steps, $steps))
            : 0;

        return (int) round(min($done, $total) / $total * 100);
    }

    /**
     * Mark a step key complete (idempotent — no duplicates) and advance
     * current_step to the next incomplete step. Persists.
     */
    public function markStepComplete(string $stepKey): void
    {
        if (!in_array($stepKey, self::STEPS, true)) {
            return;
        }
        $done = is_array($this->completed_steps) ? $this->completed_steps : [];
        if (!in_array($stepKey, $done, true)) {
            $done[] = $stepKey;
        }
        $this->completed_steps = array_values($done);

        // Advance the resume pointer to the 1-based index of the first step
        // not yet completed (or the last step if all are done).
        $next = self::totalSteps();
        foreach (self::STEPS as $i => $key) {
            if (!in_array($key, $done, true)) {
                $next = $i + 1;
                break;
            }
        }
        $this->current_step = $next;
        $this->save();
    }
}
