<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * .ai/specs/rental-portal-access.md §8/§10 — AT-445. Agency-uploaded
 * breach-notice / notice-to-vacate templates. Body is HTML with {{token}}
 * placeholders filled by RentalNoticeService::render().
 */
class RentalNoticeTemplate extends Model
{
    use BelongsToAgency, SoftDeletes;

    public const TYPE_BREACH = 'breach';
    public const TYPE_NOTICE_TO_VACATE = 'notice_to_vacate';

    public const TYPES = [self::TYPE_BREACH, self::TYPE_NOTICE_TO_VACATE];

    protected $fillable = [
        'agency_id',
        'name',
        'notice_type',
        'body_html',
        'is_active',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function notices(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RentalNotice::class);
    }
}
