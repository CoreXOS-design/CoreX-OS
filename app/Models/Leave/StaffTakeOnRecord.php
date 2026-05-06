<?php

namespace App\Models\Leave;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Payroll\PayrollEmployee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class StaffTakeOnRecord extends Model
{
    use SoftDeletes, BelongsToAgency, BelongsToBranch;

    protected $fillable = [
        'agency_id', 'branch_id', 'user_id', 'payroll_employee_id',
        'take_on_date', 'previous_employer', 'previous_employment_start_date',
        'original_employment_start_date', 'take_on_type',
        'personal_details_verified', 'banking_details_verified',
        'tax_details_verified', 'employment_terms_verified',
        'compensation_setup_verified', 'leave_balances_captured',
        'compliance_documents_uploaded', 'signed_employment_contract_uploaded',
        'completed_at', 'completed_by_user_id', 'notes', 'current_step',
    ];

    protected $casts = [
        'take_on_date'                        => 'date',
        'previous_employment_start_date'      => 'date',
        'original_employment_start_date'      => 'date',
        'personal_details_verified'           => 'boolean',
        'banking_details_verified'            => 'boolean',
        'tax_details_verified'                => 'boolean',
        'employment_terms_verified'           => 'boolean',
        'compensation_setup_verified'         => 'boolean',
        'leave_balances_captured'             => 'boolean',
        'compliance_documents_uploaded'       => 'boolean',
        'signed_employment_contract_uploaded' => 'boolean',
        'completed_at'                        => 'datetime',
    ];

    // ── Relationships ──

    public function agency(): BelongsTo { return $this->belongsTo(Agency::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function payrollEmployee(): BelongsTo { return $this->belongsTo(PayrollEmployee::class); }
    public function completedBy(): BelongsTo { return $this->belongsTo(User::class, 'completed_by_user_id'); }

    // ── Methods ──

    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    public function progressPercentage(): int
    {
        $flags = [
            $this->personal_details_verified,
            $this->banking_details_verified,
            $this->tax_details_verified,
            $this->employment_terms_verified,
            $this->compensation_setup_verified,
            $this->leave_balances_captured,
            $this->compliance_documents_uploaded,
            $this->signed_employment_contract_uploaded,
        ];

        $done = count(array_filter($flags));
        return (int) round(($done / 8) * 100);
    }

    public function nextStep(): ?string
    {
        $steps = [
            'user'         => true, // always done (user picked)
            'personal'     => $this->personal_details_verified,
            'tax_banking'  => $this->banking_details_verified && $this->tax_details_verified,
            'employment'   => $this->employment_terms_verified,
            'compensation' => $this->compensation_setup_verified,
            'leave'        => $this->leave_balances_captured,
            'compliance'   => $this->compliance_documents_uploaded && $this->signed_employment_contract_uploaded,
            'review'       => $this->isComplete(),
        ];

        foreach ($steps as $step => $done) {
            if (!$done) {
                return $step;
            }
        }

        return null;
    }

    public function markStepComplete(string $step, User $user): void
    {
        $flagMap = [
            'personal'     => ['personal_details_verified'],
            'tax_banking'  => ['banking_details_verified', 'tax_details_verified'],
            'employment'   => ['employment_terms_verified'],
            'compensation' => ['compensation_setup_verified'],
            'leave'        => ['leave_balances_captured'],
            'compliance'   => ['compliance_documents_uploaded', 'signed_employment_contract_uploaded'],
        ];

        if (isset($flagMap[$step])) {
            $updates = ['current_step' => $step];
            foreach ($flagMap[$step] as $flag) {
                $updates[$flag] = true;
            }
            $this->update($updates);
        }

        // Check if all complete
        if ($this->progressPercentage() === 100 && !$this->completed_at) {
            $this->update([
                'completed_at'        => now(),
                'completed_by_user_id' => $user->id,
                'current_step'        => 'review',
            ]);
        }
    }
}
