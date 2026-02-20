<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceAuditItem extends Model
{
    protected $fillable = [
        'audit_run_id',
        'definition_key',
        'entity_type',
        'entity_id',
        'period',
        'expected_numeric',
        'actual_numeric',
        'diff_numeric',
        'expected_json',
        'actual_json',
        'diff_json',
        'severity',
        'message',
    ];

    protected $casts = [
        'expected_json' => 'array',
        'actual_json' => 'array',
        'diff_json' => 'array',
        'expected_numeric' => 'decimal:6',
        'actual_numeric' => 'decimal:6',
        'diff_numeric' => 'decimal:6',
    ];

    public function auditRun()
    {
        return $this->belongsTo(FinanceAuditRun::class, 'audit_run_id');
    }
}
