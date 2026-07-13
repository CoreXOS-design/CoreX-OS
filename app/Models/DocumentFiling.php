<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

use App\Models\Concerns\BelongsToAgency;
use App\Models\Concerns\BelongsToBranch;
class DocumentFiling extends Model
{
    use BelongsToBranch, BelongsToAgency, SoftDeletes;

    protected $table = 'document_filing_register';

    protected $fillable = [
        'agency_id',
        'branch_id',
        'agent_id',
        'property_id',
        'seller_contact_id',
        'document_type',
        'file_reference',
        'sequence_number',
        'property_address',
        'seller_name',
        'expiry_date',
        'notes',
        'captured_by',
        'link_source',
        'link_confidence',
        'link_reviewed_at',
        'link_reviewed_by_user_id',
    ];

    protected $casts = [
        'expiry_date'      => 'date',
        'link_reviewed_at' => 'datetime',
    ];

    /* ── Relationships ── */

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * AT-238 — the canonical property this filing is about, when it could be linked.
     * Nullable by design: ~42% of the historical register predates the property records
     * or names an address no property row has. Those rows keep their free text and are
     * not second-class citizens.
     */
    public function property()
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /** AT-238 — the seller as a real contact, sourced from the property's link roles. */
    public function sellerContact()
    {
        return $this->belongsTo(Contact::class, 'seller_contact_id');
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function capturedBy()
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /* ── Scopes ── */

    public function scopeVisibleTo($query, \App\Models\User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'filing');

        if ($scope === 'all') return $query;
        if ($scope === 'branch') return $query->where('branch_id', $user->effectiveBranchId());
        if ($scope === 'own') return $query->where('agent_id', $user->id);

        return $query->whereRaw('1 = 0');
    }

    public function scopeForBranch($query, $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeForAgent($query, $userId)
    {
        return $query->where('agent_id', $userId);
    }

    public function scopeExpiringSoon($query, $days = 30)
    {
        return $query->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [Carbon::today(), Carbon::today()->addDays($days)]);
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expiry_date')
            ->where('expiry_date', '<', Carbon::today());
    }

    public function scopeSearch($query, $term)
    {
        $like = '%' . $term . '%';
        return $query->where(function ($q) use ($like) {
            $q->where('property_address', 'like', $like)
              ->orWhere('file_reference', 'like', $like)
              ->orWhere('seller_name', 'like', $like)
              ->orWhere('sequence_number', 'like', $like);
        });
    }

    /* ── Accessors ── */

    public function getFullReferenceAttribute(): string
    {
        return $this->file_reference . ' / ' . $this->sequence_number;
    }

    /**
     * AT-238 — what to SHOW for the property. The linked record wins when there is one:
     * that is the whole point of linking (the register stops disagreeing with the
     * property page about the address). The free text is the answer when there is no
     * link — never a blank.
     */
    public function getPropertyDisplayAttribute(): string
    {
        if ($this->property) {
            return $this->property->buildDisplayAddress() ?: (string) $this->property_address;
        }

        return (string) $this->property_address;
    }

    /** Same rule for the seller: the linked contact wins, the typed name is the fallback. */
    public function getSellerDisplayAttribute(): ?string
    {
        if ($this->sellerContact) {
            $name = trim(($this->sellerContact->first_name ?? '') . ' ' . ($this->sellerContact->last_name ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return $this->seller_name ?: null;
    }

    /** Is this row pointing at the real record, or still just describing it in words? */
    public function getIsLinkedAttribute(): bool
    {
        return $this->property_id !== null;
    }

    public function getStatusAttribute(): string
    {
        if (!$this->expiry_date) {
            return 'active';
        }

        if ($this->expiry_date->lt(Carbon::today())) {
            return 'expired';
        }

        if ($this->expiry_date->lte(Carbon::today()->addDays(30))) {
            return 'expiring';
        }

        return 'active';
    }
}
