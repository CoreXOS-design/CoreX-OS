<?php

namespace Tests\Unit\Support;

use App\Support\WhatsAppNumberFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Contact-details Phase 1 — proves the fix for "agent could not load [reach]
 * a USA number on WhatsApp": every deep-link builder used to strip digits and
 * blindly replace a leading '0' with South Africa's '27', with no way to know
 * a number was ever anything else. These cases show the OLD behaviour would
 * have wrecked a US number, and the new one doesn't.
 */
class WhatsAppNumberFormatterTest extends TestCase
{
    /** @dataProvider numbers */
    public function test_forDeepLink(?string $rawPhone, ?string $dialCode, string $expected): void
    {
        $this->assertSame($expected, WhatsAppNumberFormatter::forDeepLink($rawPhone, $dialCode));
    }

    public static function numbers(): array
    {
        return [
            // ZA — unchanged from the old "leading 0 -> 27" behaviour.
            'ZA local, leading 0'        => ['082 123 4567', '+27', '27821234567'],
            'ZA already international'  => ['+27 82 123 4567', '+27', '27821234567'],
            'ZA bare, no leading 0'      => ['821234567', '+27', '27821234567'],

            // USA — THE BUG. A number typed with a local-style leading 0 (the
            // exact shape an agent used to ZA numbers would type) must get the
            // US dial code, never South Africa's.
            'USA typed with leading 0 (the bug)' => ['0415 555 2671', '+1', '14155552671'],
            'USA already international'          => ['+1 415 555 2671', '+1', '14155552671'],
            'USA bare, no leading 0'              => ['415 555 2671', '+1', '14155552671'],

            // UK — a different non-ZA shape, same guarantee.
            'UK already international' => ['+44 7911 123456', '+44', '447911123456'],
            'UK typed with leading 0'   => ['07911 123456', '+44', '447911123456'],

            // Defaults / edge cases.
            'missing dial code defaults to ZA' => ['0821234567', null, '27821234567'],
            'blank number'                     => ['', '+1', ''],
            'null number'                      => [null, '+27', ''],
        ];
    }

    public function test_old_behaviour_would_have_wrecked_a_usa_number(): void
    {
        // Documents the exact defect being fixed: the old JS/PHP heuristic
        // hardcoded '27' regardless of the number's real country.
        $usDigits = '04155552671'; // agent typed a US number with a leading 0
        $oldBuggyResult = '27' . substr(preg_replace('/\D/', '', $usDigits), 1);
        $this->assertSame('274155552671', $oldBuggyResult, 'the old code would have produced this (wrong) link');

        $fixed = WhatsAppNumberFormatter::forDeepLink($usDigits, '+1');
        $this->assertNotSame($oldBuggyResult, $fixed);
        $this->assertSame('14155552671', $fixed);
    }
}
