<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MIC ↔ Deeds ↔ Contact loop — Part B (Johan 2026-08-14).
 *
 * A persistent "No contact details available" dead-end marker on ONE canonical contact, so any
 * future agent immediately sees this owner has been chased and there is nothing contactable.
 * Agency-scoped (BelongsToAgency). One active row per contact (unique contact_id).
 */
final class ContactDeadEndFlag extends Model
{
    use BelongsToAgency;

    public const REASON_OPTED_OUT      = 'opted_out';
    public const REASON_NOT_IN_TVA     = 'not_in_tva';
    public const REASON_NO_RECORD      = 'no_record_found';

    /** Allowed reasons (agent picks; default not_in_tva). */
    public const REASONS = [
        self::REASON_OPTED_OUT,
        self::REASON_NOT_IN_TVA,
        self::REASON_NO_RECORD,
    ];

    protected $fillable = [
        'agency_id',
        'contact_id',
        'property_id',
        'reason',
        'source',
        'note',
        'created_by_user_id',
    ];

    /** Human label for a reason code. */
    public static function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            self::REASON_OPTED_OUT => 'Opted out',
            self::REASON_NO_RECORD => 'No record found',
            default                => 'Not in TVA',
        };
    }

    /** Normalise an incoming reason to an allowed value (defaults to not_in_tva). */
    public static function normaliseReason(?string $reason): string
    {
        return in_array($reason, self::REASONS, true) ? $reason : self::REASON_NOT_IN_TVA;
    }

    public function getReasonLabelAttribute(): string
    {
        return self::reasonLabel($this->reason);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
