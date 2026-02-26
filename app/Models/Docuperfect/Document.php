<?php

namespace App\Models\Docuperfect;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
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
    ];

    protected $casts = [
        'fields_json' => 'array',
        'archived_at' => 'datetime',
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
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->isBranchManager()) {
            $branchId = $user->effectiveBranchId();
            return $query->where('branch_id', $branchId);
        }

        return $query->where('owner_id', $user->id);
    }
}
