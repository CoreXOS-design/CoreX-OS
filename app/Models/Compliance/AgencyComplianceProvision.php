<?php

namespace App\Models\Compliance;

use App\Models\Branch;
use App\Models\Concerns\BelongsToAgency;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class AgencyComplianceProvision extends Model
{
    use SoftDeletes, BelongsToAgency;

    /**
     * @deprecated Use AgencyDocumentTypeConfig per-agency configurable types instead.
     */
    public const TYPES = [
        'pi_insurance', 'tax_clearance', 'ffc_certificate',
        'id_copy', 'proof_of_address', 'bank_confirmation',
    ];

    /**
     * @deprecated Use AgencyDocumentTypeConfig per-agency configurable types instead.
     */
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
        'document_type_config_id',
        'branch_id',
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

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(AgencyDocumentTypeConfig::class, 'document_type_config_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
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

    public function scopeForType($query, int $configId)
    {
        return $query->where('document_type_config_id', $configId);
    }

    public function scopeCompanyWide($query)
    {
        return $query->whereNull('branch_id');
    }

    public function scopeForBranch($query, int $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    // ── Resolvers ──

    /**
     * Resolve the effective provision for a user: branch override first, company fallback.
     */
    /**
     * 2026-09-28 (PPRA Inspection Pack Phase A) — deliberately does NOT use
     * the active() scope here: that scope's date-window filters out a row
     * once effective_until has passed, which silently made an EXPIRED
     * document invisible to every caller of this method rather than
     * findable-but-expired. That broke two things at once: the
     * expired/expiring status display in AgencyDocumentsViewerController
     * (an expired document rendered as "not available" instead of
     * "Expired"), and — more seriously — its download() anti-tamper check
     * (`$resolved->id === $provision->id`), which could never match once
     * the current document expired, so an admin could not even download
     * their own expired compliance document to renew it. "Current version"
     * and "not yet expired" are different questions; this method answers
     * the first only status='active' (i.e. not revoked/superseded) still
     * correctly excludes those. Callers that want "is this actually valid
     * right now" (amber/red/green) compute that themselves from
     * effective_until, as AgencyDocumentsViewerController::statusFor() and
     * PpraInspectionPackChecklistService already do. active() itself is
     * left unchanged — it has no other caller in the codebase (confirmed by
     * grep before this change), but renaming or repurposing it here would
     * be a wider change than this fix needs.
     */
    public static function resolveForUser(int $typeConfigId, ?int $branchId): ?self
    {
        if ($branchId) {
            $branchVersion = static::where('status', 'active')
                ->where('document_type_config_id', $typeConfigId)
                ->where('branch_id', $branchId)
                ->latest('effective_from')
                ->first();
            if ($branchVersion) {
                return $branchVersion;
            }
        }

        return static::where('status', 'active')
            ->where('document_type_config_id', $typeConfigId)
            ->whereNull('branch_id')
            ->latest('effective_from')
            ->first();
    }

    /**
     * Build the full state matrix for the agency documents index.
     */
    public static function stateMatrixForAgency(?int $agencyId): Collection
    {
        // Owner-context guard: a System Owner (super_admin, NULL agency) who has not
        // yet selected an agency reaches here with null. The route's agency.required
        // middleware already redirects them to the switcher, but never let this method
        // itself 500 on a null — return an empty matrix rather than a TypeError.
        if (! $agencyId) {
            return collect();
        }

        $types = AgencyDocumentTypeConfig::active()->ordered()->get();
        $branches = Branch::where('agency_id', $agencyId)->orderBy('name')->get();

        $provisions = static::with(['creator', 'documentType', 'branch'])
            ->where('status', 'active')
            ->get();

        return $types->map(function ($type) use ($provisions, $branches) {
            $typeProvisions = $provisions->where('document_type_config_id', $type->id);

            $company = $typeProvisions->whereNull('branch_id')->first();

            $branchRows = $branches->map(function ($branch) use ($typeProvisions) {
                return (object) [
                    'branch'    => $branch,
                    'provision' => $typeProvisions->where('branch_id', $branch->id)->first(),
                ];
            });

            return (object) [
                'type_config' => $type,
                'company'     => $company,
                'branches'    => $branchRows,
            ];
        });
    }

    // ── Accessors ──

    public function getStatusLabelAttribute(): string
    {
        if ($this->status !== 'active') {
            return ucfirst($this->status);
        }
        if ($this->effective_until) {
            $daysLeft = (int) now()->diffInDays($this->effective_until, false);
            if ($daysLeft < 0) return 'Expired';
            if ($daysLeft <= 30) return "Expiring in {$daysLeft} days";
            return 'Active (expires ' . $this->effective_until->format('d M Y') . ')';
        }
        return 'Active, no expiry';
    }

    public function getStatusColourAttribute(): string
    {
        if ($this->status !== 'active') return 'slate';
        if ($this->effective_until) {
            $daysLeft = (int) now()->diffInDays($this->effective_until, false);
            if ($daysLeft < 0) return 'red';
            if ($daysLeft <= 30) return 'amber';
        }
        return 'teal';
    }

    public function scopeForUser($query, User $user)
    {
        return $query->where('agency_id', $user->agency_id)
            ->active()
            ->where(function ($q) use ($user) {
                $q->whereNull('applies_to_roles')
                  ->orWhereJsonLength('applies_to_roles', 0)
                  ->orWhereJsonContains('applies_to_roles', $user->role);
            })
            ->where(function ($q) use ($user) {
                $q->whereNull('applies_to_branches')
                  ->orWhereJsonLength('applies_to_branches', 0)
                  ->orWhereJsonContains('applies_to_branches', (string) $user->branch_id);
            });
    }

    /**
     * @deprecated Use documentType() relationship with AgencyDocumentTypeConfig instead.
     */
    public static function coversUser(User $user, string $provisionType): ?self
    {
        return static::forUser($user)
            ->where('provision_type', $provisionType)
            ->first();
    }
}
