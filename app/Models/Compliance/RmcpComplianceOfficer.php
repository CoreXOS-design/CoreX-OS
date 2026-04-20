<?php

namespace App\Models\Compliance;

use App\Models\Agency;
use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @deprecated 2026-04-21 — Replaced by FicaOfficerAppointment.
 * Table renamed to rmcp_compliance_officers_deprecated_20260421.
 * Kept for reference only — do not use in new code.
 */
class RmcpComplianceOfficer extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $table = 'rmcp_compliance_officers';

    protected $fillable = [
        'agency_id',
        'user_id',
        'full_name',
        'id_number',
        'cell',
        'email',
        'title',
        'appointed_on',
        'ended_on',
        'appointed_by',
        'appointment_notes',
    ];

    protected $casts = [
        'appointed_on' => 'date',
        'ended_on'     => 'date',
    ];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appointed_by');
    }

    // ── Scopes ──

    public function scopeCurrent($query)
    {
        return $query->whereNull('ended_on');
    }

    // ── Static ──

    public static function current(int $agencyId): ?self
    {
        return static::where('agency_id', $agencyId)
            ->whereNull('ended_on')
            ->first();
    }
}
