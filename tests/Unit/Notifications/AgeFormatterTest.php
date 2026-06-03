<?php

namespace Tests\Unit\Notifications;

use App\Support\Notifications\AgeFormatter;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Guards the notification age-humanisation that replaced raw Carbon floats
 * (e.g. "2097.989875346h ago") leaking into push / in-app notification copy.
 *
 * The mobile app renders title/body verbatim, so the backend is the only
 * place these strings can be made human.
 */
class AgeFormatterTest extends TestCase
{
    private const NO_DECIMAL = '/\d+\.\d+/';

    #[Test]
    public function a_multi_day_old_item_reads_as_natural_language_with_no_decimals(): void
    {
        // The bug-report shape: an ~87-day-old item that surfaced as "2097.989875346h ago".
        $now     = Carbon::parse('2026-06-03 12:00:00');
        $created = $now->copy()->subHours(2097)->subMinutes(59); // 87d 9h 59m — i.e. 2097.98h

        $ago = AgeFormatter::ago($created, $now);

        $this->assertSame('87 days ago', $ago);
        $this->assertDoesNotMatchRegularExpression(self::NO_DECIMAL, $ago);
        $this->assertStringNotContainsString('h ago', $ago); // never the raw-hours unit for multi-day ages
    }

    #[Test]
    public function the_composed_body_for_a_multi_day_item_is_clean(): void
    {
        $now     = Carbon::parse('2026-06-03 12:00:00');
        $created = $now->copy()->subDays(87)->subHours(2);

        // Mirrors the exact template expression used by the scan commands.
        $age  = AgeFormatter::ago($created, $now);
        $body = $age ? "Listed {$age}, no documents on file." : 'No documents on file.';

        $this->assertSame('Listed 87 days ago, no documents on file.', $body);
        $this->assertDoesNotMatchRegularExpression(self::NO_DECIMAL, $body);
    }

    #[Test]
    public function an_item_with_a_missing_timestamp_omits_the_age_clause_instead_of_placeholder_filler(): void
    {
        // Missing optional field → formatter returns null → caller drops the clause.
        $this->assertNull(AgeFormatter::ago(null));
        $this->assertNull(AgeFormatter::duration(null));
        $this->assertSame(0, AgeFormatter::wholeHours(null));

        $age  = AgeFormatter::ago(null);
        $body = $age ? "Listed {$age}, no documents on file." : 'No documents on file.';

        // No "Listed h ago", no broken interpolation, no placeholder filler.
        $this->assertSame('No documents on file.', $body);
        $this->assertStringNotContainsString('Listed', $body);
        $this->assertStringNotContainsString('null', $body);
        $this->assertStringNotContainsString('h ago', $body);
    }

    #[Test]
    public function minutes_bucket(): void
    {
        $base = Carbon::parse('2026-06-03 12:00:00');
        $this->assertSame('5m ago', AgeFormatter::ago($base->copy()->subMinutes(5), $base));
        $this->assertSame('59m ago', AgeFormatter::ago($base->copy()->subMinutes(59), $base));
        $this->assertSame('just now', AgeFormatter::ago($base->copy()->subSeconds(20), $base));
    }

    #[Test]
    public function hours_bucket(): void
    {
        $base = Carbon::parse('2026-06-03 12:00:00');
        $this->assertSame('1h ago', AgeFormatter::ago($base->copy()->subMinutes(60), $base));
        $this->assertSame('3h ago', AgeFormatter::ago($base->copy()->subHours(3), $base));
        $this->assertSame('23h ago', AgeFormatter::ago($base->copy()->subHours(23)->subMinutes(59), $base));
    }

    #[Test]
    public function days_bucket_pluralises_correctly(): void
    {
        $base = Carbon::parse('2026-06-03 12:00:00');
        $this->assertSame('1 day ago', AgeFormatter::ago($base->copy()->subHours(24), $base));
        $this->assertSame('2 days ago', AgeFormatter::ago($base->copy()->subDays(2), $base));
    }

    #[Test]
    public function duration_omits_the_ago_suffix_for_mid_sentence_use(): void
    {
        $base = Carbon::parse('2026-06-03 12:00:00');
        $this->assertSame('87 days', AgeFormatter::duration($base->copy()->subDays(87)->subHours(2), $base));
        $this->assertSame('3h', AgeFormatter::duration($base->copy()->subHours(3), $base));

        // Mirrors the deal template expression.
        $age  = AgeFormatter::duration($base->copy()->subDays(87)->subHours(2), $base);
        $body = $age ? "No update in {$age} at offer stage." : 'Awaiting progress at offer stage.';
        $this->assertSame('No update in 87 days at offer stage.', $body);
        $this->assertDoesNotMatchRegularExpression(self::NO_DECIMAL, $body);
    }

    #[Test]
    public function whole_hours_is_always_an_integer(): void
    {
        $base  = Carbon::parse('2026-06-03 12:00:00');
        $value = AgeFormatter::wholeHours($base->copy()->subHours(2090), $base);
        $this->assertIsInt($value);
        $this->assertSame(2090, $value); // exact elapsed hours, no fraction
    }
}
