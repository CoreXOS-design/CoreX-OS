<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated 2026-04-21 — Replaced by App\Models\Compliance\FicaOfficerAppointment.
 * Table renamed to fica_compliance_officers_deprecated_20260421.
 * Kept for reference only — do not use in new code.
 */
class FicaComplianceOfficer extends Model
{
    protected $fillable = [
        'user_id',
        'assigned_by',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
