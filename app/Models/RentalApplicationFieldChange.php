<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-516 - one immutable row per applicant-entered field an agent corrected on the live values after submission. Mirrors
 * RentalApplicationStatusHistory: created_at only, written through the RentalApplicationDetailEditor, never updated (bar the
 * emailed_at stamp for the batch) and never deleted. The applicant's signed answers are NOT here - they stay sealed in
 * RentalApplicationGeneration.
 */
class RentalApplicationFieldChange extends Model
{
    use BelongsToAgency;

    public const UPDATED_AT = null;

    protected $fillable = [
        'rental_application_id', 'agency_id', 'batch_uuid', 'generation', 'field_key', 'field_label',
        'old_value', 'new_value', 'changed_by_user_id', 'note', 'emailed_at', 'created_at',
    ];

    protected $casts = [
        'generation' => 'integer',
        'emailed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function rentalApplication(): BelongsTo
    {
        return $this->belongsTo(RentalApplication::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
