<?php

namespace App\Models\Prospecting;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BedroomSegment extends Model
{
    use SoftDeletes, BelongsToAgency;

    protected $fillable = [
        'name',
        'beds_min',
        'beds_max',
        'display_order',
    ];

    protected $casts = [
        'beds_min'      => 'integer',
        'beds_max'      => 'integer',
        'display_order' => 'integer',
    ];

    public function scopeOrdered($query)
    {
        return $query->orderBy('display_order')->orderBy('beds_min');
    }

    /**
     * True if this segment includes the given bed count.
     * beds_min is inclusive; beds_max is inclusive (or null = unbounded above).
     */
    public function covers(int $beds): bool
    {
        if ($beds < $this->beds_min) {
            return false;
        }
        if ($this->beds_max !== null && $beds > $this->beds_max) {
            return false;
        }
        return true;
    }
}
