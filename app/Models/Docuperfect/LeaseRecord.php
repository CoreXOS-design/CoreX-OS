<?php

namespace App\Models\Docuperfect;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class LeaseRecord extends Model
{
    use SoftDeletes;

    protected $table = 'lease_records';

    protected $fillable = [
        'document_id',
        'signature_template_id',
        'property_id',
        'property_address',
        'tenant_name',
        'tenant_email',
        'landlord_name',
        'landlord_email',
        'rental_amount',
        'lease_start_date',
        'lease_end_date',
        'status',
        'previous_lease_id',
        'renewed_lease_id',
    ];

    protected $casts = [
        'rental_amount' => 'decimal:2',
        'lease_start_date' => 'date',
        'lease_end_date' => 'date',
    ];

    // Status constants
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRING_SOON = 'expiring_soon';
    const STATUS_EXPIRED = 'expired';
    const STATUS_RENEWED = 'renewed';
    const STATUS_TERMINATED = 'terminated';

    // --- Relationships ---

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function signatureTemplate()
    {
        return $this->belongsTo(SignatureTemplate::class, 'signature_template_id');
    }

    public function previousLease()
    {
        return $this->belongsTo(self::class, 'previous_lease_id');
    }

    public function renewedLease()
    {
        return $this->belongsTo(self::class, 'renewed_lease_id');
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeExpiringSoon($query)
    {
        return $query->where('status', self::STATUS_EXPIRING_SOON);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', self::STATUS_EXPIRED);
    }

    public function scopeVisibleTo($query, User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'rentals');

        // SECURITY (audit 2026-07-21): lease_records has no agency_id and no global AgencyScope, so
        // even an 'all'-scope user must be bounded to THEIR agency here or the list (and the
        // per-record guard built on it) leaks every agency's leases. Isolation is derived through
        // the document's owner agency.
        if ($scope === 'all') {
            $agencyId = $user->effectiveAgencyId();
            // A platform owner in global context (no effective agency) is legitimately cross-agency.
            if (!$agencyId) {
                return $query;
            }
            return $query->whereHas('document', function ($q) use ($agencyId) {
                $q->whereHas('owner', fn ($o) => $o->where('agency_id', $agencyId));
            });
        }

        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();
            return $query->whereHas('document', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            });
        }

        // AT-267 — an assistant's 'own' is their Assigned Agent's; everyone else: [$user->id].
        return $query->whereHas('document', function ($q) use ($user) {
            $q->whereIn('owner_id', $user->dataIdentityIds());
        });
    }

    // --- Helpers ---

    public function daysUntilExpiry(): int
    {
        if (!$this->lease_end_date) {
            return 0;
        }
        return max(0, (int) now()->diffInDays($this->lease_end_date, false));
    }

    public function isExpired(): bool
    {
        return $this->lease_end_date && $this->lease_end_date->isPast();
    }

    public function isExpiringSoon(int $withinDays = 90): bool
    {
        $days = $this->daysUntilExpiry();
        return $days <= $withinDays && $days > 0;
    }

    /**
     * Navigate the full version chain for this lease.
     */
    public function allVersions(): Collection
    {
        $versions = collect([$this]);

        // Walk back to the original
        $current = $this;
        while ($current->previousLease) {
            $versions->prepend($current->previousLease);
            $current = $current->previousLease;
        }

        // Walk forward to the latest
        $current = $this;
        while ($current->renewedLease) {
            $versions->push($current->renewedLease);
            $current = $current->renewedLease;
        }

        return $versions;
    }
}
