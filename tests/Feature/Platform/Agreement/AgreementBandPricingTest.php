<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\PlatformEsign\WordingVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementFields;
use App\Services\PlatformEsign\Agreement\AgreementPricing;
use App\Services\PlatformEsign\Agreement\AgreementRenderer;
use App\Services\PlatformEsign\Agreement\AgreementService;
use App\Services\PlatformEsign\Agreement\AgreementVersions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The October 2026 price list in the Subscription Agreement — spec §11.5a (Johan 2026-10-19). One flat monthly fee per band:
 * 1 agent R450 · 2 agents R900 · 3–5 R1 495 · 6–10 R2 495 · 11–15 R3 295 · 16–20 R3 995 · 21–25 R4 595 · 26–30 R5 095 · more than 30
 * on quotation · R750 for each branch after the first, whatever the band. The expectations are written out independently of the code.
 */
class AgreementBandPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    public static function bands(): array
    {
        return [
            '1 agent' => [1, 450], '2 agents' => [2, 900], '3 agents' => [3, 1495], '5 agents' => [5, 1495], '6 agents' => [6, 2495],
            '10 agents' => [10, 2495], '11 agents' => [11, 3295], '15 agents' => [15, 3295], '16 agents' => [16, 3995], '20 agents' => [20, 3995],
            '21 agents' => [21, 4595], '25 agents' => [25, 4595], '26 agents' => [26, 5095], '30 agents' => [30, 5095],
        ];
    }

    /** @dataProvider bands */
    public function test_each_band_is_one_flat_monthly_fee(int $agents, int $fee): void
    {
        $c = AgreementPricing::derive(['agents' => (string) $agents, 'branches' => '1'], [], AgreementPricing::BAND_RATES);
        $this->assertEquals($fee, $c['total'], "$agents agents");
        $this->assertSame(1, $c['lines'][$c['plan']]['qty']);
        $this->assertFalse($c['over_quote_threshold']);
    }

    public function test_each_branch_after_the_first_is_seven_hundred_and_fifty_in_every_band(): void
    {
        $c = AgreementPricing::derive(['agents' => '1', 'branches' => '3'], [], AgreementPricing::BAND_RATES);
        $this->assertSame(2, $c['extra_branches']);
        $this->assertEquals(450 + 2 * 750, $c['total']);
        $c = AgreementPricing::derive(['agents' => '12', 'branches' => '2'], [], AgreementPricing::BAND_RATES);
        $this->assertEquals(3295 + 750, $c['total']);
    }

    public function test_more_than_thirty_agents_has_no_price_without_a_quoted_fee(): void
    {
        $c = AgreementPricing::derive(['agents' => '31', 'branches' => '2'], [], AgreementPricing::BAND_RATES);
        $this->assertSame('', $c['plan']);
        $this->assertTrue($c['over_quote_threshold']);
        $this->assertEquals(0, $c['total']);

        $c = AgreementPricing::derive(['agents' => '31', 'branches' => '2'], ['quoted_fee' => '5995'], AgreementPricing::BAND_RATES);
        $this->assertSame('quoted', $c['plan']);
        $this->assertEquals(5995 + 750, $c['total']);
    }

    public function test_a_tier_priced_version_still_prices_by_tiers(): void
    {
        $c = AgreementPricing::derive(['agents' => '13', 'branches' => '1'], [], AgreementPricing::DEFAULT_RATES);
        $this->assertSame('agency', $c['plan']);
        $this->assertEquals(5195, $c['total']);
    }

    // ── the wording version ────────────────────────────────────────────────

    public function test_a_fresh_environment_publishes_the_band_version_on_top_of_one_point_zero(): void
    {
        $cur = app(AgreementContent::class)->ensureSeeded();
        $this->assertTrue(AgreementPricing::isBands((array) $cur->rates_json));
        $this->assertSame('1.1', $cur->version);
        $this->assertSame('1.0', $cur->parent->version);
        // The band version itself swaps the plan fields for band fields - a developer change, which is exactly what the
        // "fields can be moved, not added or removed" rule leaves to developers. What must hold: a person's wording edit
        // made from it passes every publish rule, so the wording can still be changed later without a developer.
        $draft = app(AgreementVersions::class)->createDraft($this->owner()->id);
        $this->assertSame($cur->id, (int) $draft->parent_version_id);
        $this->assertSame([], app(AgreementVersions::class)->problems($draft), 'a draft of the band version passes every publish rule');
    }

    public function test_an_environment_on_tier_pricing_gets_a_new_version_and_the_old_one_is_untouched(): void
    {
        $this->onlyTierPricing();
        $old = AgreementContent::current();
        $before = $old->content_json;

        $new = app(AgreementContent::class)->publishBandPricing(null);
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($new->id, AgreementContent::current()->id);
        $this->assertSame($before, $old->fresh()->content_json, 'a published version never changes');
        $this->assertSame($new->id, app(AgreementContent::class)->publishBandPricing(null)->id, 'running it again changes nothing');

        foreach (['intro', 'part_c', 'part_d', 'mandate'] as $part) {
            $this->assertSame($before[$part], $new->content_json[$part], "$part is word for word the same");
        }
    }

    public function test_the_band_version_is_not_published_over_an_open_draft(): void
    {
        $this->onlyTierPricing();
        app(AgreementVersions::class)->createDraft($this->owner()->id);
        $this->expectException(\DomainException::class);
        app(AgreementContent::class)->publishBandPricing(null);
    }

    public function test_the_contract_states_the_bands_and_the_new_clause_ten_one(): void
    {
        $v = app(AgreementContent::class)->ensureSeeded();
        $text = $this->text($v, 'part_a', 'text');
        foreach (['1 agent R450', '2 agents R900', '3 to 5 agents R1 495', '6 to 10 agents R2 495', '11 to 15 agents R3 295', '16 to 20 agents R3 995',
            '21 to 25 agents R4 595', '26 to 30 agents R5 095', 'More than 30 agents — quoted fee On quotation', 'each branch after the first) R750'] as $row) {
            $this->assertStringContainsString($row, $text);
        }
        $this->assertStringContainsString('for example, 4 agents pay R1 495 a month', $text);
        $this->assertStringNotContainsString('CoreX Team', $text);
        $this->assertStringNotContainsString('base fee', $text);
        $this->assertStringContainsString('Once RR Technologies becomes VAT registered, the amounts quoted will be inclusive of VAT and will not increase because of VAT.', $text);
        $this->assertStringContainsString('10.1 Fees are the flat monthly fee for the price band', $this->text($v, 'part_b', 'text'));
    }

    // ── through the real recipient endpoints ───────────────────────────────

    public function test_saving_the_entries_fills_the_band_line_and_the_mandate_amount(): void
    {
        [$doc, $token] = $this->sent();
        $res = $this->save($token, ['agents' => '4', 'branches' => '2'], 0)->assertOk();
        $this->assertEquals(1495 + 750, $res->json('calc.total'));
        $d = $doc->fresh();
        $this->assertSame('band3', $d->form_data['plan']);
        $this->assertSame('1', $d->form_data['extra_branches']);
        $this->assertSame('2245', $d->form_data['m_amount']);

        $html = html_entity_decode(strip_tags(implode("\n", app(AgreementRenderer::class)->blocks($d->wording, 'part_a', 'pdf', app(AgreementService::class)->context($d)))));
        $this->assertMatchesRegularExpression('/3 to 5 agents\s+1\s+R1 495\s+R 1 495/', $html);
        $this->assertMatchesRegularExpression('/each branch after the first\)\s+1\s+R750\s+R 750/', $html);
        $this->assertMatchesRegularExpression('/Monthly total\s+R 2 245/', $html);
        $this->assertDoesNotMatchRegularExpression('/6 to 10 agents\s+1/', $html, 'only the band the agents fall in is filled');
    }

    public function test_more_than_thirty_agents_without_a_quoted_fee_cannot_be_completed(): void
    {
        [$doc, $token] = $this->sent();
        $this->save($token, ['agents' => '35', 'branches' => '1'], 0)->assertOk();
        $d = $doc->fresh();
        $this->assertSame('', $d->form_data['plan']);
        $errors = AgreementFields::validateRecipient($d->form_data, (array) $d->wording->rates_json);
        $this->assertSame(AgreementRenderer::overBandsNote(30), $errors['agents']);
        $this->assertStringContainsString('the monthly fee is on quotation', $this->get(route('platform-esign.agreement.show', $token))->assertOk()->getContent());
    }

    public function test_a_quoted_fee_set_at_send_time_prices_more_than_thirty_agents(): void
    {
        [$doc, $token] = $this->sent(['quoted_fee' => '6 200']);
        $this->assertSame('6200', $doc->rr_data['quoted_fee']);
        $res = $this->save($token, ['agents' => '40', 'branches' => '1'], 0)->assertOk();
        $this->assertEquals(6200, $res->json('calc.total'));
        $d = $doc->fresh();
        $this->assertSame('quoted', $d->form_data['plan']);
        $this->assertArrayNotHasKey('agents', AgreementFields::validateRecipient($d->form_data, (array) $d->wording->rates_json));
        $this->assertSame(1, $d->events()->where('event', 'quoted_fee_set')->count());
    }

    public function test_a_tier_priced_agreement_already_sent_keeps_its_tiers_after_the_band_version_is_published(): void
    {
        $this->onlyTierPricing();
        [$doc, $token] = $this->sent();
        app(AgreementContent::class)->publishBandPricing(null);
        $res = $this->save($token, ['agents' => '13', 'branches' => '1'], 0)->assertOk();
        $this->assertEquals(5195, $res->json('calc.total'));
        $this->assertSame('agency', $doc->fresh()->form_data['plan']);
    }

    public function test_the_rates_tab_of_a_band_version_offers_the_bands_and_refuses_overlapping_bands(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded();
        $svc = app(AgreementVersions::class);
        $draft = $svc->createDraft($owner->id);
        $this->assertSame(array_keys(AgreementVersions::BAND_RATE_FIELDS), array_keys(AgreementVersions::rateFields((array) $draft->rates_json)));

        $input = array_map(fn ($v) => (string) $v, AgreementPricing::BAND_RATES);
        $input['band4_max'] = '5';
        try {
            $svc->saveRates($draft, $input, 0, $owner->id);
            $this->fail('overlapping bands were accepted');
        } catch (\App\Services\PlatformEsign\Agreement\WordingInvalid $e) {
            $this->assertStringContainsString('Band 4 must run up to more agents than band 3', implode(' ', $e->errors));
        }
    }

    public function test_wording_that_names_a_band_the_rates_do_not_have_is_refused_at_publish(): void
    {
        $v = app(AgreementContent::class)->ensureSeeded();
        $md = str_replace('| 3 to 5 agents|', '| 3 to 6 agents|', $v->content_json['part_a']);
        $errors = \App\Services\PlatformEsign\Agreement\AgreementConsistency::check($md, AgreementPricing::BAND_RATES, AgreementPricing::BAND_RATES, 'Part A');
        $this->assertNotEmpty(array_filter($errors, fn ($e) => str_contains($e, '“3 to 6 agents”')));
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function onlyTierPricing(): void
    {
        app(AgreementContent::class)->ensureSeeded();
        // An environment that has never had the band version. A published version can never be deleted through the app (the
        // model refuses), so the test removes the row underneath it.
        $ids = WordingVersion::withTrashed()->get()->filter(fn ($v) => AgreementPricing::isBands((array) $v->rates_json))->pluck('id');
        \App\Models\PlatformEsign\WordingAudit::whereIn('version_id', $ids)->delete();
        \Illuminate\Support\Facades\DB::table((new WordingVersion())->getTable())->whereIn('id', $ids)->orderByDesc('id')->delete();
    }

    private function text(WordingVersion $v, string $part, string $mode): string
    {
        $html = implode("\n", app(AgreementRenderer::class)->blocks($v, $part, $mode));

        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);
    }

    private function sent(array $extra = []): array
    {
        Mail::fake();
        $doc = app(AgreementService::class)->send($extra + ['name' => 'Pat Principal', 'email' => 'pat@caprivi.test'], $this->owner()->id);

        return [$doc, Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token')];
    }

    private function save(string $token, array $values, int $rev)
    {
        return $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => $rev, 'values' => $values]);
    }
}
