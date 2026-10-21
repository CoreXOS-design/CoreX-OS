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

    // .ai/specs/rental-notices.md §3 - the agency's own uploaded notice templates. 'breach' is the older single breach template (kept; read as "other").
    public const TYPE_BREACH_PAYMENT = 'breach_payment';
    public const TYPE_BREACH_OTHER = 'breach_other';
    // AT-489 - the agency's EXPIRY NOTICE to the tenant (CPA s14(2)(c)): the agency's own wording, uploaded here; CoreX ships none.
    public const TYPE_EXPIRY_NOTICE = 'expiry_notice';
    public const TYPES = [self::TYPE_BREACH_PAYMENT, self::TYPE_BREACH_OTHER, self::TYPE_NOTICE_TO_VACATE, self::TYPE_EXPIRY_NOTICE, self::TYPE_BREACH];
    public const TYPE_LABELS = [
        self::TYPE_BREACH_PAYMENT => 'Breach notice (payment)',
        self::TYPE_BREACH_OTHER => 'Breach notice (ad hoc)',
        self::TYPE_NOTICE_TO_VACATE => 'Notice to vacate',
        self::TYPE_EXPIRY_NOTICE => 'Lease expiry notice',
        self::TYPE_BREACH => 'Breach notice (older template)',
    ];

    public static function labelFor(?string $type): string
    {
        return self::TYPE_LABELS[$type] ?? ucfirst(str_replace('_', ' ', (string) $type));
    }

    protected $fillable = [
        'agency_id',
        'name',
        'notice_type',
        'body_html',
        'is_active',
        'created_by_user_id',
        'source_file_name',
        'source_file_path',
        'default_clause',
        'remedy_days',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'remedy_days' => 'integer',
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
