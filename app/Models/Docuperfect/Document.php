<?php

namespace App\Models\Docuperfect;

use App\Models\Branch;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

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

    public function scopeVisibleTo($query, User $user)
    {
        $scope = \App\Services\PermissionService::getDataScope($user, 'documents');

        if ($scope === 'all') return $query;
        if ($scope === 'branch') return $query->where('branch_id', $user->effectiveBranchId());
        if ($scope === 'own') return $query->where('owner_id', $user->id);

        return $query->whereRaw('1 = 0');
    }
}
