<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * .ai/specs/rental-inspections.md §45.8 (Build I-6b) — the append-only history of an inspection: who did what,
 * when. Same intent as RentalApplicationAuditLog, simpler shape.
 *
 * APPEND-ONLY, enforced here and not just by convention: an update or a delete of a row throws. Written through
 * record(), which never lets a failure to log break the action being logged — but a failure is logged LOUDLY
 * (error level), never swallowed silently.
 */
class RentalInspectionAuditLog extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    protected $table = 'rental_inspection_audit_log';

    public const EVENT_CREATED = 'created';
    public const EVENT_DETAILS_EDITED = 'details_edited';
    public const EVENT_STATUS_CHANGED = 'status_changed';
    public const EVENT_RESCHEDULED = 'rescheduled';
    public const EVENT_CANCELLED = 'cancelled';
    public const EVENT_ARCHIVED = 'archived';
    public const EVENT_RESTORED = 'restored';
    public const EVENT_PUBLIC_LINK_ISSUED = 'public_link_issued';
    public const EVENT_PUBLIC_LINK_REVOKED = 'public_link_revoked';
    public const EVENT_ATTENDANCE_RECORDED = 'attendance_recorded';
    public const EVENT_ATTENDANCE_CORRECTED = 'attendance_corrected';
    public const EVENT_ATTENDANCE_WITHDRAWN = 'attendance_withdrawn';
    public const EVENT_INVITATION_RECORDED = 'invitation_recorded';
    public const EVENT_SIGNATURE_SUPERSEDED = 'signature_superseded';
    public const EVENT_FINDING_SUPERSEDED = 'finding_superseded';
    public const EVENT_COPIES_SENT = 'report_copies_sent';
    // §46 — signing by personal link.
    public const EVENT_SIGNING_LINK_SENT = 'signing_link_sent';
    public const EVENT_SIGNING_LINK_REVOKED = 'signing_link_revoked';
    public const EVENT_SIGNED_BY_LINK = 'signed_by_link';
    // §47 — "Edit report" on a signed inspection.
    public const EVENT_REOPENED = 'report_reopened';
    public const EVENT_REPLACED = 'replaced';
    // §51 — the signing-window reminder to the agent.
    public const EVENT_SIGNING_REMINDER = 'signing_reminder';

    /** @var array<string, string> event key => the label the History panel's filter shows */
    public const EVENT_LABELS = [
        self::EVENT_CREATED => 'Created',
        self::EVENT_DETAILS_EDITED => 'Details edited',
        self::EVENT_STATUS_CHANGED => 'Status changed',
        self::EVENT_RESCHEDULED => 'Rescheduled',
        self::EVENT_CANCELLED => 'Cancelled',
        self::EVENT_ARCHIVED => 'Archived',
        self::EVENT_RESTORED => 'Restored',
        self::EVENT_PUBLIC_LINK_ISSUED => 'Public link issued',
        self::EVENT_PUBLIC_LINK_REVOKED => 'Public link revoked',
        self::EVENT_ATTENDANCE_RECORDED => 'Attendance recorded',
        self::EVENT_ATTENDANCE_CORRECTED => 'Attendance corrected',
        self::EVENT_ATTENDANCE_WITHDRAWN => 'Attendance withdrawn',
        self::EVENT_INVITATION_RECORDED => 'Invitation recorded',
        self::EVENT_SIGNATURE_SUPERSEDED => 'Signature replaced',
        self::EVENT_FINDING_SUPERSEDED => 'Finding replaced',
        self::EVENT_COPIES_SENT => 'Report copies sent',
        self::EVENT_SIGNING_LINK_SENT => 'Signing link sent',
        self::EVENT_SIGNING_LINK_REVOKED => 'Signing link revoked',
        self::EVENT_SIGNED_BY_LINK => 'Signed from a link',
        self::EVENT_REOPENED => 'Report reopened for editing',
        self::EVENT_REPLACED => 'Replaced / replaces',
        self::EVENT_SIGNING_REMINDER => 'Signing reminder to the agent',
    ];

    protected $fillable = [
        'agency_id', 'rental_inspection_id', 'event', 'user_id', 'summary', 'before', 'after', 'created_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new \LogicException('The inspection history is append-only — a row is never edited.');
        });
        static::deleting(function () {
            throw new \LogicException('The inspection history is append-only — a row is never deleted.');
        });
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(RentalInspection::class, 'rental_inspection_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function eventLabel(): string
    {
        return self::EVENT_LABELS[$this->event] ?? ucfirst(str_replace('_', ' ', $this->event));
    }

    /**
     * Write one history row. The actor is the acting user (or `$by`); null = the system acted. Never throws: a
     * history row that cannot be written must not undo or block the action it describes — it is logged as an
     * ERROR so the gap is visible, not silent.
     *
     * @param  array<string, mixed>|null  $before  the changed fields' old values
     * @param  array<string, mixed>|null  $after   the changed fields' new values
     */
    public static function record(RentalInspection $inspection, string $event, ?string $summary = null, ?array $before = null, ?array $after = null, ?User $by = null): ?self
    {
        try {
            return static::create([
                'agency_id' => $inspection->agency_id,
                'rental_inspection_id' => $inspection->id,
                'event' => $event,
                'user_id' => $by?->id ?? auth()->id(),
                'summary' => $summary !== null ? mb_substr($summary, 0, 500) : null,
                'before' => $before === [] ? null : $before,
                'after' => $after === [] ? null : $after,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Rental inspection history row could not be written', [
                'inspection_id' => $inspection->id, 'event' => $event, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
