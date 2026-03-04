<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class DocumentFiling extends Model
{
    protected $table = 'document_filing_register';

    protected $fillable = [
        'branch_id',
        'agent_id',
        'document_type',
        'file_reference',
        'sequence_number',
        'property_address',
        'seller_name',
        'expiry_date',
        'notes',
        'captured_by',
    ];

    protected $casts = [
        'expiry_date' => 'date',
    ];

    /* ── Relationships ── */

    public function branch()
    {
        return $this->belongsTo(Branch::class);
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
        if ($user->isEffectiveAdmin()) {
            return $query;
        }

        if ($user->isEffectiveBranchManager()) {
            return $query->where('branch_id', $user->effectiveBranchId());
        }

        return $query->where('agent_id', $user->id);
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
