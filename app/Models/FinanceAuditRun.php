<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class FinanceAuditRun extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'period',
        'scope',
        'status',
        'engine_version',
        'started_at',
        'finished_at',
        'created_by',
    ];

    protected $casts = [
        'scope' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function items()
    {
        return $this->hasMany(FinanceAuditItem::class, 'audit_run_id');
    }
}
