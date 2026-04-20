<?php

namespace App\Models\Compliance;

use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class FicaOfficerAppointment extends Model
{
    use SoftDeletes, BelongsToAgency;

    const ROLE_PRIMARY = 'primary_compliance_officer';
    const ROLE_MLRO    = 'mlro';

    protected $table = 'fica_officer_appointments';

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
            // Auto-populate personal details from linked user if not explicitly set
            if ($appointment->user_id && empty($appointment->full_name)) {
                $user = User::find($appointment->user_id);
                if ($user) {
                    $appointment->full_name = $appointment->full_name ?: $user->name;
                    $appointment->email     = $appointment->email ?: $user->email;
                }
            }

            // If appointing a new primary CO, auto-end the current one
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

                    Log::info('Primary CO auto-ended on new appointment', [
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

    public function scopeMlro($query)
    {
        return $query->where('role', self::ROLE_MLRO);
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where(function ($q) use ($branchId) {
            $q->where('branch_id', $branchId)->orWhereNull('branch_id');
        });
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

    public static function activeMlrosFor(?int $agencyId, ?int $branchId = null): \Illuminate\Database\Eloquent\Collection
    {
        if ($agencyId === null) return new \Illuminate\Database\Eloquent\Collection();

        $query = static::where('agency_id', $agencyId)
            ->mlro()
            ->active();

        if ($branchId) {
            $query->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)->orWhereNull('branch_id');
            });
        }

        return $query->get();
    }
}
