<?php

namespace App\Models\Compliance;

use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

/**
 * Phase 9c-2 — POPIA s55 Information Officer appointment.
 *
 * Mirrors FicaOfficerAppointment exactly. One active primary IO per
 * agency (enforced in the creating boot hook); multiple deputies allowed.
 */
class InformationOfficerAppointment extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    const ROLE_PRIMARY = 'primary_information_officer';
    const ROLE_DEPUTY  = 'deputy_information_officer';

    protected $table = 'information_officer_appointments';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'user_id',
        'role',
        'full_name',
        'id_number',
        'cell',
        'email',
        'title',
        'appointed_on',
        'ended_on',
        'appointed_by',
        'appointment_letter_path',
        'notes',
    ];

    protected $casts = [
        'appointed_on' => 'date',
        'ended_on'     => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $appointment) {
            // Auto-populate personal details from linked user when blank.
            if ($appointment->user_id && empty($appointment->full_name)) {
                $user = User::find($appointment->user_id);
                if ($user) {
                    $appointment->full_name = $appointment->full_name ?: $user->name;
                    $appointment->email     = $appointment->email ?: $user->email;
                }
            }

            // Appointing a new primary IO ends the agency's current primary.
            if ($appointment->role === self::ROLE_PRIMARY) {
                $existing = self::where('agency_id', $appointment->agency_id)
                    ->primary()
                    ->active()
                    ->first();

                if ($existing) {
                    $endDate = $appointment->appointed_on
                        ? $appointment->appointed_on->subDay()->toDateString()
                        : now()->subDay()->toDateString();

                    $existing->update(['ended_on' => $endDate]);

                    Log::info('Primary IO auto-ended on new appointment', [
                        'ended_id'   => $existing->id,
                        'ended_name' => $existing->full_name,
                        'new_name'   => $appointment->full_name,
                        'agency_id'  => $appointment->agency_id,
                    ]);
                }
            }
        });
    }

    // ── Relationships ──

    public function agency(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Agency::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'appointed_by');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->whereNull('ended_on');
    }

    public function scopePrimary($query)
    {
        return $query->where('role', self::ROLE_PRIMARY);
    }

    public function scopeDeputies($query)
    {
        return $query->where('role', self::ROLE_DEPUTY);
    }

    public function isPrimary(): bool
    {
        return $this->role === self::ROLE_PRIMARY;
    }

    public function isDeputy(): bool
    {
        return $this->role === self::ROLE_DEPUTY;
    }

    // ── Static helpers ──

    public static function currentPrimary(?int $agencyId): ?self
    {
        if ($agencyId === null) return null;
        return static::where('agency_id', $agencyId)
            ->primary()
            ->active()
            ->first();
    }

    public static function activeDeputiesFor(?int $agencyId): \Illuminate\Database\Eloquent\Collection
    {
        if ($agencyId === null) return new \Illuminate\Database\Eloquent\Collection();
        return static::where('agency_id', $agencyId)
            ->deputies()
            ->active()
            ->get();
    }
}
