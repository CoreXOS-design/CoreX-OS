<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceAuditRun extends Model
{
    protected $fillable = [
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
