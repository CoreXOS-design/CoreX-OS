<?php

namespace App\Models\CommandCenter;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class CalendarEventLink extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'calendar_event_id',
        'linkable_type',
        'linkable_id',
        'role',
        'created_by_user_id',
    ];

    public const ROLE_SUBJECT_PROPERTY = 'subject_property';
    public const ROLE_ATTENDEE         = 'attendee';
    public const ROLE_RELATED_DEAL     = 'related_deal';
    public const ROLE_BUYER_CONTACT    = 'buyer_contact';
    public const ROLE_SELLER_CONTACT   = 'seller_contact';

    /**
     * contact_property.role values (free text, NOT this model's own role
     * column) that classify a linked contact as the buyer side for the
     * calendar's attendee auto-fill / tick-list. Single source of truth for
     * CalendarEventService::propertyOwners() and
     * CalendarController::propertyOwners() — previously duplicated
     * literals in both, which is how 'lead' (284 live contact_property
     * rows, Chanri Gardens: Shawn had 14 leads and 0 tickable buyers) went
     * unnoticed in one of the two places as long as it was missing from
     * both. 2026-08-20 (Johan, CX-107).
     *
     * 'tenant'/'lessee' match zero live contact_property rows today but
     * are left in deliberately — they are the correct rental-side
     * equivalent, not dead weight to prune.
     *
     * Deliberately NOT referenced by BuyersBackfillFlagCommand, which does
     * a different job (backfilling contacts.is_buyer) and keeps its own
     * literal list — see that file if it ever needs to include leads too;
     * that is a separate decision, not a de-duplication of this one.
     */
    public const PROPERTY_PIVOT_BUYER_ROLES = ['buyer', 'tenant', 'lessee', 'lead'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'calendar_event_id');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
