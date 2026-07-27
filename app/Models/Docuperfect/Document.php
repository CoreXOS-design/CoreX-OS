<?php

namespace App\Models\Docuperfect;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Concerns\BelongsToBranch;

class Document extends Model
{
    use BelongsToBranch, SoftDeletes;

    protected $table = 'docuperfect_documents';

    protected $fillable = [
        'name',
        'template_id',
        'fields_json',
        'owner_id',
        'branch_id',
        'pack_instance_id',
        'archived_at',
        'document_type',
        'property_address',
        'property_id',
        'lease_expiry_date',
        'web_template_data',
        'signed_paginated_html',
    ];

    protected $casts = [
        'fields_json' => 'array',
        'web_template_data' => 'array',
        'archived_at' => 'datetime',
        'lease_expiry_date' => 'date',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function template()
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function signatureTemplate()
    {
        return $this->hasOne(SignatureTemplate::class, 'document_id');
    }

    public function property()
    {
        return $this->belongsTo(\App\Models\Rental\RentalProperty::class, 'property_id');
    }

    public function contacts()
    {
        return $this->belongsToMany(Contact::class, 'document_contact', 'document_id', 'contact_id')
            ->withPivot(['party_role', 'document_type', 'is_signed', 'signed_at', 'signed_pdf_path'])
            ->withTimestamps();
    }

    public function versions()
    {
        return $this->hasMany(SignedDocumentVersion::class, 'document_id')
            ->orderBy('version_number');
    }

    public function packInstanceValues()
    {
        if (!$this->pack_instance_id) {
            return collect();
        }
        return PackInstanceValue::where('pack_instance_id', $this->pack_instance_id)->get();
    }

    public function scopeInPackInstance($query, $instanceId)
    {
        return $query->where('pack_instance_id', $instanceId);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('archived_at');
    }

    /**
     * MDF two-phase lifecycle — first-class accessor for phase 2 (OTP).
     *
     * A Mandatory Disclosure whose Phase 1 (seller + agent) is signed & sealed and
     * whose Phase 2 (purchaser) is still pending: the ceremony has a DEFERRED
     * purchaser signer, so the SignatureTemplate parks in STATUS_AWAITING_DEFERRED.
     * The seller-signed content is frozen in web_template_data.canonical_html
     * (canonical_version >= 1) and is immutable — the purchaser's later ink bakes
     * only onto buyer-identity markers (CanonicalInkComposer identity scoping).
     *
     * When an Offer to Purchase is made for a property, the OTP flow selects the
     * property's phase-1 MDF via `Document::sellerSignedDisclosure()->forProperty($id)`
     * and resumes its deferred purchaser (SignatureService::resumeDeferredSigning).
     */
    public function scopeSellerSignedDisclosure($query)
    {
        return $query
            ->whereHas('signatureTemplate', fn ($q) => $q->where('status', SignatureTemplate::STATUS_AWAITING_DEFERRED))
            ->whereHas('template.documentType', fn ($q) => $q->where('slug', 'disclosure'));
    }

    public function scopeForProperty($query, int $propertyId)
    {
        return $query->where('property_id', $propertyId);
    }

    public function scopeVisibleTo($query, User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'documents');

        if ($scope === 'all') return $query;
        if ($scope === 'branch') return $query->where('branch_id', $user->effectiveBranchId());
        if ($scope === 'own') return $query->where('owner_id', $user->id);

        return $query->whereRaw('1 = 0');
    }
}
