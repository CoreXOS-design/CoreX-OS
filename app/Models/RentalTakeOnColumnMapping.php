<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-takeon-import.md §11 (Landing 2) — a saved, reusable
 * column mapping for one agency's own CRM export shape.
 */
class RentalTakeOnColumnMapping extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'name',
        'mapping_json',
        'created_by_user_id',
    ];

    protected $casts = [
        'mapping_json' => 'array',
    ];

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
