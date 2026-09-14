<?php

declare(strict_types=1);

namespace App\Models\Compliance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

/**
 * Per-module Reporting Officer (RO) / Compliance Officer (CO) appointment.
 *
 * Spec: .ai/specs/esign-compliance-approval-gate.md §5.1. One CO + any number of ROs per module
 * (ruling 3). Mirrors FicaOfficerAppointment's shape — dated appointments, ended by date, never
 * deleted — without touching FICA's own table.
 *
 * Deliberately NOT BelongsToBranch: the branch column is an informational stamp of where the
 * person sat when appointed. Who an officer may act on is the standing own / branch / all data
 * scope rule (ruling 5), resolved per queue, not a global scope on this row.
 */
class OfficerAppointment extends Model
{
    use SoftDeletes, BelongsToAgency;

    public const MODULE_ESIGN       = 'esign';
    public const MODULE_WHISTLEBLOW = 'whistleblow';
    public const MODULES            = [self::MODULE_ESIGN, self::MODULE_WHISTLEBLOW];

    public const ROLE_RO = 'ro';
    public const ROLE_CO = 'co';

    protected $table = 'officer_appointments';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'user_id',
        'module',
        'role',
        'full_name',
        'email',
        'appointed_on',
        'ended_on',
        'appointed_by',
        'notes',
    ];

    protected $casts = [
        'appointed_on' => 'date',
        'ended_on'     => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $appointment) {
            if ($appointment->user_id && empty($appointment->full_name)) {
                $user = User::withoutGlobalScopes()->find($appointment->user_id);
                if ($user) {
                    $appointment->full_name = $appointment->full_name ?: $user->name;
                    $appointment->email     = $appointment->email ?: $user->email;
                }
            }

            // One CO per module: appointing a new CO ends the incumbent the day before.
            if ($appointment->role === self::ROLE_CO) {
                $existing = self::withoutGlobalScopes()
                    ->where('agency_id', $appointment->agency_id)
                    ->where('module', $appointment->module)
                    ->co()
                    ->active()
                    ->first();

                if ($existing && (int) $existing->user_id !== (int) $appointment->user_id) {
                    $endDate = $appointment->appointed_on
                        ? $appointment->appointed_on->copy()->subDay()->toDateString()
                        : now()->subDay()->toDateString();

                    $existing->update(['ended_on' => $endDate]);

                    Log::info('Module CO auto-ended on new appointment', [
                        'module'     => $appointment->module,
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
        return $this->belongsTo(Agency::class);
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

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_on');
    }

    public function scopeForModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    public function scopeCo(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_CO);
    }

    public function scopeRo(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_RO);
    }

    // ── Static helpers (agency-explicit, safe with no agency context) ──

    public static function currentCo(?int $agencyId, string $module): ?self
    {
        if (! $agencyId) {
            return null;
        }

        return static::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->forModule($module)
            ->co()
            ->active()
            ->first();
    }

    public static function activeRosFor(?int $agencyId, string $module): Collection
    {
        if (! $agencyId) {
            return new Collection();
        }

        return static::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->forModule($module)
            ->ro()
            ->active()
            ->get();
    }
}
