<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rentals-faults-work-orders.md §18 — one agent mark (circle or arrow) on a fault photo. Coordinates are normalised 0-1 so the
 * mark follows the picture at any size; the photo file itself is never touched. Soft delete only.
 */
class RentalFaultPhotoMark extends Model
{
    use BelongsToAgency;
    use SoftDeletes;

    public const SHAPE_CIRCLE = 'circle';
    public const SHAPE_ARROW = 'arrow';
    public const SHAPES = [self::SHAPE_CIRCLE, self::SHAPE_ARROW];
    public const COLOURS = ['#e11d48', '#f59e0b', '#16a34a', '#2563eb', '#ffffff', '#111827'];
    public const DEFAULT_COLOUR = '#e11d48';

    protected $fillable = [
        'agency_id', 'rental_fault_report_id', 'rental_fault_report_photo_id', 'shape', 'geometry', 'colour',
        'created_by_user_id', 'updated_by_user_id', 'deleted_by_user_id',
    ];

    protected $casts = ['geometry' => 'array'];

    public function photo(): BelongsTo
    {
        return $this->belongsTo(RentalFaultReportPhoto::class, 'rental_fault_report_photo_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return array<string,array{type:string}> the geometry keys each shape needs (all numbers 0..1; a circle's r is relative to the image width) */
    public static function geometryKeys(string $shape): array
    {
        return $shape === self::SHAPE_ARROW
            ? ['x1' => 'n', 'y1' => 'n', 'x2' => 'n', 'y2' => 'n']
            : ['cx' => 'n', 'cy' => 'n', 'r' => 'n'];
    }
}
