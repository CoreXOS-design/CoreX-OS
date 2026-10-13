<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-454 — one outcome an agent can pick in the "Contacted client" popup (agency-defined).
 * Spec: .ai/specs/road-to-sold.md §10.
 */
class RtsOutcomeDefinition extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $table = 'rts_outcome_definitions';

    public const KIND_ATTEMPT      = 'attempt';
    public const KIND_CONVERSATION = 'conversation';
    public const KINDS = [self::KIND_ATTEMPT, self::KIND_CONVERSATION];

    public const ACTION_AFTER_DAYS      = 'return_after_days';
    public const ACTION_PICKED_DATE     = 'return_on_picked_date';
    public const ACTION_EXPIRY_ELSE     = 'return_on_expiry_else_picked';
    public const ACTION_MOVE_TO_LIST    = 'move_to_list';
    public const ACTION_NEVER_AGAIN     = 'never_again';
    public const ACTION_APPOINTMENT     = 'calendar_appointment';
    public const ACTIONS = [
        self::ACTION_AFTER_DAYS, self::ACTION_PICKED_DATE, self::ACTION_EXPIRY_ELSE,
        self::ACTION_MOVE_TO_LIST, self::ACTION_NEVER_AGAIN, self::ACTION_APPOINTMENT,
    ];

    protected $fillable = [
        'agency_id', 'track', 'key', 'label', 'kind', 'next_action', 'delay_days', 'list_tag',
        'counts_as_no_answer', 'records_do_not_contact', 'is_active', 'is_system', 'sort_order',
        'updated_by_user_id',
    ];

    protected $casts = [
        'delay_days'             => 'integer',
        'counts_as_no_answer'    => 'boolean',
        'records_do_not_contact' => 'boolean',
        'is_active'              => 'boolean',
        'is_system'              => 'boolean',
        'sort_order'             => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForTrack($query, string $track = 'sales')
    {
        return $query->where('track', $track);
    }

    public function isConversation(): bool
    {
        return $this->kind === self::KIND_CONVERSATION;
    }

    /** Plain-English sentence describing what happens next (used under the label in settings). */
    public function nextActionSummary(): string
    {
        return match ($this->next_action) {
            self::ACTION_AFTER_DAYS   => 'Comes back after ' . (int) $this->delay_days . ' day' . ((int) $this->delay_days === 1 ? '' : 's'),
            self::ACTION_PICKED_DATE  => 'Comes back on a date the agent picks',
            self::ACTION_EXPIRY_ELSE  => 'Comes back on the mandate expiry date, or a date the agent picks',
            self::ACTION_MOVE_TO_LIST => 'Moves to the list "' . ($this->list_tag ?: 'Tagged') . '"',
            self::ACTION_NEVER_AGAIN  => 'Never suggested again, for any agent',
            self::ACTION_APPOINTMENT  => 'Becomes a calendar appointment and leaves the call list',
            default                   => '',
        };
    }
}
