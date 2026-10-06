<?php

namespace App\Services\PlatformEsign\Agreement;

use App\Models\DevSetting;

/**
 * Platform settings for Subscription Agreement link expiry and reminders (spec §11.13). Stored as DevSetting values
 * (owner-only), edited on the Agreement wording page; defaults here, never hardcoded in the services that use them.
 */
class AgreementSettings
{
    /** key => [DevSetting key, default, min, max, label, help] */
    public const FIELDS = [
        'expiry_days' => [AgreementService::EXPIRY_KEY, 30, 1, 180, 'Link valid for (days)', 'After this many days the agency’s signing link stops working. You can re-issue it in one click; everything they entered is kept.'],
        'reminder_days' => [AgreementService::REMINDER_KEY, 3, 1, 60, 'First reminder after (days without progress)', 'The agency is reminded once this many days pass with no progress (saving, initialling or signing). Just opening the link does not count as progress.'],
        'reminder_repeat_days' => ['platform_esign.agreement_reminder_repeat_days', 3, 1, 60, 'Then remind every (days)', 'Gap between further reminders if there is still no progress.'],
        'reminder_max' => ['platform_esign.agreement_reminder_max', 3, 0, 10, 'At most this many reminders (0 = none)', 'Reminders to the agency stop after this many, and the same limit applies to countersign reminders to you.'],
        'countersign_reminder_days' => ['platform_esign.agreement_countersign_reminder_days', 1, 1, 30, 'Remind me to countersign after (days)', 'When an agency has signed and is waiting for your countersignature, you are emailed after this many days, then again at the same interval.'],
    ];

    public static function get(string $field): int
    {
        [$key, $default, $min, $max] = self::FIELDS[$field];

        return max($min, min($max, (int) DevSetting::get($key, $default)));
    }

    /** @return array<string,int> */
    public static function all(): array
    {
        return array_combine(array_keys(self::FIELDS), array_map(fn ($f) => self::get($f), array_keys(self::FIELDS)));
    }

    public static function defaults(): array
    {
        return array_map(fn ($f) => $f[1], self::FIELDS);
    }

    /**
     * Validate + store. @return array<string,array{from:int,to:int}> what changed
     * @throws WordingInvalid
     */
    public static function save(array $input): array
    {
        $errors = [];
        $clean = [];
        foreach (self::FIELDS as $field => [$key, $default, $min, $max, $label]) {
            $raw = trim((string) ($input[$field] ?? ''));
            if (!preg_match('/^\d{1,3}$/', $raw) || (int) $raw < $min || (int) $raw > $max) {
                $errors[] = $label . ' must be a whole number from ' . $min . ' to ' . $max . '.';
                continue;
            }
            $clean[$field] = (int) $raw;
        }
        if ($errors) {
            throw new WordingInvalid($errors);
        }
        $changed = [];
        foreach ($clean as $field => $to) {
            $from = self::get($field);
            if ($from !== $to) {
                $changed[$field] = ['from' => $from, 'to' => $to];
            }
            DevSetting::set(self::FIELDS[$field][0], (string) $to);
        }

        return $changed;
    }
}
