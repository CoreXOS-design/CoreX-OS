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

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }
}
