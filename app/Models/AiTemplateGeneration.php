<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One idea submitted to the AI Template Builder (AT-456 Part B, spec §B.4).
 *
 * Counts toward the agency's monthly allowance only when status = success. Holds the
 * validated options so saving one never trusts what the browser sends back.
 *
 * Tenant table: BelongsToAgency. Append-only log — there is no delete or archive action
 * anywhere (non-negotiable #1 honoured by absence); the only thing ever removed is the
 * reference image FILE, after 7 days.
 */
class AiTemplateGeneration extends Model
{
    use BelongsToAgency;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    protected $fillable = [
        'agency_id', 'user_id', 'idea', 'ad_size', 'property_type',
        'reference_image_path', 'reference_image_purged_at',
        'status', 'failure_reason', 'cost_zar', 'options_json', 'validation_log_json', 'saved_template_ids',
    ];

    protected $casts = [
        'cost_zar'                  => 'decimal:4',
        'options_json'              => 'array',
        'validation_log_json'       => 'array',
        'saved_template_ids'        => 'array',
        'reference_image_purged_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
