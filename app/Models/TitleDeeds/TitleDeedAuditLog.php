<?php

namespace App\Models\TitleDeeds;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/title-deed-register.md §10 — the audit trail shown on the record. Same shape and the same never-throws
 * `record()` as RentalInspectionAuditLog: an audit write that fails is logged, it never breaks the user's action.
 */
class TitleDeedAuditLog extends Model
{
    use BelongsToAgency;

    protected $table = 'title_deed_audit_log';

    public $timestamps = false;

    public const EVENT_CREATED = 'created';
    public const EVENT_EDITED = 'edited';
    public const EVENT_SCAN_UPLOADED = 'scan_uploaded';
    public const EVENT_SCAN_DOWNLOADED = 'scan_downloaded';
    public const EVENT_NOTICE_SENT = 'notice_sent';
    public const EVENT_WHATSAPP_OPENED = 'whatsapp_opened';
    public const EVENT_WHATSAPP_CONFIRMED = 'whatsapp_confirmed';
    public const EVENT_WHATSAPP_NOT_SENT = 'whatsapp_not_sent';
    public const EVENT_LOCATION_CHANGED = 'location_changed';
    public const EVENT_SIGNING_LINK_CREATED = 'signing_link_created';
    public const EVENT_SIGNING_LINK_OPENED = 'signing_link_opened';
    public const EVENT_SIGNING_LINK_SIGNED = 'signing_link_signed';
    public const EVENT_SIGNING_LINK_SENT = 'signing_link_sent';
    public const EVENT_SIGNING_LINK_CANCELLED = 'signing_link_cancelled';
    public const EVENT_SCAN_REPLACED = 'scan_replaced';
    public const EVENT_PIN_ISSUED = 'pin_issued';
    public const EVENT_PIN_RESENT = 'pin_resent';
    public const EVENT_PIN_REPLACED = 'pin_replaced';
    public const EVENT_PIN_WRONG = 'pin_wrong';
    public const EVENT_PIN_LOCKED = 'pin_locked';
    public const EVENT_CONSENT_REQUESTED = 'consent_requested';
    public const EVENT_CONSENT_APPROVED = 'consent_approved';
    public const EVENT_CONSENT_DECLINED = 'consent_declined';
    public const EVENT_PHONE_CONSENT = 'phone_consent';
    public const EVENT_AUTHORITY_LETTER = 'authority_letter';
    public const EVENT_OVERRIDE = 'override';
    public const EVENT_HANDOVER = 'handover';
    public const EVENT_REMINDER_SENT = 'reminder_sent';
    public const EVENT_ARCHIVED = 'archived';
    public const EVENT_RESTORED = 'restored';

    protected $fillable = ['agency_id', 'title_deed_id', 'event', 'user_id', 'summary', 'before', 'after', 'created_at'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(TitleDeed $deed, string $event, ?string $summary = null, ?array $before = null, ?array $after = null, ?User $by = null): ?self
    {
        try {
            return static::withoutGlobalScopes()->create([
                'agency_id' => $deed->agency_id,
                'title_deed_id' => $deed->id,
                'event' => $event,
                'user_id' => $by?->id ?? auth()->id(),
                'summary' => $summary !== null ? mb_substr($summary, 0, 500) : null,
                'before' => $before === [] ? null : $before,
                'after' => $after === [] ? null : $after,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Title deed audit row could not be written', ['title_deed_id' => $deed->id, 'event' => $event, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
