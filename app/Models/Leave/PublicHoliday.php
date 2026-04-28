<?php

namespace App\Models\Leave;

use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    protected $fillable = [
        'country_code', 'holiday_date', 'name',
        'is_movable', 'applies_to_year',
    ];

    protected $casts = [
        'holiday_date'     => 'date',
        'is_movable'       => 'boolean',
        'applies_to_year'  => 'integer',
    ];

    // ── Scopes ──

    public function scopeForYear($query, int $year)
    {
        return $query->where('applies_to_year', $year);
    }

    public function scopeForCountry($query, string $code = 'ZA')
    {
        return $query->where('country_code', $code);
    }

    public function scopeBetween($query, $start, $end)
    {
        return $query->whereBetween('holiday_date', [$start, $end]);
    }
}
