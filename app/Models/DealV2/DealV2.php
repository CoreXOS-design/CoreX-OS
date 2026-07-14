<?php

namespace App\Models\DealV2;

use App\Models\Branch;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventLink;
use App\Models\Contact;
use App\Models\PerformanceSetting;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
class DealV2 extends Model
{
    use BelongsToBranch, BelongsToAgency, SoftDeletes;

    protected $table = 'deals_v2';

    protected $fillable = [
        'agency_id',
        'legacy_deal_id', // WS1 — DR1↔DR2 link (deals.id of the mirrored DR1 twin)
        'reference',
        'deal_type',
        'status',
        'property_id',
        'listing_agent_id',
        'selling_agent_id',
        'pipeline_template_id',
        'linked_deal_id',
        'purchase_price',
        'commission_percentage',
        'commission_amount',
        'commission_vat',
        'listing_split_percent',
        'listing_external',
        'listing_our_share_percent',
        'listing_external_agency',
        'selling_split_percent',
        'selling_external',
        'selling_our_share_percent',
        'selling_external_agency',
        'commission_status',
        'offer_date',
        'expected_registration',
        'actual_registration',
        'overall_rag',
        'backfilled_at',
        'notes',
        'branch_id',
        'created_by_id',
    ];

    protected $casts = [
        'offer_date' => 'date',
        'expected_registration' => 'date',
        'actual_registration' => 'date',
        'purchase_price' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'commission_vat' => 'decimal:2',
        'listing_external' => 'boolean',
        'selling_external' => 'boolean',
        'backfilled_at' => 'datetime',
    ];

    /**
     * A "pre-pipeline" twin: a DR1 deal backfilled into DR2 for register
     * completeness (Johan-ruled, .ai/specs/dr2-twin-backfill.md). It carries NO
     * pipeline — no step instances, no clocks, no RAG countdown — and the UI
     * must present it as captured pre-pipeline, never as a mis-configured deal.
     */
    public function isPrePipeline(): bool
    {
        return $this->backfilled_at !== null;
    }

