<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class ListingImportRow extends Model
{
    use BelongsToAgency, SoftDeletes;

    protected $fillable = [
        'agency_id',
        'run_id',
        'external_id',
        'external_ref',
        'property',
        'status',
        'price_cents',
        'file_agent',
        'resolved_user_id',
        'matched_listing_stock_id',
        'match_confidence',
        'decision',
        'row_payload',
    ];

    protected $casts = [
        'price_cents' => 'integer',
        'row_payload' => 'array',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(ListingImportRun::class, 'run_id');
    }

    public function resolvedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_user_id');
    }

    public function matchedStock(): BelongsTo
    {
        return $this->belongsTo(ListingStock::class, 'matched_listing_stock_id');
    }
}
