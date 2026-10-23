<?php

namespace App\Models\RoadToSold;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-454 phase 1 - a real CoreX record (tracked property, contact, property, deal ...) a completion produced
 * (road-to-sold.md section 5.4), so "what did the drive produce?" is a list of real records. Rows are never edited or removed.
 */
class RtsTaskCompletionRecord extends Model
{
    use BelongsToAgency;

    protected $table = 'rts_task_completion_records';

    protected $fillable = ['agency_id', 'completion_id', 'record_type', 'record_id'];

    public function completion(): BelongsTo
    {
        return $this->belongsTo(RtsTaskCompletion::class, 'completion_id')->withTrashed();
    }
}
