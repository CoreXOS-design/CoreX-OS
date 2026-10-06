<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use App\Models\DealV2\AgencyServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * .ai/specs/rental-portal-access.md §4/§6 — AT-445. A per-job secure link
 * for a contractor with no CoreX login. Only a hash of the token is ever
 * stored; the raw token exists only in the one URL shown to the agent.
 *
 * .ai/specs/rental-work-orders.md §14.27 — the same table also carries the
 * crew's links: one per job card (`crew_job_card`) and one standing link per
 * crew (`crew_standing`). Exactly one target (work order / job card / crew)
 * is set per row, chosen by `purpose`; isLive() switches on it.
 */
class RentalSecureAccessToken extends Model
{
    use BelongsToAgency;

    public const PURPOSE_CONTRACTOR_WORK_ORDER = 'contractor_work_order';
    public const PURPOSE_CREW_JOB_CARD = 'crew_job_card';
    public const PURPOSE_CREW_STANDING = 'crew_standing';
    /** §17.10.3 — the tenant's one-click response link for ONE completion round (Build 3 mints and resolves it). */
    public const PURPOSE_TENANT_COMPLETION = 'tenant_completion';

    protected $fillable = [
        'agency_id',
        'rental_work_order_id',
        'rental_completion_round_id',
        'rental_job_card_id',
        'rental_crew_id',
        'agency_service_provider_id',
        'token_hash',
        'purpose',
        'expires_at',
        'revoked_at',
        'last_used_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(RentalWorkOrder::class, 'rental_work_order_id');
    }

    /** Unscoped + withTrashed: a public request has no agency context, and an archived card must resolve so isLive() can refuse it. */
    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id')->withoutGlobalScopes()->withTrashed();
    }

    /** Same reasoning as jobCard(). */
    public function crew(): BelongsTo
    {
        return $this->belongsTo(RentalCrew::class, 'rental_crew_id')->withoutGlobalScopes()->withTrashed();
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(AgencyServiceProvider::class, 'agency_service_provider_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isLive(): bool
    {
        if ($this->revoked_at) {
            return false;
        }
        // Null expires_at = no expiry (a crew-page link may stand until revoked).
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return match ($this->purpose ?: self::PURPOSE_CONTRACTOR_WORK_ORDER) {
            self::PURPOSE_CREW_JOB_CARD => $this->crewJobCardTargetIsLive(),
            self::PURPOSE_CREW_STANDING => $this->crewStandingTargetIsLive(),
            // §17.10.3 — the tenant's response link for ONE completion round (Build 3). Its own arm so a tenant_completion
            // token can never fall through to the contractor rule below.
            self::PURPOSE_TENANT_COMPLETION => $this->tenantCompletionTargetIsLive(),
            default => $this->contractorTargetIsLive(),
        };
    }

    /** §17.10.3 — the completion round this token answers (null for every other purpose). */
    public function completionRound(): BelongsTo
    {
        return $this->belongsTo(RentalWorkCompletionRound::class, 'rental_completion_round_id')->withoutGlobalScopes();
    }

    /**
     * §17.10.3 — live while the link has not expired (window end + 7 days, set when minted) and its round, work
     * order and property still exist in the same agency. NOT tied to the answer: after a response the SAME link shows
     * a read-only "You answered" page, and after the window it says the response period has ended — the controller
     * decides which page from the round's outcome, so those are not "unavailable".
     */
    private function tenantCompletionTargetIsLive(): bool
    {
        $round = $this->completionRound;
        if (! $round || (int) $round->agency_id !== (int) $this->agency_id) {
            return false;
        }
        $workOrder = RentalWorkOrder::withoutGlobalScopes()->withTrashed()->find($round->rental_work_order_id);
        if (! $workOrder || $workOrder->trashed() || (int) $workOrder->agency_id !== (int) $this->agency_id) {
            return false;
        }
        $property = Property::withoutGlobalScopes()->withTrashed()->find($workOrder->property_id);

        return $property !== null && ! $property->trashed();
    }

    private function contractorTargetIsLive(): bool
    {
        if (!\App\Models\RentalPortalSetting::contractorLinksEnabledFor($this->agency_id)) {
            return false;
        }
        $workOrder = $this->workOrder;
        if ($workOrder && in_array($workOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true)) {
            return false;
        }

        return true;
    }

    /** §14.27.5 — live only while the master switch is on and the card is open, not archived, on a non-archived property. */
    private function crewJobCardTargetIsLive(): bool
    {
        if (!\App\Models\RentalPortalSetting::crewLinksEnabledFor($this->agency_id)) {
            return false;
        }
        $card = $this->jobCard;
        if (!$card || $card->trashed() || $card->isClosed() || (int) $card->agency_id !== (int) $this->agency_id) {
            return false;
        }
        $property = $card->property()->withoutGlobalScopes()->withTrashed()->first();

        return $property !== null && !$property->trashed();
    }

    /** §14.27.5 — live only while the master switch is on and the crew is active and not archived. */
    private function crewStandingTargetIsLive(): bool
    {
        if (!\App\Models\RentalPortalSetting::crewLinksEnabledFor($this->agency_id)) {
            return false;
        }
        $crew = $this->crew;

        return $crew !== null && !$crew->trashed() && $crew->is_active && (int) $crew->agency_id === (int) $this->agency_id;
    }

    /**
     * Generate a new raw token (40+ random bytes, hex-encoded) and return it
     * alongside its hash. The raw value is never persisted or logged — only
     * handed back once, to be embedded in the link shown to the agent.
     */
    public static function generateRawToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    public static function findLiveByRawToken(string $rawToken): ?self
    {
        $hash = self::hashToken($rawToken);

        /** @var self|null $record */
        $record = static::withoutGlobalScopes()->where('token_hash', $hash)->first();

        return $record;
    }
}
