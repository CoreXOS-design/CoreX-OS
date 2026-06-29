<?php

namespace App\Models\Outreach;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Models\SellerOutreach\SellerOutreachTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-117 §7 — a deferred outreach message: prepared now, surfaced at due_at,
 * worked by hand. Tenant-owned (BelongsToAgency → AgencyScope auto-stamps and
 * isolates agency_id) and soft-deleted (cancel/archive is never a hard delete).
 *
 * Lifecycle: pending → surfaced → sent ; or dropped (consent revoked at surface)
 * / expired / cancelled. The sweep and UI use the scopes below; on dispatch the
 * row links to its canonical send-record via seller_outreach_send_id.
 */
class OutreachQueue extends Model
{
    use BelongsToAgency;
    use BelongsToBranch; // AT-120 — branch tier (auto-stamp branch_id + BranchScope)
    use SoftDeletes;

    protected $table = 'outreach_queue';

    // AT-117 (simplified): no due-time/surfacing — a queued row is immediately
    // READY to send; the ONLY send gate is the agency send-window at dispatch.
    public const STATUS_READY     = 'ready';
    public const STATUS_SENT      = 'sent';
    public const STATUS_DROPPED   = 'dropped';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_CONTACT = 'contact';
    public const SOURCE_MAP     = 'map';
    public const SOURCE_MIC     = 'mic';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'contact_id',
        'property_id',
        'agent_id',
        'template_id',
        'seller_outreach_send_id',
        'channel',
        'source',
        'body_snapshot',
        'due_at',
        'status',
        'claimed_at',
        'surfaced_at',
        'sent_at',
        'dropped_reason',
    ];

    protected $casts = [
        'due_at'      => 'datetime',
        'claimed_at'  => 'datetime',
        'surfaced_at' => 'datetime',
        'sent_at'     => 'datetime',
    ];

    protected $attributes = [
        'channel' => 'whatsapp',
        'status'  => self::STATUS_READY, // created prepared-and-ready (no time-gated pending)
    ];

    // ── Relations ────────────────────────────────────────────────────────
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SellerOutreachTemplate::class, 'template_id');
    }

    public function sellerOutreachSend(): BelongsTo
    {
        return $this->belongsTo(SellerOutreachSend::class, 'seller_outreach_send_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────

    /** Prepared-and-ready rows (the work-list). */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_READY);
    }

    public function scopeForAgent(Builder $query, int $agentId): Builder
    {
        return $query->where('agent_id', $agentId);
    }

    /**
     * AT-120 — canonical own/branch/all visibility (mirrors CalendarEvent::scopeVisibleTo).
     * The "owner" of a queue row is its preparing agent (agent_id). BranchScope already
     * isolates by branch automatically when Split Branches is on; this adds the
     * own-narrowing (agent) and the explicit branch tier, and 'none' for no access.
     */
    public function scopeVisibleTo(Builder $query, User $user, ?string $scope): Builder
    {
        return match ($scope) {
            'all'    => $query,
            'branch' => $user->effectiveBranchId()
                ? $query->where('branch_id', $user->effectiveBranchId())
                : $query->where('agent_id', $user->id),
            'none'   => $query->whereRaw('1 = 0'),
            default  => $query->where('agent_id', $user->id), // 'own' or null
        };
    }
}
