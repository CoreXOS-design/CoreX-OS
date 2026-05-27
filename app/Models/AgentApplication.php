<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Models\Concerns\BelongsToAgency;
class AgentApplication extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        // Applicant identity (public intake fields)
        'first_name',
        'last_name',
        'email',
        'phone',
        'id_number',
        // Professional background
        'current_agency',
        'years_experience',
        'ffc_number',
        'ffc_expiry',
        'ppra_status',
        'designation',
        'motivation',
        'referral_source',
        'referred_by_user_id',
        // Tenancy + lifecycle (set by owner controllers, not public payloads)
        'agency_id',
        'status',
        'status_changed_at',
        'status_notes',
    ];
    // INTENTIONALLY EXCLUDED from $fillable (must be set explicitly, never mass-assigned):
    //   reviewed_by, activated_at, activated_by, user_id (privilege/identity binding fields).

    protected $casts = [
        'ffc_expiry' => 'date',
        'status_changed_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public const STATUSES = [
        'applied',
        'documents_pending',
        'compliance_review',
        'mentor_assignment',
        'training',
        'activated',
        'rejected',
        'withdrawn',
    ];

    public const PIPELINE_STATUSES = [
        'applied',
        'documents_pending',
        'compliance_review',
        'mentor_assignment',
        'training',
        'activated',
    ];

    public const STATUS_LABELS = [
        'applied' => 'Applied',
        'documents_pending' => 'Documents',
        'compliance_review' => 'Compliance',
        'mentor_assignment' => 'Mentor',
        'training' => 'Training',
        'activated' => 'Active',
        'rejected' => 'Rejected',
        'withdrawn' => 'Withdrawn',
    ];

    public const DESIGNATION_LABELS = [
        'property_practitioner' => 'Property Practitioner',
        'candidate_practitioner' => 'Candidate Practitioner',
        'intern' => 'Intern',
    ];

    public const CHECKLIST_ITEMS = [
        ['key' => 'identity_verified', 'label' => 'Identity document verified', 'required' => true, 'sort' => 1],
        ['key' => 'ffc_valid', 'label' => 'Valid FFC certificate confirmed', 'required' => true, 'sort' => 2],
        ['key' => 'ppra_registered', 'label' => 'PPRA registration verified', 'required' => true, 'sort' => 3],
        ['key' => 'pi_insurance', 'label' => 'Professional indemnity insurance confirmed', 'required' => true, 'sort' => 4],
        ['key' => 'tax_clearance', 'label' => 'Tax clearance or SARS registration verified', 'required' => true, 'sort' => 5],
        ['key' => 'proof_of_address', 'label' => 'Proof of address verified', 'required' => true, 'sort' => 6],
        ['key' => 'qualifications_verified', 'label' => 'Qualifications verified', 'required' => true, 'sort' => 7],
        ['key' => 'employment_contract', 'label' => 'Employment contract signed', 'required' => true, 'sort' => 8],
        ['key' => 'bank_details', 'label' => 'Bank details captured', 'required' => true, 'sort' => 9],
        ['key' => 'mentor_assigned', 'label' => 'Mentor assigned', 'required' => true, 'sort' => 10],
        ['key' => 'fica_training', 'label' => 'FICA compliance training completed', 'required' => true, 'sort' => 11],
        ['key' => 'system_training', 'label' => 'CoreX OS system training completed', 'required' => true, 'sort' => 12],
        ['key' => 'rmcp_acknowledged', 'label' => 'RMCP read and acknowledged', 'required' => true, 'sort' => 13],
        ['key' => 'user_account_created', 'label' => 'CoreX user account created', 'required' => true, 'sort' => 14],
        ['key' => 'portal_access', 'label' => 'Portal access configured', 'required' => true, 'sort' => 15],
    ];

    // Status advance requirements: what checklist items must be done to enter each status
    public const STATUS_REQUIREMENTS = [
        'documents_pending' => ['identity_verified'],
        'compliance_review' => ['ffc_valid', 'ppra_registered', 'pi_insurance'],
        'mentor_assignment' => ['identity_verified', 'ffc_valid', 'ppra_registered', 'pi_insurance', 'tax_clearance', 'proof_of_address', 'qualifications_verified'],
        'training' => ['mentor_assigned'],
        'activated' => null, // all required items
    ];

    // ── Relationships ──

    public function agency()
    {
        return $this->belongsTo(Agency::class);
    }

    public function documents()
    {
        return $this->hasMany(ApplicationDocument::class, 'application_id');
    }

    public function checklist()
    {
        return $this->hasMany(OnboardingChecklist::class, 'application_id')->orderBy('sort_order');
    }

    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function reviewedByUser()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function activatedByUser()
    {
        return $this->belongsTo(User::class, 'activated_by');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ── Scopes ──

    public function scopePending($query)
    {
        return $query->whereNotIn('status', ['activated', 'rejected', 'withdrawn']);
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeRecent($query)
    {
        return $query->orderByDesc('created_at');
    }

    // ── Accessors ──

    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function getDesignationLabelAttribute(): string
    {
        return self::DESIGNATION_LABELS[$this->designation] ?? ucfirst(str_replace('_', ' ', $this->designation));
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst(str_replace('_', ' ', $this->status));
    }

    // ── Methods ──

    public function seedChecklist(): void
    {
        foreach (self::CHECKLIST_ITEMS as $item) {
            OnboardingChecklist::firstOrCreate(
                ['application_id' => $this->id, 'item_key' => $item['key']],
                [
                    'item_label' => $item['label'],
                    'is_required' => $item['required'],
                    'sort_order' => $item['sort'],
                ]
            );
        }
    }

    public function currentStep(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function completionPercent(): int
    {
        $items = $this->checklist;
        if ($items->isEmpty()) {
            return 0;
        }
        $required = $items->where('is_required', true);
        if ($required->isEmpty()) {
            return 100;
        }
        $completed = $required->where('is_completed', true)->count();

        return (int) round(($completed / $required->count()) * 100);
    }

    public function canAdvanceTo(string $targetStatus): bool
    {
        $requirements = self::STATUS_REQUIREMENTS[$targetStatus] ?? null;
        if ($requirements === null) {
            // 'activated' requires ALL required items
            $incomplete = $this->checklist()->where('is_required', true)->where('is_completed', false)->count();
            return $incomplete === 0;
        }

        $completedKeys = $this->checklist()->where('is_completed', true)->pluck('item_key')->toArray();

        foreach ($requirements as $key) {
            if (!in_array($key, $completedKeys)) {
                return false;
            }
        }

        return true;
    }

    public function nextStatus(): ?string
    {
        $flow = self::PIPELINE_STATUSES;
        $currentIndex = array_search($this->status, $flow);
        if ($currentIndex === false || $currentIndex >= count($flow) - 1) {
            return null;
        }

        return $flow[$currentIndex + 1];
    }

    public function daysInCurrentStage(): int
    {
        $since = $this->status_changed_at ?? $this->created_at;

        return $since ? (int) now()->diffInDays($since) : 0;
    }
}
