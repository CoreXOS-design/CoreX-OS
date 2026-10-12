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
        self::REP_REFUSE => 'Refuse - they cannot collect',
        self::REP_OWNER_CONSENT => 'Ask the owner to confirm by link',
        self::REP_CONSENT_OR_LETTER => 'Owner link or a signed authority letter',
    ];

    /** The three-way value of a proof: Required = cannot hand over without it; Optional = offered, may be skipped (the record then
     *  says "not captured"); Not used = not shown at all. */
    public const MODE_REQUIRED = 'required';
    public const MODE_OPTIONAL = 'optional';
    public const MODE_NOT_USED = 'not_used';

    public const PROOF_MODES = [
        self::MODE_REQUIRED => 'Required',
        self::MODE_OPTIONAL => 'Optional',
        self::MODE_NOT_USED => 'Not used',
    ];

    // The approved defaults (Johan): PIN required, signature required, representatives allowed, consent by phone off, authority letter
    // not required (offered where it applies), photo of ID off, reminders every 14 days up to 3.
    public const DEFAULT_PIN_MODE = self::MODE_REQUIRED;
    public const DEFAULT_SIGNATURE_MODE = self::MODE_REQUIRED;
    public const DEFAULT_REPRESENTATIVES_ALLOWED = true;
    public const DEFAULT_REP_WITHOUT_PIN_MODE = self::REP_OWNER_CONSENT;
    public const DEFAULT_PHONE_CONSENT_ALLOWED = false;
    public const DEFAULT_AUTHORITY_LETTER_MODE = self::MODE_OPTIONAL;
    public const DEFAULT_COLLECTOR_ID_PHOTO_MODE = self::MODE_NOT_USED;
    public const DEFAULT_SIGN_ON_SCREEN_ENABLED = true;
    public const DEFAULT_PIN_LOCK_ATTEMPTS = 5;
    public const DEFAULT_CONSENT_VALIDITY_MINUTES = 60;
    public const DEFAULT_REMINDER_DAYS = 14;
    public const DEFAULT_MAX_REMINDERS = 3;
    public const DEFAULT_CONTACT_CHANGED_BLOCK_DAYS = 7;
    public const DEFAULT_NOTIFY_LISTING_AGENT = true;
    public const DEFAULT_SIGN_BY_LINK_ENABLED = true;
    public const DEFAULT_SIGNING_LINK_MINUTES = 30;

    /** Every settable column, with its type — the one list the saver, the screen and the wizard read from. */
    public const FIELDS = [
        'pin_mode' => 'proof',
        'signature_mode' => 'proof',
        'collector_id_photo_mode' => 'proof',
        'representatives_allowed' => 'bool',
        'rep_without_pin_mode' => 'mode',
        'phone_consent_allowed' => 'bool',
        'authority_letter_mode' => 'proof',
        'sign_on_screen_enabled' => 'bool',
        'pin_lock_attempts' => 'int',
        'consent_validity_minutes' => 'int',
        'reminder_days' => 'int',
        'max_reminders' => 'int',
        'contact_changed_block_days' => 'int',
        'notify_listing_agent' => 'bool',
        'sign_by_link_enabled' => 'bool',
        'signing_link_minutes' => 'int',
    ];

    /** The setting as the screen words it (also what the history shows). */
    public const LABELS = [
        'pin_mode' => 'Collection PIN from the owner',
        'signature_mode' => 'Signature of the person collecting',
        'collector_id_photo_mode' => "Photo of the collector's ID",
        'representatives_allowed' => 'May a representative collect?',
        'rep_without_pin_mode' => 'A representative who arrives without the PIN',
        'phone_consent_allowed' => "Accept the owner's consent by phone call",
        'authority_letter_mode' => 'Authority letter',
        'sign_on_screen_enabled' => 'Sign on our screen',
        'sign_by_link_enabled' => 'Sign on their own phone from a link or QR',
        'notify_listing_agent' => "Tell the deal's listing agent when a deed is collected",
        'pin_lock_attempts' => 'Wrong PIN attempts before the PIN locks',
        'consent_validity_minutes' => "How long an owner's confirmation lasts (minutes)",
        'reminder_days' => 'Remind the owners every (days)',
        'max_reminders' => 'Most reminders for one deed',
        'signing_link_minutes' => 'How long a signing link lasts (minutes)',
        'contact_changed_block_days' => "Extra check after an owner's email or phone changed (days)",
    ];

    /** A stored value as a person reads it ("Required", "Yes", "Owner consent link", "30"). */
    public static function displayValue(string $key, mixed $value): string
    {
        $type = self::FIELDS[$key] ?? 'int';

        return match (true) {
            $value === null => 'not set',
            $type === 'bool' => ($value === true || $value === 1 || $value === '1' || $value === 'yes') ? 'Yes' : 'No',
            $type === 'proof' => self::PROOF_MODES[$value] ?? (string) $value,
            $type === 'mode' => self::REP_MODES[$value] ?? (string) $value,
            default => (string) $value,
        };
    }

    /** Allowed range for each integer setting [min, max]. */
    public const INT_RANGES = [
        'pin_lock_attempts' => [1, 20],
        'consent_validity_minutes' => [5, 1440],
        'reminder_days' => [1, 365],
        'max_reminders' => [0, 20],
        'contact_changed_block_days' => [0, 365],
        'signing_link_minutes' => [5, 1440],
    ];

    protected $fillable = [
        'agency_id',
        'pin_mode', 'signature_mode', 'collector_id_photo_mode', 'authority_letter_mode', 'sign_on_screen_enabled',
        'representatives_allowed', 'rep_without_pin_mode', 'phone_consent_allowed',
        'pin_lock_attempts', 'consent_validity_minutes', 'reminder_days', 'max_reminders', 'contact_changed_block_days',
        'notify_listing_agent', 'sign_by_link_enabled', 'signing_link_minutes',
    ];

    protected $casts = [
        'representatives_allowed' => 'boolean',
        'phone_consent_allowed' => 'boolean',
        'sign_on_screen_enabled' => 'boolean',
        'notify_listing_agent' => 'boolean',
        'sign_by_link_enabled' => 'boolean',
        'signing_link_minutes' => 'integer',
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

    private static function proof(?int $agencyId, string $column, string $default): string
    {
        $mode = (string) static::value($agencyId, $column, $default);

        return array_key_exists($mode, self::PROOF_MODES) ? $mode : $default;
    }

    /** The saved choice for each proof, exactly as chosen (the screens and the wizard read these). */
    public static function pinModeFor(?int $agencyId): string
    {
        return static::proof($agencyId, 'pin_mode', self::DEFAULT_PIN_MODE);
    }

    public static function signatureModeFor(?int $agencyId): string
    {
        return static::proof($agencyId, 'signature_mode', self::DEFAULT_SIGNATURE_MODE);
    }

    public static function collectorIdPhotoModeFor(?int $agencyId): string
    {
        return static::proof($agencyId, 'collector_id_photo_mode', self::DEFAULT_COLLECTOR_ID_PHOTO_MODE);
    }

    public static function authorityLetterModeFor(?int $agencyId): string
    {
        return static::proof($agencyId, 'authority_letter_mode', self::DEFAULT_AUTHORITY_LETTER_MODE);
    }

    public static function signOnScreenEnabledFor(?int $agencyId): bool
    {
        return (bool) static::value($agencyId, 'sign_on_screen_enabled', self::DEFAULT_SIGN_ON_SCREEN_ENABLED);
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

    public static function notifyListingAgentFor(?int $agencyId): bool
    {
        return (bool) static::value($agencyId, 'notify_listing_agent', self::DEFAULT_NOTIFY_LISTING_AGENT);
    }

    public static function signByLinkEnabledFor(?int $agencyId): bool
    {
        return (bool) static::value($agencyId, 'sign_by_link_enabled', self::DEFAULT_SIGN_BY_LINK_ENABLED);
    }

    public static function signingLinkMinutesFor(?int $agencyId): int
    {
        return max(1, (int) static::value($agencyId, 'signing_link_minutes', self::DEFAULT_SIGNING_LINK_MINUTES));
    }
}
