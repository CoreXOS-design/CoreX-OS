<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Services\P24\P24EmailParserService;
use App\Services\PrivateProperty\PpEmailParserService;
use App\Services\Prospecting\MicWork\SoleMandateMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-520/AT-528 (1 Nov): an alert email is "mandated" only when its block says sole / exclusive next to mandate / agent / agency - the same rule as the advert wording.
 * An agency called "Mandated" (308 QA1 adverts) flagged every one of its adverts as sole mandate before.
 */
final class AlertMandateParserTest extends TestCase
{
    use RefreshDatabase;

    private function p24(string $inside): ?bool
    {
        $body = '<table><tr><td><a href="https://www.property24.com/for-sale/uvongo/margate/kwazulu-natal/6359/116945369?listingNumber=P24-116945369">View</a> '
            . '3 Bedroom House Uvongo R 790 000 ' . $inside . '</td></tr></table>';
        $r = (new P24EmailParserService())->parse($body, 'House for sale in Uvongo P24-116945369');

        return $r === [] ? null : (bool) $r[0]['is_mandated'];
    }

    public function test_the_p24_alert_flag_needs_sole_or_exclusive_next_to_mandate_agent_or_agency(): void
    {
        $this->assertFalse($this->p24('Listed by Mandated'), 'an agency called "Mandated" never flags');
        $this->assertFalse($this->p24('Mandated Properties - Open mandate'));
        $this->assertFalse($this->p24('Open mandate'));
        $this->assertFalse($this->p24('Mandate to sell'));
        $this->assertFalse($this->p24('no wording at all'));
        $this->assertTrue($this->p24('Sole mandate'));
        $this->assertTrue($this->p24('<b>SOLE</b> <i>Mandate</i>'), 'tags between the words do not hide the phrase');
        $this->assertTrue($this->p24('Exclusive mandate'));
        $this->assertTrue($this->p24('the sole agent'));
    }

    public function test_the_pp_alert_flag_uses_the_same_rule(): void
    {
        $pp = new PpEmailParserService();
        $row = fn (string $txt) => (new \ReflectionMethod($pp, 'row'))->invoke($pp, 'T5220359', 'https://www.privateproperty.co.za/for-sale/x/uvongo/T5220359', $txt, 'House for sale in Uvongo');
        $this->assertFalse($row('3 Bedroom House R 790 000 Mandated')['is_mandated']);
        $this->assertFalse($row('3 Bedroom House R 790 000 Open mandate')['is_mandated']);
        $this->assertTrue($row('3 Bedroom House R 790 000 Sole Mandate')['is_mandated']);
    }

    public function test_the_advertisers_own_name_is_removed_before_the_rule_runs_and_an_agencys_own_words_apply(): void
    {
        $this->assertFalse(SoleMandateMatcher::textSaysSoleMandate('Marketed by Sole Mandate Realty', 'Sole Mandate Realty'));
        $this->assertTrue(SoleMandateMatcher::textSaysSoleMandate('Marketed by Sole Mandate Realty. Sole mandate!', 'Sole Mandate Realty'));
        $this->assertFalse(SoleMandateMatcher::textSaysSoleMandate(null));
    }

    public function test_the_clear_command_is_a_dry_run_by_default_journals_and_can_put_the_flags_back(): void
    {
        $a = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $b = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $row = fn (int $agency, string $n, int $m) => DB::table('p24_listings')->insertGetId(['agency_id' => $agency, 'p24_listing_number' => $n, 'asking_price' => 1, 'first_seen_date' => now()->toDateString(),
            'last_seen_date' => now()->toDateString(), 'canonical_ref' => 'p24:' . substr($n, 4), 'is_mandated' => $m, 'created_at' => now(), 'updated_at' => now()]);
        $mine = $row($a->id, 'P24-1', 1);
        $other = $row($b->id, 'P24-2', 1);
        $dir = sys_get_temp_dir() . '/clear-mandate-' . uniqid();

        $this->artisan('mic:clear-alert-mandate', ['--agency' => $a->id, '--report-dir' => $dir])->assertSuccessful();
        $this->assertSame(1, (int) DB::table('p24_listings')->where('id', $mine)->value('is_mandated'), 'dry run');

        $this->artisan('mic:clear-alert-mandate', ['--agency' => $a->id, '--apply' => true, '--report-dir' => $dir])->assertSuccessful();
        $this->assertSame(0, (int) DB::table('p24_listings')->where('id', $mine)->value('is_mandated'));
        $this->assertSame(1, (int) DB::table('p24_listings')->where('id', $other)->value('is_mandated'), "another agency's flags are untouched");

        $journal = glob($dir . '/clear-alert-mandate-agency' . $a->id . '-journal-*.jsonl')[0];
        $this->artisan('mic:clear-alert-mandate', ['--restore' => $journal])->assertSuccessful();
        $this->assertSame(1, (int) DB::table('p24_listings')->where('id', $mine)->value('is_mandated'));
    }
}
