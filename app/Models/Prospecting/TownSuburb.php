<?php

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TownSuburb extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'town_id',
        'suburb_name',
        'suburb_normalised',
    ];

    public function town(): BelongsTo
    {
        return $this->belongsTo(Town::class);
    }

    /**
     * Canonical form for suburb lookups. Used at write-time when persisting
     * the mapping and at read-time when resolving a wishlist's suburb string
     * against the agency's mapping table.
     */
    public static function normaliseSuburb(string $suburb): string
    {
        return strtolower(trim($suburb));
    }
}
