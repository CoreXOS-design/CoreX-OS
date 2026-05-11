<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PropertyAuditLog extends Model
{
    use BelongsToAgency;

    protected $table = 'property_audit_log';

    public $timestamps = false;

    protected $fillable = [
        'property_id', 'user_id', 'agency_id', 'branch_id',
        'event_category', 'event_type',
        'old_values', 'new_values', 'metadata',
        'human_summary', 'created_at',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'metadata'   => 'array',
        'created_at' => 'datetime',
    ];

    public function property(): BelongsTo { return $this->belongsTo(Property::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }

    public function scopeForProperty($q, int $propertyId) { return $q->where('property_id', $propertyId); }
    public function scopeForCategory($q, string $category) { return $q->where('event_category', $category); }
}
