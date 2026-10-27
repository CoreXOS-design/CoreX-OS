<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * Road to Sold v3 - one line of a prospect's history (road-to-sold.md section 22.12). APPEND-ONLY: this model refuses to update or
 * delete a row. Every stage count a manager sees is read from here, so a prospect moving on never loses the fact that it passed through.
 */
class RtsProspectEvent extends Model
{
    use BelongsToAgency;

    protected $table = 'rts_prospect_events';

    public const PLACED = 'placed';
    public const STAGE = 'stage';
    public const OUTCOME = 'outcome';
    public const NEXT_ACTION = 'next_action';
    public const PARK = 'park';
    public const UNPARK = 'unpark';
    public const RELEASE = 'release';
    public const CLOSE = 'close';

    protected $fillable = [
        'agency_id', 'track', 'prospect_id', 'user_id', 'actor_user_id', 'occurred_at', 'kind', 'from_stage', 'to_stage',
        'outcome_id', 'event_class', 'subject_type', 'subject_id', 'note', 'meta',
    ];

    protected $casts = ['occurred_at' => 'datetime', 'meta' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('The prospect history is append-only - a change is a new row.'));
        static::deleting(fn () => throw new \LogicException('The prospect history is append-only - nothing is deleted.'));
    }
}
