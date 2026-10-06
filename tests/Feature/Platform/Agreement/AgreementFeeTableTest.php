<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementPricing;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Subscription Agreement fee table — spec §11.5. Section 3 completes itself from TWO entries (agents, branches):
 * 1–10 agents → CoreX Team (R450 a seat, flat, no base fee, no branch fee); 11+ → CoreX Agency (R1 495 base + graduated seats
 * 10×R295 / 10×R250 / rest×R195 + (branches − 1)×R750). The expectations here are written out independently of the code.
 */
class AgreementFeeTableTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    /** @return array{plan:string,qty:array<string,int>,total:int} */
    private function expected(int $agents, int $branches): array
    {
        $q = ['team_seats' => 0, 'agency_base' => 0, 'agency_t1' => 0, 'agency_t2' => 0, 'agency_t3' => 0, 'branches' => 0];
        if ($agents <= 10) {
            $q['team_seats'] = $agents;

            return ['plan' => 'team', 'qty' => $q, 'total' => 450 * $agents];
        }
        $q['agency_base'] = 1;
        $q['agency_t1'] = 10;
        $q['agency_t2'] = min($agents - 10, 10);
        $q['agency_t3'] = max($agents - 20, 0);
        $q['branches'] = max($branches - 1, 0);

        return ['plan' => 'agency', 'qty' => $q, 'total' => 1495 + 295 * 10 + 250 * $q['agency_t2'] + 195 * $q['agency_t3'] + 750 * $q['branches']];
    }

    public static function table(): array
    {
        $rows = [];
        foreach ([1, 10, 11, 13, 20, 21, 25, 40, 41] as $agents) {
            foreach ([1, 2, 4] as $branches) {
                $rows["$agents agents, $branches branches"] = [$agents, $branches];
            }
        }

        return $rows;
    }

    /** @dataProvider table */
    public function test_the_calculation_selects_the_plan_and_tiers_the_seats(int $agents, int $branches): void
    {
        $e = $this->expected($agents, $branches);
        $c = AgreementPricing::derive(['agents' => (string) $agents, 'branches' => (string) $branches], [], AgreementPricing::DEFAULT_RATES);

        $this->assertSame($e['plan'], $c['plan']);
        foreach ($e['qty'] as $line => $qty) {
            $this->assertSame($qty, $c['lines'][$line]['qty'], "$line quantity for $agents agents / $branches branches");
        }
        $this->assertEquals($e['total'], $c['total'], "monthly total for $agents agents / $branches branches");
        $this->assertSame($agents > 40, $c['over_quote_threshold']);
    }

    public function test_thirteen_agents_on_one_branch_is_five_thousand_one_hundred_and_ninety_five(): void
    {
        $c = AgreementPricing::derive(['agents' => '13', 'branches' => '1'], [], AgreementPricing::DEFAULT_RATES);
        $this->assertSame('agency', $c['plan']);
        $this->assertSame([1, 10, 3, 0, 0], [$c['lines']['agency_base']['qty'], $c['lines']['agency_t1']['qty'], $c['lines']['agency_t2']['qty'], $c['lines']['agency_t3']['qty'], $c['lines']['branches']['qty']]);
        $this->assertEquals(1495.0, $c['lines']['agency_base']['amount']);
        $this->assertEquals(2950.0, $c['lines']['agency_t1']['amount']);
        $this->assertEquals(750.0, $c['lines']['agency_t2']['amount']);
        $this->assertEquals(5195.0, $c['total']);
    }

    public function test_the_contract_example_of_twenty_five_agents_is_10_times_295_plus_10_times_250_plus_5_times_195(): void
    {
        $c = AgreementPricing::derive(['agents' => '25', 'branches' => '1'], [], AgreementPricing::DEFAULT_RATES);
        $this->assertSame(10, $c['lines']['agency_t1']['qty']);
        $this->assertSame(10, $c['lines']['agency_t2']['qty']);
        $this->assertSame(5, $c['lines']['agency_t3']['qty']);
        $this->assertEquals(2950.0, $c['lines']['agency_t1']['amount']);
        $this->assertEquals(2500.0, $c['lines']['agency_t2']['amount']);
        $this->assertEquals(975.0, $c['lines']['agency_t3']['amount']);
        $this->assertEquals(1495 + 2950 + 2500 + 975, $c['total']);
    }

    public function test_team_has_no_base_fee_and_no_branch_fee_whatever_the_branches(): void
    {
        $c = AgreementPricing::derive(['agents' => '8', 'branches' => '4'], [], AgreementPricing::DEFAULT_RATES);
        $this->assertSame('team', $c['plan']);
        $this->assertSame(0, $c['lines']['agency_base']['qty']);
        $this->assertSame(0, $c['lines']['branches']['qty']);
        $this->assertEquals(3600.0, $c['total']);
    }

    public function test_no_agents_entered_selects_no_plan(): void
    {
        $c = AgreementPricing::derive(['agents' => '', 'branches' => '2'], [], AgreementPricing::DEFAULT_RATES);
        $this->assertSame('', $c['plan']);
        $this->assertEquals(0.0, $c['total']);
    }

    public function test_a_plan_fixed_by_the_sender_wins_over_the_number_of_agents(): void
    {
        $team = AgreementPricing::derive(['agents' => '25', 'branches' => '3'], ['plan_forced' => 'team'], AgreementPricing::DEFAULT_RATES);
        $this->assertSame('team', $team['plan']);
        $this->assertTrue($team['forced']);
        $agency = AgreementPricing::derive(['agents' => '5', 'branches' => '3'], ['plan_forced' => 'agency'], AgreementPricing::DEFAULT_RATES);
        $this->assertSame('agency', $agency['plan']);
        $this->assertSame(5, $agency['lines']['agency_t1']['qty']);
        $this->assertSame(2, $agency['lines']['branches']['qty']);
        $this->assertEquals(1495 + 5 * 295 + 2 * 750, $agency['total']);
    }

    // ── through the real recipient endpoints ───────────────────────────────

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

    public function test_saving_the_two_entries_completes_section_three_and_the_mandate_amount(): void
    {
        [$doc, $token] = $this->sent();
        $res = $this->save($token, ['agents' => '13', 'branches' => '3'], 0)->assertOk();
        $this->assertEquals(1495 + 2950 + 750 + 2 * 750, $res->json('calc.total'));

        $d = $doc->fresh();
        $this->assertSame('agency', $d->form_data['plan']);
        $this->assertSame('2', $d->form_data['extra_branches']);
        $this->assertSame('3', $d->form_data['branches_start']);
        $this->assertSame('6695', $d->form_data['m_amount']);
    }

    public function test_switching_from_ten_to_eleven_agents_and_back_flips_the_plan_and_follows_the_mandate_amount(): void
    {
        [$doc, $token] = $this->sent();
        $rev = $this->save($token, ['agents' => '10', 'branches' => '2'], 0)->assertOk()->json('rev');
        $this->assertSame('team', $doc->fresh()->form_data['plan']);
        $this->assertSame('0', $doc->fresh()->form_data['extra_branches']);
        $this->assertSame('4500', $doc->fresh()->form_data['m_amount']);

        $rev = $this->save($token, ['agents' => '11'], $rev)->assertOk()->json('rev');
        $this->assertSame('agency', $doc->fresh()->form_data['plan']);
        $this->assertSame('1', $doc->fresh()->form_data['extra_branches']);
        $this->assertSame((string) (1495 + 2950 + 250 + 750), $doc->fresh()->form_data['m_amount']);

        $this->save($token, ['agents' => '10'], $rev)->assertOk();
        $this->assertSame('team', $doc->fresh()->form_data['plan']);
        $this->assertSame('4500', $doc->fresh()->form_data['m_amount']);
    }

    public function test_a_mandate_amount_the_recipient_typed_themselves_is_left_alone(): void
    {
        [$doc, $token] = $this->sent();
        $rev = $this->save($token, ['agents' => '5', 'branches' => '1'], 0)->json('rev');
        $rev = $this->save($token, ['m_amount' => '999'], $rev)->json('rev');
        $this->save($token, ['agents' => '6'], $rev)->assertOk();
        $this->assertSame('999', $doc->fresh()->form_data['m_amount']);
    }

    public function test_the_recipient_cannot_tick_a_plan_or_set_extra_branches(): void
    {
        [$doc, $token] = $this->sent();
        $this->save($token, ['agents' => '25', 'branches' => '2', 'plan' => 'team', 'extra_branches' => '9', 'branches_start' => '7'], 0)->assertOk();
        $d = $doc->fresh();
        $this->assertSame('agency', $d->form_data['plan']);
        $this->assertSame('1', $d->form_data['extra_branches']);
        $this->assertSame('2', $d->form_data['branches_start']);
    }

    public function test_the_read_only_places_carry_a_screen_only_tip_that_links_to_the_agents_box(): void
    {
        [$doc, $token] = $this->sent();
        $html = $this->get(route('platform-esign.agreement.show', $token))->assertOk()->getContent();

        // three read-only places: the plan ticks, section 1 "Number of branches", section 3 "Branches at start"
        $this->assertSame(3, substr_count($html, 'class="auto-tip'));
        $this->assertSame(3, substr_count($html, 'Fills in automatically — enter your number of agents and branches in the '));
        $this->assertSame(3, substr_count($html, '<a href="#fld-agents" data-goto-agents="1">Monthly fee at start</a> section (section 3).'));
        $this->assertStringContainsString('id="fld-agents"', $html, 'the link target exists');
        $this->assertMatchesRegularExpression('/data-mirror="branches"[^>]*>\s*<span class="auto-tip"/', $html, 'tip next to the section 1 branches row');
        $this->assertMatchesRegularExpression('/name="branches_start"[^>]*>\s*<span class="auto-tip"/', $html, 'tip next to the section 3 branches row');
        $this->assertMatchesRegularExpression('/class="auto-tip auto-tip-float".*?name="plan" value="team"/s', $html, 'tip beside the plan ticks');
    }

    public function test_the_tips_are_not_in_any_other_rendering_the_pdfs_or_the_wording(): void
    {
        [$doc, $token] = $this->sent();
        $svc = app(AgreementService::class);
        $doc = Document::findOrFail($doc->id);
        $ctx = $svc->context($doc);
        $renderer = app(\App\Services\PlatformEsign\Agreement\AgreementRenderer::class);
        foreach (['wet', 'pdf', 'rr', 'preview', 'text', 'canon'] as $mode) {
            foreach (array_keys(\App\Services\PlatformEsign\Agreement\AgreementContent::PARTS) as $part) {
                $out = implode("\n", $renderer->blocks($doc->wording, $part, $mode, $ctx));
                $this->assertStringNotContainsString('auto-tip', $out, "$mode/$part");
                $this->assertStringNotContainsString('Fills in automatically', $out, "$mode/$part");
            }
        }
        // the stored wording itself carries no tip
        foreach ((array) $doc->wording->content_json as $part => $md) {
            $this->assertStringNotContainsString('Fills in automatically', $md, $part);
        }
        // and the real wet-ink PDF
        $layout = app(\App\Services\PlatformEsign\Agreement\AgreementLayout::class)->ensure($doc->wording);
        $wet = $svc->wetCopy($doc, $svc->agencySigner($doc), null);
        $text = \App\Services\PlatformEsign\Agreement\AgreementFidelity::pdfText($wet, (int) $layout['total']);
        $this->assertStringNotContainsString('Fills in automatically', $text);
        $this->assertStringNotContainsString('automatically', $text);
    }

    public function test_the_entries_and_the_fee_table_are_never_parted_by_a_page_break(): void
    {
        $v = app(\App\Services\PlatformEsign\Agreement\AgreementContent::class)->ensureSeeded();
        $layout = app(\App\Services\PlatformEsign\Agreement\AgreementLayout::class)->ensure($v);
        $blocks = app(\App\Services\PlatformEsign\Agreement\AgreementRenderer::class)->blocks($v, 'part_a', 'canon');
        $at = null;
        foreach ($blocks as $i => $html) {
            if (str_contains($html, 'keep-next')) {
                $at = $i;
            }
        }
        $this->assertNotNull($at, 'the entries block exists');
        $this->assertStringStartsWith('<table', $blocks[$at + 1], 'the fee table follows the entries');
        $pageOf = function (int $block) use ($layout) {
            $end = 0;
            foreach ($layout['parts']['part_a'] as $page => $count) {
                $end += $count;
                if ($block < $end) {
                    return $page;
                }
            }
        };
        $this->assertSame($pageOf($at), $pageOf($at + 1));
    }

    public function test_the_page_after_a_reload_shows_the_tiered_lines_and_a_plan_that_cannot_be_ticked(): void
    {
        [$doc, $token] = $this->sent();
        $this->save($token, ['agents' => '13', 'branches' => '1'], 0)->assertOk();

        $html = $this->get(route('platform-esign.agreement.show', $token))->assertOk()->getContent();
        foreach (['agency_t1' => 10, 'agency_t2' => 3, 'agency_t3' => 0, 'branches' => 0] as $line => $qty) {
            $this->assertMatchesRegularExpression('/data-calc="q:' . $line . '">' . $qty . '</', $html, $line);
        }
        $this->assertStringContainsString('data-calc="amt:agency_base">1 495<', $html);
        $this->assertStringContainsString('data-calc="amt:total">5 195<', $html);
        $this->assertMatchesRegularExpression('/value="agency" data-field="plan" data-derived="1" checked disabled/', $html);
        $this->assertMatchesRegularExpression('/value="team" data-field="plan" data-derived="1" disabled/', $html);
        $this->assertStringNotContainsString('name="extra_branches"', $html);
    }

    public function test_the_two_entries_sit_side_by_side_directly_above_the_fee_table_and_the_other_rows_only_show_them(): void
    {
        [$doc, $token] = $this->sent();
        $this->save($token, ['agents' => '13', 'branches' => '3'], 0)->assertOk();
        $html = $this->get(route('platform-esign.agreement.show', $token))->assertOk()->getContent();

        $pair = strpos($html, 'class="ctl-pair"');
        $this->assertNotFalse($pair);
        $this->assertStringContainsString('Number of agents</strong>', $html);
        $this->assertStringContainsString('Number of branches</strong>', $html);
        $agents = strpos($html, 'name="agents"');
        $branches = strpos($html, 'name="branches"');
        $table = strpos($html, 'seats 1 to 10');
        $this->assertTrue($pair < $agents && $agents < $branches && $branches < $table, 'agents and branches inputs, in that order, before the fee table');
        $this->assertSame(1, substr_count($html, 'name="branches"'), 'branches is typed in exactly one place');
        // section 1 "Number of branches" and section 3 "Branches at start" are read-only mirrors of that one value
        $this->assertMatchesRegularExpression('/value="3" readonly tabindex="-1" data-derived="1" data-mirror="branches"/', $html);
        $this->assertMatchesRegularExpression('/name="branches_start"[^>]*readonly/', $html);
        $this->assertMatchesRegularExpression('/value="3"[^>]*name="branches_start"|name="branches_start"[^>]*value="3"/', $html);
    }

    public function test_an_older_save_with_a_wrong_stored_split_is_recalculated_on_the_next_load(): void
    {
        [$doc, $token] = $this->sent();
        $doc->update(['form_data' => ['agents' => '13', 'branches' => '1', 'plan' => 'team', 'extra_branches' => '5']]);

        $html = $this->get(route('platform-esign.agreement.show', $token))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-calc="q:agency_t2">3</', $html);
        $this->assertStringContainsString('data-calc="amt:total">5 195<', $html);
    }

    public function test_a_plan_fixed_at_send_time_is_kept_audited_and_shown_fixed(): void
    {
        [$doc, $token] = $this->sent(['plan' => 'agency']);
        $this->assertSame('agency', $doc->rr_data['plan_forced']);
        $this->assertTrue($doc->events()->where('event', 'plan_forced')->exists());

        $this->save($token, ['agents' => '4', 'branches' => '2'], 0)->assertOk();
        $this->assertSame('agency', $doc->fresh()->form_data['plan']);
        $this->assertSame('1', $doc->fresh()->form_data['extra_branches']);
        $html = $this->get(route('platform-esign.agreement.show', $token))->getContent();
        $this->assertMatchesRegularExpression('/value="agency" data-field="plan" data-derived="1" checked disabled/', $html);
        $this->assertStringContainsString('"forcedPlan":"agency"', $html);
    }

    public function test_a_fixed_team_plan_cannot_be_signed_for_more_than_ten_agents(): void
    {
        [$doc, $token] = $this->sent(['plan' => 'team']);
        $this->save($token, ['agents' => '12', 'branches' => '1'], 0)->assertOk();
        $errors = \App\Services\PlatformEsign\Agreement\AgreementFields::validateRecipient($doc->fresh()->form_data, AgreementPricing::DEFAULT_RATES);
        $this->assertArrayHasKey('agents', $errors);
    }

    public function test_agents_and_branches_must_be_at_least_one(): void
    {
        $errors = \App\Services\PlatformEsign\Agreement\AgreementFields::validateRecipient(['agents' => '0', 'branches' => '0'], AgreementPricing::DEFAULT_RATES);
        $this->assertArrayHasKey('agents', $errors);
        $this->assertArrayHasKey('branches', $errors);
        $errors = \App\Services\PlatformEsign\Agreement\AgreementFields::validateRecipient(['agents' => '', 'branches' => ''], AgreementPricing::DEFAULT_RATES);
        $this->assertArrayHasKey('agents', $errors);
        $this->assertArrayHasKey('branches', $errors);
    }

    public function test_countersign_review_wet_ink_and_sealed_pdf_use_the_same_tiered_figures(): void
    {
        [$doc, $token] = $this->sent();
        $svc = app(AgreementService::class);
        $this->save($token, ['agents' => '13', 'branches' => '1'], 0)->assertOk();
        $doc = Document::findOrFail($doc->id);
        $ctx = $svc->context($doc);

        $this->assertSame('agency', $ctx['values']['plan']);
        $this->assertSame(5195.0, (float) $ctx['calc']['total']);
        $renderer = app(\App\Services\PlatformEsign\Agreement\AgreementRenderer::class);
        foreach (['rr', 'pdf', 'wet'] as $mode) {
            $text = html_entity_decode(strip_tags(implode("\n", $renderer->blocks($doc->wording, 'part_a', $mode, $ctx))));
            $this->assertMatchesRegularExpression('/seats 1 to 10\s+10\s+R295\s+R 2 950/', $text, $mode);
            $this->assertMatchesRegularExpression('/seats 11 to 20\s+3\s+R250\s+R 750/', $text, $mode);
            $this->assertMatchesRegularExpression('/Monthly total\s+R 5 195/', $text, $mode);
            $this->assertStringContainsString('☒ CoreX Agency', $text, $mode);
        }
    }
}
