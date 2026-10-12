<?php

namespace App\Models\TitleDeeds;

use App\Models\Concerns\BelongsToAgency;
use Illuminate\Database\Eloquent\Model;

/**
 * .ai/specs/title-deed-register.md §9 — the agency's Title Deed Register settings. One row per agency, every column
 * nullable: a NULL resolves to the DEFAULT_* constant at read time (never written on read), the same pattern as
 * RentalInspectionSetting. Nothing here is a code constant the agency cannot change.
 */
class TitleDeedSetting extends Model
{
    use BelongsToAgency;

    protected $table = 'title_deed_settings';

    public const REP_REFUSE = 'refuse';
    public const REP_OWNER_CONSENT = 'owner_consent';
    public const REP_CONSENT_OR_LETTER = 'consent_or_letter';

    public const REP_MODES = [
        self::REP_REFUSE => 'Refuse - a representative cannot collect without the PIN',
        self::REP_OWNER_CONSENT => 'Owner consent link',
        self::REP_CONSENT_OR_LETTER => 'Owner consent link or authority letter',
    ];

    public const DEFAULT_PIN_REQUIRED = true;
    public const DEFAULT_SIGNATURE_REQUIRED = true;
    public const DEFAULT_REPRESENTATIVES_ALLOWED = true;
    public const DEFAULT_REP_WITHOUT_PIN_MODE = self::REP_OWNER_CONSENT;
    public const DEFAULT_PHONE_CONSENT_ALLOWED = false;
    public const DEFAULT_AUTHORITY_LETTER_REQUIRED = false;
    public const DEFAULT_COLLECTOR_ID_PHOTO_REQUIRED = false;
    public const DEFAULT_PIN_LOCK_ATTEMPTS = 5;
    public const DEFAULT_CONSENT_VALIDITY_MINUTES = 60;
    public const DEFAULT_REMINDER_DAYS = 14;
    public const DEFAULT_MAX_REMINDERS = 3;
    public const DEFAULT_CONTACT_CHANGED_BLOCK_DAYS = 7;

    /** Every settable column, with its type — the one list the saver, the screen and the wizard read from. */
    public const FIELDS = [
        'pin_required' => 'bool',
        'signature_required' => 'bool',
        'representatives_allowed' => 'bool',
        'rep_without_pin_mode' => 'mode',
        'phone_consent_allowed' => 'bool',
        'authority_letter_required' => 'bool',
        'collector_id_photo_required' => 'bool',
        'pin_lock_attempts' => 'int',
        'consent_validity_minutes' => 'int',
        'reminder_days' => 'int',
        'max_reminders' => 'int',
        'contact_changed_block_days' => 'int',
    ];

    /** Allowed range for each integer setting [min, max]. */
    public const INT_RANGES = [
        'pin_lock_attempts' => [1, 20],
        'consent_validity_minutes' => [5, 1440],
        'reminder_days' => [1, 365],
        'max_reminders' => [0, 20],
        'contact_changed_block_days' => [0, 365],
    ];

    protected $fillable = [
        'agency_id',
        'pin_required', 'signature_required', 'representatives_allowed', 'rep_without_pin_mode',
        'phone_consent_allowed', 'authority_letter_required', 'collector_id_photo_required',
        'pin_lock_attempts', 'consent_validity_minutes', 'reminder_days', 'max_reminders', 'contact_changed_block_days',
    ];

    protected $casts = [
        'pin_required' => 'boolean',
        'signature_required' => 'boolean',
        'representatives_allowed' => 'boolean',
        'phone_consent_allowed' => 'boolean',
        'authority_letter_required' => 'boolean',
        'collector_id_photo_required' => 'boolean',
        'pin_lock_attempts' => 'integer',
        'consent_validity_minutes' => 'integer',
        'reminder_days' => 'integer',
        'max_reminders' => 'integer',
        'contact_changed_block_days' => 'integer',
    ];

    /** The agency's row, or null (read-only: nothing is created on read). */
    public static function rowFor(?int $agencyId): ?self
    {
        if (! $agencyId) {
            return null;
        }

        return static::withoutGlobalScopes()->where('agency_id', $agencyId)->first();
    }

    private static function value(?int $agencyId, string $column, mixed $default): mixed
    {
        $row = static::rowFor($agencyId);

        return $row && $row->{$column} !== null ? $row->{$column} : $default;
    }

    public static function pinRequiredFor(?int $agencyId): bool
    {
        return (bool) static::value($agencyId, 'pin_required', self::DEFAULT_PIN_REQUIRED);
    }

    public static function signatureRequiredFor(?int $agencyId): bool
    {
        return (bool) static::value($agencyId, 'signature_required', self::DEFAULT_SIGNATURE_REQUIRED);
    }

    public static function representativesAllowedFor(?int $agencyId): bool
    {
        return (bool) static::value($agencyId, 'representatives_allowed', self::DEFAULT_REPRESENTATIVES_ALLOWED);
    }

    public static function repWithoutPinModeFor(?int $agencyId): string
    {
        $mode = (string) static::value($agencyId, 'rep_without_pin_mode', self::DEFAULT_REP_WITHOUT_PIN_MODE);

        return array_key_exists($mode, self::REP_MODES) ? $mode : self::DEFAULT_REP_WITHOUT_PIN_MODE;
    }

    public static function phoneConsentAllowedFor(?int $agencyId): bool
    {
        return (bool) static::value($agencyId, 'phone_consent_allowed', self::DEFAULT_PHONE_CONSENT_ALLOWED);
    }

    public static function authorityLetterRequiredFor(?int $agencyId): bool
    {
        return (bool) static::value($agencyId, 'authority_letter_required', self::DEFAULT_AUTHORITY_LETTER_REQUIRED);
    }

    public static function collectorIdPhotoRequiredFor(?int $agencyId): bool
    {
        return (bool) static::value($agencyId, 'collector_id_photo_required', self::DEFAULT_COLLECTOR_ID_PHOTO_REQUIRED);
    }

    public static function pinLockAttemptsFor(?int $agencyId): int
    {
        return max(1, (int) static::value($agencyId, 'pin_lock_attempts', self::DEFAULT_PIN_LOCK_ATTEMPTS));
    }

    public static function consentValidityMinutesFor(?int $agencyId): int
    {
        return max(1, (int) static::value($agencyId, 'consent_validity_minutes', self::DEFAULT_CONSENT_VALIDITY_MINUTES));
    }

    public static function reminderDaysFor(?int $agencyId): int
    {
        return max(1, (int) static::value($agencyId, 'reminder_days', self::DEFAULT_REMINDER_DAYS));
    }

    public static function maxRemindersFor(?int $agencyId): int
    {
        return max(0, (int) static::value($agencyId, 'max_reminders', self::DEFAULT_MAX_REMINDERS));
    }

    public static function contactChangedBlockDaysFor(?int $agencyId): int
    {
        return max(0, (int) static::value($agencyId, 'contact_changed_block_days', self::DEFAULT_CONTACT_CHANGED_BLOCK_DAYS));
    }
}
