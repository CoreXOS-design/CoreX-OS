<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * .ai/specs/rental-work-orders.md §3a — "who did what" for a fault report.
 * Mirrors RentalWorkOrderUpdate exactly — same shape, same reasoning, the
 * closest existing sibling in this same feature family. Append-only, never
 * edited or deleted.
 */
class RentalFaultReportUpdate extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    public const TYPE_LOGGED = 'logged';
    public const TYPE_APPROVAL_REQUESTED = 'approval_requested';
    public const TYPE_APPROVAL_RECORDED = 'approval_recorded';
    public const TYPE_WORK_ORDER_RAISED = 'work_order_raised';
    public const TYPE_OUTCOME_SET = 'outcome_set';
    public const TYPE_STATUS_CHANGE = 'status_change';
    public const TYPE_NOTE = 'note';
    public const TYPE_ARCHIVED = 'archived';
    public const TYPE_RESTORED = 'restored';
    /** Fault flow F2 - the agent saved the sanitised version the owner will see. */
    public const TYPE_OWNER_VERSION_SAVED = 'owner_version_saved';
    /** AT-452 (rentals-faults-work-orders.md §18) - agent marks and per-photo notes: who, when, which photo. */
    public const TYPE_PHOTO_MARK_ADDED = 'photo_mark_added';
    public const TYPE_PHOTO_MARK_CHANGED = 'photo_mark_changed';
    public const TYPE_PHOTO_MARK_REMOVED = 'photo_mark_removed';
    public const TYPE_PHOTO_MARK_RESTORED = 'photo_mark_restored';
    public const TYPE_PHOTO_NOTE_SET = 'photo_note_set';
    public const TYPE_PHOTO_NOTE_REMOVED = 'photo_note_removed';
    /** AT-452 item 9 - the agent confirmed sending to the owner while no contractor covered this type of work. */
    /** Item I - the agent went ahead under the no-approval limit / as an emergency and the owner was told. */
    public const TYPE_ARRANGED_BY_AGENT = 'arranged_by_agent';
    /** Item I - the owner's "Query this" note on a fault they were informed about. */
    public const TYPE_OWNER_QUERY = 'owner_query';
    // AT-452 (§19.12) - the owner asked for a repair of a fault the tenant resolved, with their own comment
    public const TYPE_OWNER_REPAIR_REQUEST = 'owner_repair_request';
    public const TYPE_OWNER_COMMENT = 'owner_comment';
    public const TYPE_SENT_WITHOUT_CONTRACTOR = 'sent_without_contractor';
    // section 21.3 - the agent chose a first responder to look at the fault (nothing is sent by this alone)
    public const TYPE_FIRST_RESPONDER_CHOSEN = 'first_responder_chosen';
    // section 22 - the authorisation record (Decision 1: who goes to look), the owner's decision taken by the agent, the tenant's confirmation
    public const TYPE_AUTHORISATION_RECORDED = 'authorisation_recorded';
    public const TYPE_AUTHORISATION_SUPERSEDED = 'authorisation_superseded';
    public const TYPE_SENT_TO_OWNER_TO_AUTHORISE = 'sent_to_owner_to_authorise';
    public const TYPE_TENANT_CONFIRMED = 'tenant_confirmed';

    protected $fillable = [
        'agency_id',
        'rental_fault_report_id',
        'update_type',
        'from_status',
        'to_status',
        'note',
        'created_by_user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $update) {
            if (empty($update->created_at)) {
                $update->created_at = now();
            }
        });
    }

    public function faultReport(): BelongsTo
    {
        return $this->belongsTo(RentalFaultReport::class, 'rental_fault_report_id');
    }

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
