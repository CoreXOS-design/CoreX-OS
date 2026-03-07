<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    protected $fillable = [
        'contact_type_id', 'created_by_user_id',
        'first_name', 'last_name', 'phone', 'email', 'notes',
        'birthday', 'id_number', 'address',
        'bank_name', 'bank_account_name', 'bank_account_number',
        'bank_branch_name', 'bank_branch_code', 'bank_account_type',
    ];

    protected $casts = [
        'birthday' => 'date',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(ContactType::class, 'contact_type_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function contactNotes(): HasMany
    {
        return $this->hasMany(ContactNote::class)->latest();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ContactDocument::class)->latest();
    }

    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'contact_property')
                    ->withPivot('role')
                    ->withTimestamps();
    }

    public function getFullNameAttribute(): string
    {
        return $this->first_name . ' ' . $this->last_name;
    }

    public function getInitialsAttribute(): string
    {
        return strtoupper(substr($this->first_name, 0, 1) . substr($this->last_name, 0, 1));
    }
}