    // ── Relationships ──

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function listingAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'listing_agent_id');
    }

    public function sellingAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selling_agent_id');
    }

    public function pipelineTemplate(): BelongsTo
    {
        return $this->belongsTo(DealPipelineTemplate::class, 'pipeline_template_id');
    }

    public function linkedDeal(): BelongsTo
    {
        return $this->belongsTo(self::class, 'linked_deal_id');
    }

    public function linkedFromDeals(): HasMany
    {
        return $this->hasMany(self::class, 'linked_deal_id');
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'deal_v2_contacts', 'deal_id', 'contact_id')
            ->withPivot('role');
    }

    /**
     * WS2 (D2) — provider parties on the deal: rows of deal_v2_contacts keyed by
     * agency_service_provider_id (contact_id NULL). A deal party is a contact OR
     * a directory provider; this is the provider side.
     */
    public function providerParties(): BelongsToMany
    {
        return $this->belongsToMany(AgencyServiceProvider::class, 'deal_v2_contacts', 'deal_id', 'agency_service_provider_id')
            ->withPivot('role')
            ->wherePivotNotNull('agency_service_provider_id');
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'deal_v2_agents', 'deal_id', 'user_id')
            ->withPivot([
                'side', 'agent_split_percent', 'agent_cut_percent',
                'paye_method', 'paye_value', 'deductions', 'deductions_description',
                'paid_at', 'sliding_granted_month', 'sliding_sequence_in_month',
                'sliding_applied_cut_percent', 'sliding_applied_at',
            ])
            ->withTimestamps();
    }

    public function listingAgents(): BelongsToMany
    {
        return $this->agents()->wherePivot('side', 'listing');
    }

    public function sellingAgents(): BelongsToMany
    {
        return $this->agents()->wherePivot('side', 'selling');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(DealV2Settlement::class, 'deal_id');
    }

    public function stepInstances(): HasMany
    {
        return $this->hasMany(DealStepInstance::class, 'deal_id')->orderBy('position');
    }

    public function activityLog(): HasMany
    {
        return $this->hasMany(DealActivityLog::class, 'deal_id')->orderBy('created_at', 'desc');
    }

    /** AT-158 WS-V2 — stage advances (auto/prompt/undo) — the undo + audit spine. */
    public function stageMoves(): HasMany
    {
        return $this->hasMany(DealStageMove::class, 'deal_id')->orderBy('created_at', 'desc');
    }

    /** AT-158 WS-V6 — free-form remarks (the DR1 addRemark analogue). */
    public function remarks(): HasMany
    {
        return $this->hasMany(DealRemark::class, 'deal_id')->orderBy('created_at', 'desc');
    }

    /** The single pending stage-prompt awaiting confirm (prompt mode), if any. */
    public function pendingStageMove()
    {
        return $this->stageMoves()->where('state', 'pending')->latest('id')->first();
    }

    /** The most recent applied/confirmed move that can still be undone, if any. */
    public function undoableStageMove()
    {
        return $this->stageMoves()
            ->whereIn('state', ['applied', 'confirmed'])
            ->whereNull('undone_at')
            ->latest('id')
            ->first();
    }

    /**
     * AT-158 WS3 (D4) — unified documents anchored directly to this deal
     * (upload-onto-deal, PDF-splitter deal target, e-sign auto-file). This is
     * the deal-level document spine; per-step files live on
     * stepInstances()->documents() and, once populated, also point back to a
     * unified document via DealStepDocument::document().
     */
    public function documents(): HasMany
    {
        return $this->hasMany(\App\Models\Document::class, 'deal_id')->latest();
    }

    /**
     * AT-158 WS4 (§8) — document distributions sent from this deal (secure-link
     * or direct-attachment sends, with their lifecycle status + comms anchor).
     */
    public function distributions(): HasMany
    {
        return $this->hasMany(DealDocumentDistribution::class, 'deal_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    // ── Commission Methods (ported from V1 Deal model) ──

    public function commissionExVat(): float
    {
        $vatRate = (float) PerformanceSetting::get('vat_rate', 15) / 100.0;
        $inc = (float) ($this->commission_amount + $this->commission_vat);
        if ($inc <= 0 || $vatRate <= 0) {
            return (float) $this->commission_amount;
        }
        return $inc / (1.0 + $vatRate);
    }

    private function calculateInternalPool(string $side): float
    {
        if ((bool) $this->{$side . '_external'}) {
            return 0.0;
        }

        $sidePct = (float) ($this->{$side . '_split_percent'} ?? 50);
        $ourPct = (float) ($this->{$side . '_our_share_percent'} ?? 100);

        return $this->commissionExVat() * ($sidePct / 100.0) * ($ourPct / 100.0);
    }

    public function listingPool(): float
    {
        return $this->calculateInternalPool('listing');
    }

    public function sellingPool(): float
    {
        return $this->calculateInternalPool('selling');
    }

    public function totalOurCommission(): float
    {
        return $this->listingPool() + $this->sellingPool();
    }

    public function isFinanciallyLocked(): bool
    {
        return (string) ($this->commission_status ?? '') === 'Paid';
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * @param  string|null  $requestedScope  Overview scope-switcher request
     *   (own|branch|all|company). Server-authoritative: CLAMPED to the user's
     *   permitted scope — a switcher can only NARROW, never widen the gate
     *   (WS8, §12). Null/absent = the user's full permitted scope (existing
     *   behaviour, so all current callers are unaffected).
     */
    public function scopeVisibleTo($query, User $user, ?string $requestedScope = null)
    {
        $scope = self::clampScope($requestedScope, PermissionService::getDataScope($user, 'deals_v2'));

        if ($scope === 'all') {
            return $query;
        }

        if ($scope === 'branch') {
            return $query->where('branch_id', $user->branch_id);
        }

        // AT-267 — an assistant's 'own' is their Assigned Agent's; everyone else: [$user->id].
        $identityIds = $user->dataIdentityIds();

        return $query->where(function ($q) use ($identityIds) {
            $q->whereIn('listing_agent_id', $identityIds)
              ->orWhereIn('selling_agent_id', $identityIds)
              ->orWhereIn('created_by_id', $identityIds);
        });
    }

    /**
     * Narrow the requested scope to at most the permitted scope. Ranking
     * own(1) < branch(2) < all(3); "company" is an alias for "all". Returns the
     * MIN of requested and permitted, so a branch manager asking for "all"
     * still gets "branch", and any junk falls back to permitted.
     */
    public static function clampScope(?string $requested, ?string $permitted): string
    {
        $rank = ['own' => 1, 'branch' => 2, 'all' => 3, 'company' => 3];
        $permitted = $permitted ?: 'own';
        $permRank = $rank[$permitted] ?? 1;
        if ($requested === null || ! isset($rank[$requested])) {
            return $permitted === 'company' ? 'all' : $permitted;
        }
        $effRank = min($rank[$requested], $permRank);

        return array_search($effRank, ['own' => 1, 'branch' => 2, 'all' => 3], true) ?: 'own';
    }

    // ── Helper Methods ──

    public function buyers()
    {
        return $this->contacts()->wherePivotIn('role', ['buyer', 'co_buyer']);
    }

    public function sellers()
    {
        return $this->contacts()->wherePivotIn('role', ['seller', 'co_seller']);
    }

    public function currentMilestone()
    {
        $completed = $this->stepInstances()
            ->where('is_milestone', true)
            ->where('status', 'completed')
            ->orderBy('position', 'desc')
            ->first();

        return $completed ?? $this->stepInstances()
            ->where('is_milestone', true)
            ->where('status', 'active')
            ->orderBy('position')
            ->first();
    }

    public function activeSteps()
    {
        return $this->stepInstances()->where('status', 'active');
    }

    public function overdueSteps()
    {
        return $this->stepInstances()->where('status', 'overdue');
    }

    public static function generateReference(): string
    {
        $year = now()->format('Y');
        $prefix = "DL-{$year}-";

        $latest = static::withTrashed()
            ->where('reference', 'like', $prefix . '%')
            ->orderBy('reference', 'desc')
            ->value('reference');

        if ($latest) {
            $lastNumber = (int) substr($latest, strlen($prefix));
            $next = $lastNumber + 1;
        } else {
            $next = 1;
        }

        return $prefix . str_pad($next, 5, '0', STR_PAD_LEFT);
    }

    // ── Calendar event links (M2.2) ──

    public function calendarEventLinks(): MorphMany
    {
        return $this->morphMany(CalendarEventLink::class, 'linkable');
    }

    public function calendarEvents()
    {
        return $this->morphToMany(CalendarEvent::class, 'linkable', 'calendar_event_links', null, 'calendar_event_id');
    }
}
