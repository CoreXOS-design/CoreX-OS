<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-work-orders.md section 17.40 (AT-465) - one "Send to office" from the crew: what they found and what is
 * needed, plus how many lines went with it. Never hard-deleted.
 */
class RentalJobCardCrewReport extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    protected $fillable = [
        'agency_id',
        'rental_job_card_id',
        'findings',
        'line_count',
        'sent_label',
        'sent_via',
        'sent_ip',
    ];

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(RentalJobCard::class, 'rental_job_card_id');
    }

    /** The lines that went with this send. */
    public function lines(): HasMany
    {
        return $this->hasMany(RentalJobCardLine::class, 'rental_job_card_crew_report_id');
    }
}
