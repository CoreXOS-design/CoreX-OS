<?php

namespace App\Models\CommandCenter;

use App\Models\Property;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use App\Models\Concerns\BelongsToAgency;
class PropertyHealthScore extends Model
{
    use BelongsToAgency;

    protected $fillable = [
        'agency_id',
        'property_id', 'score', 'grade', 'factors', 'last_calculated_at',
    ];

    protected $casts = [
        'factors'            => 'array',
        'last_calculated_at' => 'datetime',
    ];

    public const GRADE_EXCELLENT = 'excellent';
    public const GRADE_GOOD      = 'good';
    public const GRADE_ATTENTION = 'attention';
    public const GRADE_CRITICAL  = 'critical';

    public static function gradeFromScore(int $score): string
    {
        return match (true) {
            $score >= 90 => self::GRADE_EXCELLENT,
            $score >= 70 => self::GRADE_GOOD,
            $score >= 50 => self::GRADE_ATTENTION,
            default      => self::GRADE_CRITICAL,
        };
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function scopeCritical($query)
    {
        return $query->where('grade', self::GRADE_CRITICAL);
    }

    public function scopeNeedsAttention($query)
    {
        return $query->whereIn('grade', [self::GRADE_CRITICAL, self::GRADE_ATTENTION]);
    }
}
