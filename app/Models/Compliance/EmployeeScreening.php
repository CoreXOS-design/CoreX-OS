<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeScreening extends Model
{
    use SoftDeletes, BelongsToAgency;

    const TYPE_PRE_EMPLOYMENT = 'pre_employment';
    const TYPE_PERIODIC       = 'periodic';
    const TYPE_TFS_UPDATE     = 'tfs_list_update';
    const TYPE_TRIGGERED      = 'triggered';

    const RISK_HIGH   = 'high';
    const RISK_MEDIUM = 'medium';
    const RISK_LOW    = 'low';

    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED   = 'completed';
    const STATUS_FLAGGED     = 'flagged';
    const STATUS_CANCELLED   = 'cancelled';

    protected $fillable = [
        'agency_id', 'user_id', 'screening_type', 'risk_tier',
        'status', 'initiated_on', 'completed_on', 'next_due_on',
        'initiated_by', 'completed_by', 'overall_result', 'summary_notes',
    ];

    protected $casts = [
        'initiated_on' => 'date',
        'completed_on' => 'date',
        'next_due_on'  => 'date',
    ];

    // ── Relationships ──

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(EmployeeScreeningCheck::class);
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->whereIn('status', [self::STATUS_IN_PROGRESS, self::STATUS_FLAGGED]);
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', self::STATUS_COMPLETED)
            ->where('next_due_on', '<', now()->toDateString());
    }

    public function scopeDueSoon($query, int $days = 30)
    {
        return $query->where('status', self::STATUS_COMPLETED)
            ->whereBetween('next_due_on', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    // ── Methods ──

    public function complete(string $overallResult, ?string $notes, User $completedByUser): void
    {
        $nextDue = match ($this->risk_tier) {
            self::RISK_HIGH   => now()->addYear(),
            self::RISK_MEDIUM => now()->addYears(3),
            self::RISK_LOW    => now()->addYears(5),
            default           => now()->addYears(3),
        };

        $this->update([
            'status'         => self::STATUS_COMPLETED,
            'completed_on'   => now()->toDateString(),
            'next_due_on'    => $nextDue->toDateString(),
            'completed_by'   => $completedByUser->id,
            'overall_result' => $overallResult,
            'summary_notes'  => $notes,
        ]);

        // Update denormalised fields on user
        $screeningStatus = $overallResult === 'pass' ? 'clear' : 'concerns_flagged';
        $this->user->update([
            'screening_status' => $screeningStatus,
            'screening_due_on' => $nextDue->toDateString(),
        ]);
    }

    public function expectedChecks(): array
    {
        return EmployeeScreeningCheck::typesForScreening($this->screening_type);
    }

    public function completionPercent(): int
    {
        $expected = count($this->expectedChecks());
        if ($expected === 0) return 100;

        $done = $this->checks()->where('result', '!=', 'pending')->count();
        return (int) round(($done / $expected) * 100);
    }

    public static array $typeLabels = [
        'pre_employment'  => 'Pre-Employment',
        'periodic'        => 'Periodic Review',
        'tfs_list_update' => 'TFS List Update',
        'triggered'       => 'Triggered Review',
    ];
}
