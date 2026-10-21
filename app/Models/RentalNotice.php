<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-portal-access.md §8/§10 — AT-445. An append-only record
 * of a sent notice. Not edited after sending — a sending error is handled
 * by the normal soft-delete floor, never an update.
 */
class RentalNotice extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'lease_id',
        'rental_notice_template_id',
        'notice_type',
        'figures',
        'sent_to_tenant',
        'sent_to_landlord',
        'document_id',
        'sent_at',
        'sent_by_user_id',
        'lease_breach_id',
        'delivery_status',
    ];

    protected $casts = [
        'figures' => 'array',
        'sent_to_tenant' => 'boolean',
        'sent_to_landlord' => 'boolean',
        'sent_at' => 'datetime',
    ];

    /** Deleted-related-record rule (.ai/BUILD_STANDARD.md §4) — see Lease::property(). */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class)->withTrashed();
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(RentalNoticeTemplate::class, 'rental_notice_template_id');
    }

    /**
     * What the agency calls this document ("Notice of breach") - its own template's name - for the email subject, the file name and the portal.
     * Falls back to the generic type label only when the template is gone. Read without scopes: a client-portal request has no agency context.
     */
    public function documentTitle(): string
    {
        $name = trim((string) RentalNoticeTemplate::withoutGlobalScopes()->withTrashed()->whereKey($this->rental_notice_template_id)->value('name'));

        return $name !== '' ? $name : RentalNoticeTemplate::labelFor($this->notice_type);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** .ai/specs/rental-notices.md - the breach this notice (breach notice / notice to vacate) belongs to. */
    public function breach(): BelongsTo
    {
        return $this->belongsTo(LeaseBreach::class, 'lease_breach_id')->withTrashed();
    }

    /** AT-495 - every attempt to email this notice, newest first (the first send and each resend). */
    public function deliveries(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RentalNoticeDelivery::class)->orderByDesc('id');
    }

    /** AT-495 - the summary of the latest attempt per recipient: 'sent' | 'partial' | 'not_sent', or null for a notice from before deliveries were recorded. */
    public const DELIVERY_SENT = 'sent';
    public const DELIVERY_PARTIAL = 'partial';
    public const DELIVERY_NOT_SENT = 'not_sent';

    public const DELIVERY_LABELS = [
        self::DELIVERY_SENT => 'Emailed',
        self::DELIVERY_PARTIAL => 'Partly sent',
        self::DELIVERY_NOT_SENT => 'NOT sent',
    ];

    public function deliveryLabel(): string
    {
        return self::DELIVERY_LABELS[$this->delivery_status] ?? 'Not recorded';
    }

    /**
     * The latest delivery row for each recipient (a resend replaces the earlier answer for that person), oldest recipient first.
     *
     * @return \Illuminate\Support\Collection<int, RentalNoticeDelivery>
     */
    public function latestDeliveries(): \Illuminate\Support\Collection
    {
        return $this->deliveries->unique(fn (RentalNoticeDelivery $d) => $d->recipient_role . '|' . ($d->contact_id ?? 'x' . $d->id))->reverse()->values();
    }

    /** A notice made by the breach flow is told and resent from the breach screen, not here. */
    public function isResendable(): bool
    {
        return $this->lease_breach_id === null && ($this->sent_to_tenant || $this->sent_to_landlord);
    }

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
