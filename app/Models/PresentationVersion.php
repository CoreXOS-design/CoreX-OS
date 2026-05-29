<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class PresentationVersion extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'presentation_id',
        'compiled_by',
        'blueprint_version',
        'analytics_run_id',
        'probability_run_id',
        'data_snapshot_json',
        'hydration_summary_json',
        'compiled_at',
        // Phase 3 — AI summary fields.
        'ai_variant_id',
        'ai_summary_text',
        'ai_summary_raw_text',
        'ai_summary_edited_by_agent',
        'ai_summary_generated_at',
        'ai_summary_edited_at',
        'ai_summary_model',
        'ai_summary_prompt_hash',
        'ai_summary_input_facts_json',
        // Build 2 — per-version review lifecycle.
        'review_status',
        'reviewer_user_id',
        'reviewer_locked_at',
        'awaiting_review_at',
        'published_at',
        'archived_at',
        'included_comp_ids_json',
        // Build 3 — condition snapshot.
        'condition_level_id',
        'condition_adjustment_pct',
        'condition_label',
        // Build 4 — per-version section toggles snapshot.
        'enabled_sections_json',
        // Build 5 — full report payload snapshot.
        'snapshot_payload',
        'snapshot_taken_at',
    ];

    protected $casts = [
        'compiled_at'                 => 'datetime',
        'hydration_summary_json'      => 'array',
        'ai_summary_edited_by_agent'  => 'boolean',
        'ai_summary_generated_at'     => 'datetime',
        'ai_summary_edited_at'        => 'datetime',
        'ai_summary_input_facts_json' => 'array',
        // Build 2 — review-flow timestamps.
        'reviewer_locked_at'          => 'datetime',
        'awaiting_review_at'          => 'datetime',
        'published_at'                => 'datetime',
        'archived_at'                 => 'datetime',
        'included_comp_ids_json'      => 'array',
        'enabled_sections_json'       => 'array',
        // Build 5.
        'snapshot_payload'            => 'array',
        'snapshot_taken_at'           => 'datetime',
    ];

    // Build 2 — review_status states.
    public const REVIEW_DRAFT           = 'draft';
    public const REVIEW_AWAITING        = 'awaiting_review';
    public const REVIEW_PUBLISHED       = 'published';
    public const REVIEW_ARCHIVED        = 'archived';

    /** Concurrent-reviewer window (minutes). A second agent opening the
     *  version within this window sees the "currently being reviewed by X"
     *  banner. After the window expires the lock is considered stale. */
    public const REVIEWER_LOCK_MINUTES = 10;

    // ── Build 4 — toggleable report sections ────────────────────────────
    // Floor sections are RENDERED ALWAYS regardless of enabled_sections_json
    // (cover + subject facts card are implicit, executive_summary is the
    // visible floor — shown in settings as locked-on for transparency).
    public const SECTION_EXECUTIVE_SUMMARY  = 'executive_summary';
    public const SECTION_MARKET_OVERVIEW    = 'market_overview';
    public const SECTION_RECENT_SALES       = 'recent_sales';
    public const SECTION_SPATIAL_VIEW       = 'spatial_view';
    public const SECTION_CMA_ANALYSIS       = 'cma_analysis';
    public const SECTION_ACTIVE_COMPETITION = 'active_competition';
    public const SECTION_INFLOW_ABSORPTION  = 'inflow_absorption';
    public const SECTION_HOLDING_COST       = 'holding_cost';
    public const SECTION_PRICING_STRATEGY   = 'pricing_strategy';

    /** Ordered map: section_key => display label. Drives the settings UI,
     *  the review screen checklist, and the order things render in. */
    public const SECTIONS_CATALOGUE = [
        self::SECTION_EXECUTIVE_SUMMARY  => 'Executive Summary',
        self::SECTION_MARKET_OVERVIEW    => 'Market Overview',
        self::SECTION_RECENT_SALES       => 'Recent Sales / Vicinity',
        self::SECTION_SPATIAL_VIEW       => 'Spatial View (Map)',
        self::SECTION_CMA_ANALYSIS       => 'CMA Analysis',
        self::SECTION_ACTIVE_COMPETITION => 'Active Competition',
        self::SECTION_INFLOW_ABSORPTION  => 'P24 Inflow & Absorption',
        self::SECTION_HOLDING_COST       => 'Holding Cost Analysis',
        self::SECTION_PRICING_STRATEGY   => 'Pricing Strategy & Recommendation',
    ];

    /** Sections that ALWAYS render. Toggle UI surfaces them as locked-on
     *  so agents see the full report shape but can't break the floor. */
    public const SECTION_FLOOR = [
        self::SECTION_EXECUTIVE_SUMMARY,
    ];

    /**
     * Hard dependencies — when key is OFF, value (a list) goes OFF too.
     * Compiler + toggle endpoint enforce. Reversed: when a dependent is
     * turned ON, every key it depends on is auto-enabled.
     */
    public const SECTION_DEPENDENCIES = [
        // Pricing Strategy reads CMA bands at render time — see
        // PresentationPdfService L1902 `if ($cmaMiddle && $cmaUpper)`.
        // Without CMA the section is structurally broken.
        self::SECTION_PRICING_STRATEGY => [self::SECTION_CMA_ANALYSIS],
    ];

    /** Rough page-count estimates per section. Drives the live preview
     *  on the review screen ("Estimated final page count: ~7 pages").
     *  Conservative — actual layout depends on data density. */
    public const SECTION_PAGE_ESTIMATE = [
        'floor'                          => 2, // cover + subject facts
        self::SECTION_EXECUTIVE_SUMMARY  => 1,
        self::SECTION_MARKET_OVERVIEW    => 1,
        self::SECTION_RECENT_SALES       => 1,
        self::SECTION_SPATIAL_VIEW       => 1,
        self::SECTION_CMA_ANALYSIS       => 1,
        self::SECTION_ACTIVE_COMPETITION => 1,
        self::SECTION_INFLOW_ABSORPTION  => 1,
        self::SECTION_HOLDING_COST       => 1,
        self::SECTION_PRICING_STRATEGY   => 1,
    ];

    public function reviewerUser()
    {
        return $this->belongsTo(\App\Models\User::class, 'reviewer_user_id');
    }

    public function agentOverrides()
    {
        return $this->hasMany(AgentOverride::class, 'presentation_version_id');
    }

    public function isReviewerLockActive(): bool
    {
        if (!$this->reviewer_user_id || !$this->reviewer_locked_at) return false;
        return $this->reviewer_locked_at
            ->gt(now()->subMinutes(self::REVIEWER_LOCK_MINUTES));
    }

    public function aiVariant()
    {
        return $this->belongsTo(PresentationAiVariant::class, 'ai_variant_id');
    }

    public function hasAiSummary(): bool
    {
        return !empty($this->ai_summary_text);
    }

    public function presentation()
    {
        return $this->belongsTo(Presentation::class);
    }

    public function compiledBy()
    {
        return $this->belongsTo(User::class, 'compiled_by');
    }

    public function getSnapshotArray(): array
    {
        return json_decode($this->data_snapshot_json, true) ?? [];
    }

    // ── Build 4 — section toggle helpers ────────────────────────────────

    /**
     * Is the given section enabled on this version?
     *
     * Floor sections are ALWAYS enabled regardless of the snapshot —
     * the renderer cannot skip them. For non-floor sections we honour
     * the snapshot; null/missing keys default to TRUE so legacy
     * versions (pre-Build-4) keep rendering the full report.
     */
    public function isSectionEnabled(string $sectionKey): bool
    {
        if (in_array($sectionKey, self::SECTION_FLOOR, true)) {
            return true;
        }
        $sections = $this->enabled_sections_json ?? [];
        if (!is_array($sections) || !array_key_exists($sectionKey, $sections)) {
            return true; // missing key = legacy/default = ON
        }
        return (bool) $sections[$sectionKey];
    }

    /**
     * Apply a section toggle, enforcing dependencies.
     *
     *  - Turning a section OFF cascades to anything that DEPENDS on it.
     *  - Turning a section ON auto-enables every dependency.
     *  - Floor sections are silently coerced to ON.
     *
     * Returns the snapshot AFTER the change plus the diff of any sections
     * that the cascade flipped, so the caller (toggle endpoint) can log
     * agent_overrides for each and surface the cascade to the agent.
     *
     * @param  string  $sectionKey
     * @param  bool    $enabled
     * @return array{snapshot: array<string, bool>, cascaded: array<string, bool>}
     */
    public function applySectionToggle(string $sectionKey, bool $enabled): array
    {
        $snapshot = $this->enabled_sections_json ?? [];
        // Seed every catalogue key with a default true so cascades can
        // see "everything else is on" cleanly.
        foreach (array_keys(self::SECTIONS_CATALOGUE) as $key) {
            if (!array_key_exists($key, $snapshot)) {
                $snapshot[$key] = true;
            }
        }

        $cascaded = [];
        $applyChange = function (string $key, bool $newValue) use (&$snapshot, &$cascaded) {
            if (in_array($key, self::SECTION_FLOOR, true)) {
                $newValue = true; // floor cannot be turned off
            }
            $prev = $snapshot[$key] ?? true;
            if ($prev === $newValue) return;
            $snapshot[$key] = $newValue;
            $cascaded[$key] = $newValue;
        };

        $applyChange($sectionKey, $enabled);

        if ($enabled) {
            // Turning ON cascades to enable every dependency.
            foreach (self::SECTION_DEPENDENCIES[$sectionKey] ?? [] as $dependency) {
                $applyChange($dependency, true);
            }
        } else {
            // Turning OFF cascades to disable every dependent.
            foreach (self::SECTION_DEPENDENCIES as $dependent => $deps) {
                if (in_array($sectionKey, $deps, true) && ($snapshot[$dependent] ?? true)) {
                    $applyChange($dependent, false);
                }
            }
        }

        // Floor sections never flip off — re-stamp to true regardless.
        foreach (self::SECTION_FLOOR as $floorKey) {
            $snapshot[$floorKey] = true;
        }

        // The triggering toggle itself is not "cascaded" — it was the
        // request. Strip it from the cascade diff for the response.
        unset($cascaded[$sectionKey]);

        $this->enabled_sections_json = $snapshot;
        $this->save();

        return ['snapshot' => $snapshot, 'cascaded' => $cascaded];
    }

    /** Sum of enabled-section page estimates plus the floor. Drives the
     *  review-screen preview. Conservative — actual PDF layout varies. */
    public function estimatedPageCount(): int
    {
        $total = self::SECTION_PAGE_ESTIMATE['floor'] ?? 2;
        foreach (self::SECTIONS_CATALOGUE as $key => $_label) {
            if ($this->isSectionEnabled($key)) {
                $total += self::SECTION_PAGE_ESTIMATE[$key] ?? 1;
            }
        }
        return $total;
    }
}
