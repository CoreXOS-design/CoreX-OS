<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * AT-392 — Rental Application intake (Phase 1). Spec: .ai/specs/rental-applications.md
 *
 * Deliberately NOT routed through the e-sign wizard/SignatureTemplate machinery:
 * that pipeline structurally requires an agent-signing party (see the AT-332
 * investigation), which is overkill for a tenant intake form the agent never signs.
 */
class RentalApplication extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const STATUSES = [
        'sent', 'in_progress', 'returned', 'under_assessment', 'approved', 'declined', 'withdrawn',
    ];

    public const EMPLOYMENT_TYPES = [
        'permanently_employed', 'business_owner_personal_account', 'business_owner_business_account',
    ];

    /**
     * ONE set of format rules for the V8 field list — shared by the agent-side
     * update (RentalApplicationController) and the public submit
     * (RentalApplicationSigningController), so the two never drift (BUILD_STANDARD
     * §6). Every field is `nullable` — nothing here may block a save — but a
     * MALFORMED value is rejected rather than allowed through to crash a
     * date/decimal cast at save() time (BUILD_STANDARD §2/§4).
     */
    public static function fieldValidationRules(): array
    {
        return [
            'property_address_override' => ['nullable', 'string', 'max:500'],
            'full_name' => ['nullable', 'string', 'max:255'],
            'id_number' => ['nullable', 'string', 'max:50'],
            'marital_status' => ['nullable', 'string', 'max:100'],
            'spouse_name' => ['nullable', 'string', 'max:255'],
            'spouse_id' => ['nullable', 'string', 'max:50'],
            'citizenship' => ['nullable', 'string', 'max:100'],
            'current_residential_address' => ['nullable', 'string', 'max:2000'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'cell' => ['nullable', 'string', 'max:50'],
            'work_number' => ['nullable', 'string', 'max:50'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_cell' => ['nullable', 'string', 'max:50'],
            'emergency_contact_work' => ['nullable', 'string', 'max:50'],
            'current_landlord_name' => ['nullable', 'string', 'max:255'],
            'current_landlord_tel' => ['nullable', 'string', 'max:50'],
            'current_rental_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'current_rental_from' => ['nullable', 'date'],
            'current_rental_to' => ['nullable', 'date'],
            'employer_name' => ['nullable', 'string', 'max:255'],
            'employer_position' => ['nullable', 'string', 'max:255'],
            'employer_address' => ['nullable', 'string', 'max:2000'],
            'employer_tel' => ['nullable', 'string', 'max:50'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'employment_type' => ['nullable', 'in:' . implode(',', self::EMPLOYMENT_TYPES)],
            'occupation_date' => ['nullable', 'date'],
            'rental_terms' => ['nullable', 'string', 'max:255'],
            'special_conditions' => ['nullable', 'string', 'max:2000'],
            'adults' => ['nullable', 'integer', 'min:0', 'max:50'],
            'children' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];
    }

    protected $fillable = [
        'agency_id', 'branch_id', 'contact_id', 'property_id', 'created_by_user_id',
        'status', 'delivery_mode', 'token', 'token_expires_at', 'submitted_at',
        'property_address_override',
        'full_name', 'id_number', 'marital_status', 'spouse_name', 'spouse_id', 'citizenship',
        'current_residential_address', 'email', 'cell', 'work_number',
        'emergency_contact_name', 'emergency_contact_cell', 'emergency_contact_work',
        'current_landlord_name', 'current_landlord_tel', 'current_rental_amount',
        'current_rental_from', 'current_rental_to',
        'employer_name', 'employer_position', 'employer_address', 'employer_tel',
        'monthly_salary', 'employment_type',
        'occupation_date', 'rental_terms', 'special_conditions', 'adults', 'children',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'submitted_at' => 'datetime',
        'current_rental_amount' => 'decimal:2',
        'monthly_salary' => 'decimal:2',
        'current_rental_from' => 'date',
        'current_rental_to' => 'date',
        'occupation_date' => 'date',
        'adults' => 'integer',
        'children' => 'integer',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(RentalApplicationSignature::class);
    }

    /**
     * Supporting documents filed through the SHARED documents table
     * (source_type='rental_application', source_id=$this->id) — the same
     * convention every other filed document uses. No parallel documents table.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'source_id')->where('source_type', 'rental_application');
    }

    public function declarationSignature(): ?RentalApplicationSignature
    {
        return $this->signatures->firstWhere('kind', 'declaration');
    }

    public function tpnConsentSignature(): ?RentalApplicationSignature
    {
        return $this->signatures->firstWhere('kind', 'tpn_consent');
    }

    public function isFullySigned(): bool
    {
        return $this->signatures()->whereIn('kind', ['declaration', 'tpn_consent'])->count() === 2;
    }

    /**
     * Johan, 2026-09-07 — "we always need proper crud... search / sort /
     * own / branch / agency levels. that should be the design standard...
     * from the word go." Own/branch/agency visibility, enforced at the
     * query layer (never by hiding a link). Mirrors
     * Docuperfect\Document::scopeVisibleTo() EXACTLY — same
     * PermissionService::getDataScope() resolution, same three branches —
     * so LIST and single-record access (see
     * AuthorizesRentalApplicationAccess) can never disagree. 'own' here is
     * the CREATING agent (created_by_user_id), this module's equivalent of
     * Document's owner_id.
     */
    public function scopeVisibleTo($query, User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'rental_applications');

        if ($scope === 'all') {
            return $query;
        }
        if ($scope === 'branch') {
            return $query->where('branch_id', $user->effectiveBranchId());
        }
        if ($scope === 'own') {
            return $query->whereIn('created_by_user_id', $user->dataIdentityIds());
        }

        return $query->whereRaw('1 = 0');
    }
}
