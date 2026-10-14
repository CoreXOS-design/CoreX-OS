<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Agency;
use Illuminate\Support\Facades\DB;

/**
 * The agency's short code ("HFC", "CTR") - the letters that start the numbers the agency's own documents carry
 * (payslips today; .ai/specs/agency-onboarding-setup.md §5.4). It is an agency setting (agencies.short_code), never a code
 * constant: a second agency must not print the first agency's letters on its paperwork.
 *
 * A new agency gets one worked out from its name; the agency can change it (Company Settings, the Setup Wizard identity
 * step, Admin > Agencies). Changing it only affects numbers issued from then on - numbers already issued never change.
 */
final class AgencyShortCode
{
    public const MIN = 2;
    public const MAX = 10;

    /** Words that say what kind of company it is, not which one: they never contribute a letter. */
    private const FILLER = ['the', 'and', 'of', 'for', 'pty', 'ltd', 'limited', 'cc', 'inc', 'ta', 'proprietary'];

    private static ?bool $columnExists = null;

    /** False only while an older migration runs on a fresh database, before the column has been added. */
    public static function columnExists(): bool
    {
        // only a "yes" is remembered: the migration that adds the column may run later in this same process
        return self::$columnExists === true
            || (self::$columnExists = \Illuminate\Support\Facades\Schema::hasColumn('agencies', 'short_code'));
    }

    /** What a person typed, made safe: letters and digits only, upper-case, at most MAX characters. Empty or too short = null (not a code). */
    public static function clean(?string $typed): ?string
    {
        $code = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $typed));
        $code = substr($code, 0, self::MAX);

        return strlen($code) >= self::MIN ? $code : null;
    }

    /**
     * A code worked out from the agency name: the first letter of each real word, up to three ("Home Finders Coastal" -> HFC,
     * "Cape Town Rentals (Pty) Ltd" -> CTR). A one-word name uses its first three letters ("Acme" -> ACM). Never empty.
     */
    public static function fromName(?string $name): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', (string) $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_values(array_filter($words, fn (string $w) => ! in_array(strtolower($w), self::FILLER, true)));

        if (count($words) >= 2) {
            $code = '';
            foreach ($words as $w) {
                $code .= strtoupper($w[0]);
                if (strlen($code) >= 3) {
                    break;
                }
            }

            return $code;
        }

        $single = strtoupper(substr($words[0] ?? '', 0, 3));

        return strlen($single) >= self::MIN ? $single : 'AG';
    }

    /** True when another agency (archived ones included - their numbers still exist) already uses this code. */
    public static function taken(string $code, ?int $exceptAgencyId = null): bool
    {
        return DB::table('agencies')
            ->where('short_code', $code)
            ->when($exceptAgencyId, fn ($q) => $q->where('id', '!=', $exceptAgencyId))
            ->exists();
    }

    /** The wanted code, or the first free variant of it ("CTR", "CTR2", "CTR3"...). */
    public static function unique(string $wanted, ?int $exceptAgencyId = null): string
    {
        $base = substr(self::clean($wanted) ?? 'AG', 0, self::MAX - 3);
        $code = $base;
        for ($n = 2; self::taken($code, $exceptAgencyId); $n++) {
            $code = $base . $n;
        }

        return $code;
    }

    /**
     * What a person typed into a "Short code" box, checked: null when they left it blank (keep the code the agency has), the cleaned
     * code when it is good, a ValidationException on `short_code` when it is too short or another agency already uses it.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public static function validated(?string $typed, ?int $exceptAgencyId = null): ?string
    {
        if (trim((string) $typed) === '') {
            return null;
        }
        $code = self::clean($typed);
        if ($code === null) {
            throw \Illuminate\Validation\ValidationException::withMessages(['short_code' => 'Short code: use ' . self::MIN . ' to ' . self::MAX . ' letters or numbers, for example CTR.']);
        }
        if (self::taken($code, $exceptAgencyId)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['short_code' => "Short code: another agency already uses {$code}. Pick different letters."]);
        }

        return $code;
    }

    /** What an agency's documents start with: its own saved code, else one worked out from its name. Never another agency's, never empty. */
    public static function for(?Agency $agency): string
    {
        return self::clean($agency?->short_code) ?? self::fromName($agency?->name);
    }

    public static function forAgencyId(?int $agencyId): string
    {
        return self::for($agencyId ? Agency::withTrashed()->find($agencyId) : null);
    }
}
