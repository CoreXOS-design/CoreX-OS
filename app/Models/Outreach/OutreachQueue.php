<?php

namespace App\Models\Outreach;

use App\Models\Concerns\BelongsToAgency;
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
    use SoftDeletes;

    protected $table = 'outreach_queue';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_SURFACED  = 'surfaced';
    public const STATUS_SENT      = 'sent';
    public const STATUS_DROPPED   = 'dropped';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_CONTACT = 'contact';
    public const SOURCE_MAP     = 'map';
    public const SOURCE_MIC     = 'mic';

    protected $fillable = [
        'agency_id',
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
        'status'  => self::STATUS_PENDING,
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

    // ── Scopes (for the sweep + UI in later steps) ───────────────────────

    /** Pending rows whose due_at has arrived — the sweep's claim set. */
    public function scopeDue(Builder $query, ?\Carbon\Carbon $at = null): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('due_at', '<=', $at ?? now());
    }

    public function scopeSurfaced(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SURFACED);
    }

    public function scopeForAgent(Builder $query, int $agentId): Builder
    {
        return $query->where('agent_id', $agentId);
    }
}
