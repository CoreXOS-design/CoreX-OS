<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\TemplateTransfer;

use App\Models\DevSetting;

/**
 * Platform settings for e-sign template packages — defaults live here, never
 * inline in the services that use them. Stored as DevSetting rows (owner-only).
 * Spec: .ai/specs/esign-template-transfer.md §8.
 */
final class TemplateTransferSettings
{
    public const VISIBILITY_ALL_BRANCHES = 'all_branches';
    public const VISIBILITY_ADMINS_ONLY = 'agency_admins_only';

    /**
     * field => [DevSetting key, default, kind, min, max, label, help]
     * kind: int | visibility | pattern
     */
    public const FIELDS = [
        'max_package_mb' => ['template_transfer.max_package_mb', 20, 'int', 1, 200, 'Largest package accepted (MB)', 'An uploaded package bigger than this is refused before it is opened.'],
        'max_bundle_templates' => ['template_transfer.max_bundle_templates', 25, 'int', 1, 200, 'Most templates in one export / bundle', 'The most templates that can be exported together, or imported from one bundle.'],
        'default_visibility' => ['template_transfer.default_visibility', self::VISIBILITY_ALL_BRANCHES, 'visibility', null, null, 'Who can use an imported template', 'Every branch of the agency (default) or agency administrators only until the agency assigns branches.'],
        'version_suffix' => ['template_transfer.version_suffix', 'v{n}', 'pattern', null, 40, 'Name for a "new version"', 'Added after the template name when the agency already has one of that name. {n} becomes the next free version number.'],
        'copy_suffix' => ['template_transfer.copy_suffix', '(imported {date})', 'pattern', null, 40, 'Name for a "new copy"', 'Added after the template name for a new copy. {date} becomes today\'s date.'],
        'staged_upload_hours' => ['template_transfer.staged_upload_hours', 24, 'int', 1, 168, 'Keep an unconfirmed upload for (hours)', 'A package that was uploaded but never confirmed is deleted after this long.'],
    ];

    public static function int(string $field): int
    {
        [$key, $default, , $min, $max] = self::FIELDS[$field];

        return max($min, min($max, (int) DevSetting::get($key, $default)));
    }

    public static function visibility(): string
    {
        $v = (string) DevSetting::get(self::FIELDS['default_visibility'][0], self::VISIBILITY_ALL_BRANCHES);

        return in_array($v, [self::VISIBILITY_ALL_BRANCHES, self::VISIBILITY_ADMINS_ONLY], true)
            ? $v : self::VISIBILITY_ALL_BRANCHES;
    }

    public static function pattern(string $field): string
    {
        [$key, $default] = self::FIELDS[$field];
        $v = trim((string) DevSetting::get($key, $default));

        return $v === '' ? (string) $default : $v;
    }

    public static function maxPackageBytes(): int
    {
        return self::int('max_package_mb') * 1024 * 1024;
    }

    /** @return array<string,int|string> */
    public static function all(): array
    {
        $out = [];
        foreach (self::FIELDS as $field => [, , $kind]) {
            $out[$field] = match ($kind) {
                'int' => self::int($field),
                'visibility' => self::visibility(),
                default => self::pattern($field),
            };
        }

        return $out;
    }

    /**
     * Validate + store. @throws TemplateTransferException listing every problem
     */
    public static function save(array $input): void
    {
        $errors = [];
        $clean = [];
        foreach (self::FIELDS as $field => [$key, , $kind, $min, $max, $label]) {
            $raw = trim((string) ($input[$field] ?? ''));
            if ($kind === 'int') {
                if (! preg_match('/^\d{1,4}$/', $raw) || (int) $raw < $min || (int) $raw > $max) {
                    $errors[] = $label . ' must be a whole number from ' . $min . ' to ' . $max . '.';
                    continue;
                }
                $clean[$key] = (string) (int) $raw;
            } elseif ($kind === 'visibility') {
                if (! in_array($raw, [self::VISIBILITY_ALL_BRANCHES, self::VISIBILITY_ADMINS_ONLY], true)) {
                    $errors[] = $label . ': please choose one of the two options.';
                    continue;
                }
                $clean[$key] = $raw;
            } else {
                if ($raw === '' || mb_strlen($raw) > $max || preg_match('/[<>{}]/u', preg_replace('/\{(n|date)\}/', '', $raw))) {
                    $errors[] = $label . ' must be 1–' . $max . ' characters and may only use {n} or {date} as placeholders.';
                    continue;
                }
                $clean[$key] = $raw;
            }
        }
        if ($errors) {
            throw new TemplateTransferException('Settings were not saved.', $errors);
        }
        foreach ($clean as $key => $value) {
            DevSetting::set($key, $value);
        }
    }
}
