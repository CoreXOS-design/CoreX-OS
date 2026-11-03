<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\RoadToSold\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 — the Road to Sold call log: one row per captured outcome. History on the property
 * (tracked_property_id) and on the owner contact (contact_id). Spec: .ai/specs/road-to-sold.md §10.
 */
class RtsContactOutcome extends Model
{
    use BelongsToAgency, BelongsToBranch, SoftDeletes;

    protected $table = 'rts_contact_outcomes';

    /** v4 (road-to-sold.md 23.7): how the owner was contacted. A short list in code, labelled in plain words. NULL = recorded before v4. */
    public const CHANNELS = [
        'phone'     => 'Phone call',
        'whatsapp'  => 'WhatsApp',
        'email'     => 'Email',
        'in_person' => 'In person',
    ];

    public static function channelLabel(?string $channel): string
    {
        return $channel === null || $channel === '' ? 'Channel not recorded' : (self::CHANNELS[$channel] ?? ucfirst(str_replace('_', ' ', $channel)));
    }

    /** True once the channel column exists (added by migration). */
    public static function hasChannelColumn(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn((new static())->getTable(), 'channel');
    }

    protected $fillable = [
        'agency_id', 'branch_id', 'track', 'user_id', 'recorded_by_user_id', 'tracked_property_id', 'property_id',
        'prospecting_listing_id', 'list_item_id', 'contact_id', 'outcome_definition_id',
        'outcome_key', 'outcome_label', 'kind', 'channel', 'note', 'valuation_booked', 'calendar_event_id',
        'next_action', 'next_action_at', 'list_tag', 'no_answer_streak', 'dropped', 'is_current',
        'occurred_at',
    ];

    protected $casts = [
        'valuation_booked' => 'boolean',
        'dropped'          => 'boolean',
        'is_current'       => 'boolean',
        'next_action_at'   => 'date',
        'occurred_at'      => 'datetime',
        'no_answer_streak' => 'integer',
    ];

    public function scopeVisibleTo($query, User $user, ?string $requestedScope = null)
    {
        return Scope::apply($query, $user, $requestedScope);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** Who actually made the call: the assistant when one recorded it for the agent; null = the agent themselves. */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id')->withTrashed();
    }

    public function trackedProperty(): BelongsTo
    {
        return $this->belongsTo(TrackedProperty::class, 'tracked_property_id')->withTrashed();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class)->withTrashed();
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(RtsOutcomeDefinition::class, 'outcome_definition_id')->withTrashed();
    }

    public function isConversation(): bool
    {
        return $this->kind === RtsOutcomeDefinition::KIND_CONVERSATION;
    }
}
