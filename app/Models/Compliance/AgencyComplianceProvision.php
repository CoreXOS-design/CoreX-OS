<?php

namespace App\Models\Compliance;

use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgencyComplianceProvision extends Model
{
    use SoftDeletes, BelongsToAgency;

    // ── Provision type constants ──
    public const TYPES = [
        'pi_insurance',
        'tax_clearance',
        'ffc_certificate',
        'id_copy',
        'proof_of_address',
        'bank_confirmation',
    ];

    public const TYPE_LABELS = [
        'pi_insurance'      => 'PI Insurance',
        'tax_clearance'     => 'Tax Clearance',
        'ffc_certificate'   => 'FFC Certificate',
        'id_copy'           => 'ID Copy',
        'proof_of_address'  => 'Proof of Address',
        'bank_confirmation' => 'Bank Confirmation',
    ];

    protected $fillable = [
        'agency_id',
        'provision_type',
        'status',
        'document_path',
        'document_original_name',
        'policy_reference',
        'effective_from',
        'effective_until',
        'applies_to_roles',
        'applies_to_branches',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'applies_to_roles'    => 'array',
        'applies_to_branches' => 'array',
        'effective_from'      => 'date',
        'effective_until'     => 'date',
    ];

    // ── Relationships ──

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ── Scopes ──

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('effective_until')
                  ->orWhere('effective_until', '>=', now()->toDateString());
            });
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('agency_id', $user->agency_id)
            ->active()
            ->where(function ($q) use ($user) {
                // Role match: null/empty = all roles, otherwise user's role must be in the array
                $q->whereNull('applies_to_roles')
                  ->orWhereJsonLength('applies_to_roles', 0)
                  ->orWhereJsonContains('applies_to_roles', $user->role);
            })
            ->where(function ($q) use ($user) {
                // Branch match: null/empty = all branches, otherwise user's branch must be in the array
                $q->whereNull('applies_to_branches')
                  ->orWhereJsonLength('applies_to_branches', 0)
                  ->orWhereJsonContains('applies_to_branches', (string) $user->branch_id);
            });
    }

    // ── Static helpers ──

    /**
     * Returns the active provision covering this user for a given type, or null.
     */
    public static function coversUser(User $user, string $provisionType): ?self
    {
        return static::forUser($user)
            ->where('provision_type', $provisionType)
            ->first();
    }
}
