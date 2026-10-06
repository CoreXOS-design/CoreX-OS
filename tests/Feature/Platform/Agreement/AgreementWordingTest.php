<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\PlatformEsign\WordingVersion;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementFields;
use App\Services\PlatformEsign\Agreement\AgreementPricing;
use App\Services\PlatformEsign\Agreement\AgreementRenderer;
use Tests\TestCase;

/**
 * Subscription Agreement web document — spec §11.3/§11.5. No database: the seeded wording is checked against the two
 * signed-off source files word for word, and the pricing rules are checked as pure functions.
 */
class AgreementWordingTest extends TestCase
{
    private const PARTS = ['intro', 'part_a', 'part_b', 'part_c', 'part_d', 'mandate'];

    private function plain(array $blocks): string
    {
        $t = html_entity_decode(strip_tags(implode("\n", $blocks)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Blanks are ignored: underscores, dotted lines (a run of … with any trailing dots) and the ☐ tick boxes.
        return preg_replace('/…+\.*|\.{2,}|[\s_☐]+/u', '', $t);
    }

    public function test_seeded_wording_is_the_source_text_word_for_word(): void
    {
        $content = (new AgreementContent)->buildV1();
        $v = new WordingVersion(['content_json' => $content, 'rates_json' => AgreementPricing::DEFAULT_RATES]);
        $r = app(AgreementRenderer::class);

        $rendered = '';
        foreach (self::PARTS as $part) {
            $rendered .= $this->plain($r->blocks($v, $part, 'text'));
        }
        // The one label added to Part A (the contract reference sits under the Part A heading).
        $rendered = str_replace('Contractreferencenumber:', '', $rendered);

        $source = $this->plain($r->markdownBlocks(file_get_contents(AgreementContent::sourcePath('agreement-v1.0.md'))))
            . $this->plain($r->markdownBlocks(file_get_contents(AgreementContent::sourcePath('netcash-mandate-v1.0.md'))));

        // The mandate's beneficiary name/address are filled by the company adapter (blank in this mode) — they are blanks in the source too.
        $this->assertSame($source, $rendered, 'Rendered wording differs from the signed-off source text.');
    }

    public function test_source_files_are_the_signed_off_text(): void
    {
        // Same fingerprint the conductor used when the text was transferred (whitespace/underscores ignored).
        $fp = fn (string $f) => substr(hash('sha256', preg_replace('/[\s\\\\_]+/u', '', file_get_contents(AgreementContent::sourcePath($f)))), 0, 16);
        $this->assertSame('ed6426c09be3166f', $fp('netcash-mandate-v1.0.md'));
        $this->assertNotEmpty($fp('agreement-v1.0.md'));
    }

    public function test_clause_d3_6_says_we_maintain_the_website_and_nothing_still_says_we_host_it(): void
    {
        $new = '**3.6** We maintain the website for as long as this agreement runs. When it ends, we stop maintaining it, and the domain name remains the Agency’s.';
        $source = file_get_contents(AgreementContent::sourcePath('agreement-v1.0.md'));
        $seeded = (new AgreementContent)->buildV1();

        $this->assertStringContainsString($new, $source);
        $this->assertStringContainsString($new, $seeded['part_d']);
        $this->assertStringContainsString('**3.2** We do not host the website.', $seeded['part_d'], 'clause 3.2 is unchanged');
        foreach (['source' => $source, 'seeded part D' => $seeded['part_d'], 'every seeded part' => implode("\n", $seeded)] as $where => $text) {
            $this->assertStringNotContainsString('We host the website', $text, $where);
            $this->assertStringNotContainsString('we stop hosting it', $text, $where);
        }
    }

    public function test_rates_in_the_wording_come_from_the_pinned_version_not_the_view(): void
    {
        $content = (new AgreementContent)->buildV1();
        $r = app(AgreementRenderer::class);
        $v = new WordingVersion(['content_json' => $content, 'rates_json' => array_merge(AgreementPricing::DEFAULT_RATES, ['agency_base' => 1695, 'team_seat' => 475])]);
        $html = implode('', $r->blocks($v, 'part_a', 'pdf', ['calc' => null, 'values' => []]));
        $this->assertStringContainsString('R1 695', $html);
        $this->assertStringContainsString('R475', $html);
        $this->assertStringNotContainsString('R1 495', $html);
    }

    public function test_every_token_in_the_wording_has_a_field_in_the_schema(): void
    {
        $schema = AgreementFields::schema();
        $all = implode("\n", (new AgreementContent)->buildV1());
        preg_match_all('/\{\{(f|rr):([a-z0-9_]+)\}\}|\{\{o:([a-z0-9_]+):/', $all, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);
        foreach ($m as $hit) {
            $key = $hit[2] ?: $hit[3];
            $this->assertArrayHasKey($key, $schema, "Token field {$key} has no schema entry");
        }
    }

    public function test_pricing_25_agents_on_the_agency_plan_is_base_plus_three_tiers(): void
    {
        $c = AgreementPricing::compute('agency', 25, 0, 0, AgreementPricing::DEFAULT_RATES);
        $this->assertSame(1, $c['lines']['agency_base']['qty']);
        $this->assertSame(10, $c['lines']['agency_t1']['qty']);
        $this->assertSame(10, $c['lines']['agency_t2']['qty']);
        $this->assertSame(5, $c['lines']['agency_t3']['qty']);
        $this->assertSame(1495 + 10 * 295 + 10 * 250 + 5 * 195, (int) $c['total']); // 1495 + 2950 + 2500 + 975 = 7920
        $this->assertSame(7920, (int) $c['total']);
    }

    public function test_pricing_team_plan_branches_variation_and_disabled_lines(): void
    {
        $team = AgreementPricing::compute('team', 8, 3, 0, AgreementPricing::DEFAULT_RATES);
        $this->assertSame(3600, (int) $team['total']);                 // 8 × 450; agency lines and branches are not live
        $this->assertSame(0, $team['lines']['agency_base']['qty']);
        $this->assertSame(0, $team['lines']['branches']['qty']);

        $agency = AgreementPricing::compute('agency', 12, 2, 500, AgreementPricing::DEFAULT_RATES);
        $this->assertSame(1495 + 10 * 295 + 2 * 250 + 2 * 750 - 500, (int) $agency['total']);

        $capped = AgreementPricing::compute('agency', 1, 0, 999999, AgreementPricing::DEFAULT_RATES);
        $this->assertSame(0, (int) $capped['total']);                  // a discount can never push the total below zero

        $this->assertTrue(AgreementPricing::compute('agency', 41, 0, 0, AgreementPricing::DEFAULT_RATES)['over_quote_threshold']);
        $this->assertFalse(AgreementPricing::compute('agency', 40, 0, 0, AgreementPricing::DEFAULT_RATES)['over_quote_threshold']);
        $this->assertSame('R1 495', AgreementPricing::rate(1495));
        $this->assertSame('R450', AgreementPricing::rate(450));
    }

    public function test_team_plan_refuses_more_than_ten_seats_and_validation_names_what_is_missing(): void
    {
        $errors = AgreementFields::validateRecipient(['plan' => 'team', 'agents' => '11', 'term' => 'other', 'term_months' => ''], AgreementPricing::DEFAULT_RATES);
        $this->assertArrayHasKey('agents', $errors);
        $this->assertStringContainsString('CoreX Agency', $errors['agents']);
        $this->assertArrayHasKey('term_months', $errors);
        $this->assertArrayHasKey('registered_name', $errors);
        $this->assertArrayNotHasKey('vat_no', $errors, 'VAT number is optional');
    }

    public function test_cleaning_drops_the_other_sides_fields_and_junk(): void
    {
        $clean = AgreementFields::clean(['registered_name' => " Caprivi \x00 Realty ", 'variation_amount' => '999', 'rr_name' => 'Evil', 'plan' => 'platinum', 'agents' => '1a2', 'sigA' => 'javascript:alert(1)'], 'r');
        $this->assertSame('Caprivi Realty', $clean['registered_name']);
        $this->assertArrayNotHasKey('variation_amount', $clean);
        $this->assertArrayNotHasKey('rr_name', $clean);
        $this->assertSame('', $clean['plan']);
        $this->assertSame('12', $clean['agents']);
        $this->assertSame('', $clean['sigA']);
    }
}
