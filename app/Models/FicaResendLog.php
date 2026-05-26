<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class FicaResendLog extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'fica_submission_id',
        'resent_by',
        'resent_at',
        'reason_code',
        'notes',
    ];

    protected $casts = [
        'resent_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FicaSubmission::class, 'fica_submission_id');
    }

    public function resentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resent_by');
    }
}
