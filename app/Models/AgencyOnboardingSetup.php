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
        'branding',       // 2  — Logo & agency colours (auto-detect + preview)
        'commission',     // 3  — Commission & revenue share
        'properties',     // 4  — Properties & listings
        'presentations',  // 5  — Presentations / CMA
        'matches',        // 6  — Matches
        'contacts',       // 7  — Contacts
        'compliance',     // 8  — Compliance
        'notifications',  // 9  — Notifications & dashboard
        'access',         // 10 — Access & finish
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

    public function progressPercent(): int
    {
        $done = is_array($this->completed_steps) ? count($this->completed_steps) : 0;
        $total = self::totalSteps();
        if ($total === 0) return 0;
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
