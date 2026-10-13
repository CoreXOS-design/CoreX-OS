<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\PlatformEsign\Document;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementDiscount;
use App\Services\PlatformEsign\Agreement\AgreementPricing;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The agreed discount on the Subscription Agreement (spec agency-timeline-and-platform-esign.md §11.7a): a type (percentage | rand amount),
 * a value and an optional duration in months. The percentage/amount applies to the WHOLE monthly bill; the document states it in words.
 * Fixtures: 8 agents = CoreX Team, 8 x R450 = R3 600 a month. 11 agents, 1 branch = Agency, R1 495 + 10 x R295 + 1 x R250 = R4 695.
 */
class AgreementDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);
    }

    private function send(User $owner, array $discount, int $agents = 8, int $branches = 1): Document
    {
        Mail::fake();
        $doc = app(AgreementService::class)->send($discount + ['name' => 'Pat Principal', 'email' => 'pat@caprivi.test'], $owner->id);
        $doc->update(['form_data' => array_merge((array) $doc->form_data, ['agents' => (string) $agents, 'branches' => (string) $branches])]);

        return $doc->fresh();
    }

    /** The owner's review screen as plain text (entities decoded, tags removed, spaces collapsed). */
    private function words(User $owner, Document $doc): string
    {
        $html = $this->actingAs($owner)->get(route('platform-esign.agreements.review', $doc->id))->assertOk()->getContent();

        $html = preg_replace('#<(script|style)\b.*?</\1>#si', '', $html); // the page's own JS carries the same sentences

        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function calc(Document $doc): array
    {
        return app(AgreementService::class)->context($doc)['calc'];
    }

    // ── percentage + months ────────────────────────────────────────────────

    public function test_percent_for_three_months_states_every_number_in_the_document(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, ['variation_type' => 'percent', 'variation_value' => '30', 'variation_months' => '3']);

        $rr = (array) $doc->rr_data;
        $this->assertSame('percent', $rr['variation_type']);
        $this->assertSame('30', $rr['variation_percent']);
        $this->assertSame('3', $rr['variation_months']);
        $this->assertArrayNotHasKey('variation_amount', $rr);

        $c = $this->calc($doc);
        $this->assertSame(3600.0, $c['subtotal']);
        $this->assertSame(1080.0, $c['variation']);
        $this->assertSame(2520.0, $c['total']);
        $this->assertSame(3600.0, $c['full_total']);
        $this->assertSame(3, $c['discount_months']);

        $t = $this->words($owner, $doc);
        foreach ([
            'Agreed discount: 30% off each monthly bill for the first 3 months billed (months 1 to 3).',
            'Normal monthly fee: R3 600.', 'Discount: R1 080 a month.', 'Monthly fee for months 1 to 3: R2 520.', 'Monthly fee from month 4: R3 600.',
            'the discount is 30% of that month’s whole bill.', '(months 1 to 3)',
        ] as $line) {
            $this->assertStringContainsString($line, $t);
        }
        // the debit order amount is the first debit: the discounted fee (set by the take-on month)
        $withTakeOn = $this->send($owner, ['variation_type' => 'percent', 'variation_value' => '30', 'variation_months' => '3', 'take_on_month' => now()->format('Y-m')]);
        $this->assertSame('2520', app(AgreementService::class)->context($withTakeOn)['values']['m_amount']);
    }

    public function test_a_percentage_is_taken_off_every_recurring_line_not_just_the_base_fee(): void
    {
        // Agency plan: base + three seat tiers + extra branches are all part of the bill the percentage applies to.
        $c = AgreementPricing::derive(['agents' => '25', 'branches' => '3'], ['variation_type' => 'percent', 'variation_percent' => '10', 'variation_months' => '2'], AgreementPricing::DEFAULT_RATES);
        $this->assertSame(9420.0, $c['subtotal']); // 1495 + 10x295 + 10x250 + 5x195 + 2x750
        $this->assertSame(942.0, $c['variation']);
        $this->assertSame(8478.0, $c['total']);
    }

    public function test_percent_is_rounded_to_cents_the_same_way_every_time(): void
    {
        $c = AgreementPricing::derive(['agents' => '11', 'branches' => '1'], ['variation_type' => 'percent', 'variation_percent' => '12.5'], AgreementPricing::DEFAULT_RATES);
        $this->assertSame(4695.0, $c['subtotal']);
        $this->assertSame(586.88, $c['variation']); // 586.875
        $this->assertSame(4108.12, $c['total']);
        $this->assertSame('R586.88', AgreementPricing::rate($c['variation']));
    }

    public function test_percent_for_the_whole_term_has_no_second_price(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, ['variation_type' => 'percent', 'variation_value' => '30']);
        $t = $this->words($owner, $doc);

        $this->assertStringContainsString('Agreed discount: 30% off each monthly bill for the whole term.', $t);
        $this->assertStringContainsString('Monthly fee after the discount: R2 520.', $t);
        $this->assertStringNotContainsString('from month', $t);
        $this->assertStringNotContainsString('(months 1 to', $t);
        $this->assertNull($this->calc($doc)['discount_months']);
    }

    // ── rand amount ────────────────────────────────────────────────────────

    public function test_rand_amount_for_months(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, ['variation_type' => 'amount', 'variation_value' => '500', 'variation_months' => '6']);
        $t = $this->words($owner, $doc);

        $this->assertStringContainsString('Agreed discount: R500 off each monthly bill for the first 6 months billed (months 1 to 6).', $t);
        $this->assertStringContainsString('Monthly fee for months 1 to 6: R3 100.', $t);
        $this->assertStringContainsString('Monthly fee from month 7: R3 600.', $t);
        $this->assertSame(3100.0, $this->calc($doc)['total']);
    }

    public function test_rand_amount_for_the_whole_term_keeps_the_old_numbers(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, ['variation_amount' => '500', 'variation_text' => 'Launch discount']); // the old field, no type
        $rr = (array) $doc->rr_data;
        $this->assertSame('amount', $rr['variation_type']);
        $this->assertSame('500', $rr['variation_amount']);
        $this->assertArrayNotHasKey('variation_months', $rr);
        $this->assertSame(3100.0, $this->calc($doc)['total']);
        $this->assertSame('R500 a month for the whole term', AgreementDiscount::summary($rr));
    }

    public function test_a_rand_amount_above_the_fee_is_capped_so_the_bill_never_goes_below_zero(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, ['variation_type' => 'amount', 'variation_value' => '9000']);
        $c = $this->calc($doc);
        $this->assertSame(3600.0, $c['variation']);
        $this->assertSame(0.0, $c['total']);
    }

    // ── 0 % and 100 % ──────────────────────────────────────────────────────

    public function test_zero_percent_is_no_discount_at_all(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, ['variation_type' => 'percent', 'variation_value' => '0']);
        $this->assertSame([], array_intersect_key((array) $doc->rr_data, array_flip(['variation_type', 'variation_percent', 'variation_amount', 'variation_months'])));
        $this->assertStringNotContainsString('Agreed discount:', $this->words($owner, $doc));
        $this->assertSame(3600.0, $this->calc($doc)['total']);
    }

    public function test_one_hundred_percent_for_two_months_is_free_then_the_normal_fee(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, ['variation_type' => 'percent', 'variation_value' => '100', 'variation_months' => '2']);
        $c = $this->calc($doc);
        $this->assertSame(3600.0, $c['variation']);
        $this->assertSame(0.0, $c['total']);
        $t = $this->words($owner, $doc);
        $this->assertStringContainsString('Monthly fee for months 1 to 2: R0.', $t);
        $this->assertStringContainsString('Monthly fee from month 3: R3 600.', $t);
    }

    public function test_one_month_wording(): void
    {
        $spec = AgreementDiscount::fromRr(['variation_type' => 'percent', 'variation_percent' => '50', 'variation_months' => '1']);
        $this->assertSame('Agreed discount: 50% off each monthly bill for the first month billed.', AgreementDiscount::headline($spec));
        $this->assertContains('Monthly fee for month 1: R1 800.', AgreementDiscount::figures($spec, 3600.0));
        $this->assertContains('Monthly fee from month 2: R3 600.', AgreementDiscount::figures($spec, 3600.0));
    }

    // ── invalid input ──────────────────────────────────────────────────────

    /** @dataProvider badInputs */
    public function test_invalid_input_is_refused_with_a_plain_message(?string $type, ?string $value, ?string $months, string $messagePart): void
    {
        try {
            AgreementDiscount::parseInput($type, $value, $months);
            $this->fail('expected a refusal');
        } catch (\DomainException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());
        }
    }

    public static function badInputs(): array
    {
        return [
            'percent over 100'      => ['percent', '101', null, 'from 0 to 100'],
            'percent negative'      => ['percent', '-5', null, 'from 0 to 100'],
            'percent text'          => ['percent', 'abc', null, 'from 0 to 100'],
            'percent exponent'      => ['percent', '1e2', null, 'from 0 to 100'],
            'percent three places'  => ['percent', '12.345', null, 'from 0 to 100'],
            'amount text'           => ['amount', 'five hundred', null, 'amount in rand'],
            'amount negative'       => ['amount', '-1', null, 'amount in rand'],
            'amount ten digits'     => ['amount', '1234567890', null, 'amount in rand'],
            'months zero'           => ['percent', '30', '0', 'whole number from 1 to 120'],
            'months fraction'       => ['percent', '30', '1.5', 'whole number from 1 to 120'],
            'months text'           => ['percent', '30', 'three', 'whole number from 1 to 120'],
            'months too many'       => ['percent', '30', '121', 'whole number from 1 to 120'],
            'months without value'  => ['percent', '', '3', 'for those months'],
            'months with zero pct'  => ['percent', '0', '3', 'for those months'],
        ];
    }

    public function test_good_input_variants_are_understood(): void
    {
        $this->assertSame(['variation_type' => 'percent', 'variation_percent' => '30'], AgreementDiscount::parseInput('percent', ' 30 % ', ''));
        $this->assertSame(['variation_type' => 'percent', 'variation_percent' => '12.5', 'variation_months' => '3'], AgreementDiscount::parseInput('percent', '12,5', '3'));
        $this->assertSame(['variation_type' => 'amount', 'variation_amount' => '1500.5'], AgreementDiscount::parseInput('amount', 'R1,500.50', null));
        $this->assertSame(['variation_type' => 'amount', 'variation_amount' => '500'], AgreementDiscount::parseInput(null, '500', null)); // no type = rand, as before
        $this->assertSame([], AgreementDiscount::parseInput('percent', '', ''));
    }

    public function test_the_send_form_refuses_a_bad_discount_and_a_bad_type(): void
    {
        $owner = $this->owner();
        $base = ['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'take_on_month' => now()->format('Y-m')];
        $this->actingAs($owner)->post(route('platform-esign.agreements.store'), $base + ['variation_type' => 'bogus'])->assertSessionHasErrors('variation_type');
        $this->actingAs($owner)->from(route('platform-esign.agreements.create'))->post(route('platform-esign.agreements.store'), $base + ['variation_type' => 'percent', 'variation_value' => '150'])->assertSessionHasErrors('send');
        $this->assertSame(0, Document::count());
    }

    public function test_the_send_form_stores_a_percentage_for_months_and_shows_the_controls(): void
    {
        Mail::fake();
        $owner = $this->owner();
        $page = $this->actingAs($owner)->get(route('platform-esign.agreements.create'))->assertOk();
        foreach (['name="variation_type"', 'name="variation_value"', 'name="variation_months"', 'name="variation_text"'] as $f) {
            $page->assertSee($f, false);
        }
        $page->assertDontSee('Monthly discount (R)');

        $this->actingAs($owner)->post(route('platform-esign.agreements.store'), [
            'name' => 'ZZ TEST Agency', 'email' => 'can.assurance@gmail.com', 'take_on_month' => now()->format('Y-m'),
            'variation_type' => 'percent', 'variation_value' => '30', 'variation_months' => '3',
        ])->assertRedirect();
        $doc = Document::latest('id')->first();
        $this->assertSame('percent', $doc->rr_data['variation_type']);
        $this->assertSame('3', $doc->rr_data['variation_months']);
        $this->assertDatabaseHas('platform_esign_events', ['document_id' => $doc->id, 'event' => 'discount_set']);
    }

    // ── what the sender sees afterwards ────────────────────────────────────

    public function test_the_document_page_and_the_list_show_the_discount_in_words(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, ['variation_type' => 'percent', 'variation_value' => '30', 'variation_months' => '3']);

        $this->actingAs($owner)->get(route('platform-esign.documents.show', $doc->id))->assertOk()->assertSee('Agreed discount:', false)->assertSee('30% for the first 3 months', false);
        $this->actingAs($owner)->get(route('platform-esign.documents.index'))->assertOk()->assertSee('Discount: 30% for the first 3 months', false);
    }

    // ── agreements sent before this existed ────────────────────────────────

    public function test_an_older_agreement_with_a_rand_discount_renders_exactly_as_before(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, []);
        // the shape the old send() stored: no variation_type, no months
        $doc->update(['rr_data' => array_merge((array) $doc->rr_data, ['variation_text' => 'Launch discount', 'variation_amount' => '300'])]);
        $doc = $doc->fresh();

        $t = $this->words($owner, $doc);
        $this->assertStringContainsString('Launch discount', $t);
        $this->assertStringNotContainsString('Agreed discount:', $t);
        $this->assertStringNotContainsString('Monthly fee from month', $t);
        $this->assertStringNotContainsString('(months 1 to', $t);
        $c = $this->calc($doc);
        $this->assertSame(300.0, $c['variation']);
        $this->assertSame(3300.0, $c['total']);
        $this->assertNull($c['discount_months']);

        // and an older agreement with no discount at all
        $none = $this->send($owner, []);
        $this->assertEquals(0, $this->calc($none)['variation']);
        $this->assertSame(3600.0, $this->calc($none)['total']);
    }

    public function test_the_fee_table_row_shows_the_rand_value_of_a_percentage(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, ['variation_type' => 'percent', 'variation_value' => '30', 'variation_months' => '3']);
        $html = $this->actingAs($owner)->get(route('platform-esign.agreements.review', $doc->id))->getContent();
        $this->assertMatchesRegularExpression('/– R\s*<span class="val" data-calc="disc:off">1 080<\/span>/u', $html);
        $this->assertMatchesRegularExpression('/R\s*<span data-calc="amt:total">2 520<\/span>/u', $html);
    }

    public function test_before_the_recipient_enters_anything_the_words_show_but_no_figures(): void
    {
        $owner = $this->owner();
        Mail::fake();
        $doc = app(AgreementService::class)->send(['name' => 'Pat', 'email' => 'pat@caprivi.test', 'variation_type' => 'percent', 'variation_value' => '30', 'variation_months' => '3'], $owner->id);
        $t = $this->words($owner, $doc);
        $this->assertStringContainsString('Agreed discount: 30% off each monthly bill for the first 3 months billed', $t);
        $this->assertStringNotContainsString('Normal monthly fee:', $t);
    }

    // ── the sealed record ──────────────────────────────────────────────────

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function test_a_percentage_agreement_is_signed_countersigned_and_sealed_without_overflowing_a_page(): void
    {
        $owner = $this->owner();
        Mail::fake();
        $doc = app(AgreementService::class)->send(['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'variation_type' => 'percent', 'variation_value' => '30', 'variation_months' => '3'], $owner->id);
        $token = \App\Models\PlatformEsign\Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token');
        $total = app(AgreementService::class)->totalPages($doc);
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'pp'])->assertOk();
        foreach (range(1, $total) as $p) {
            $this->postJson(route('platform-esign.agreement.initial-page', [$token, $p]))->assertOk();
        }
        $values = \App\Services\PlatformEsign\Agreement\AgreementSample::ctx($doc->wording)['values'] + ['sigA' => self::PNG, 'sigM' => self::PNG];
        $this->postJson(route('platform-esign.agreement.submit', $token), ['values' => $values, 'id_number' => '8001015009087', 'consent' => 1])->assertOk();

        // 25 agents, 3 branches: R9 420 a month; 30% = R2 826; R6 594 for months 1 to 3
        $this->assertSame('6594', $doc->fresh()->form_data['m_total_at_signing']);

        $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), [
            'values' => ['rr_name' => 'Johan Reichel', 'rr_capacity' => 'Director', 'rr_place' => 'Southbroom', 'rr_date' => now()->toDateString(), 'sigR' => self::PNG],
            'initials' => 'JR', 'pages' => range(1, $total),
        ])->assertOk()->assertJsonPath('ok', true);

        $fresh = $doc->fresh(['events']);
        $this->assertSame('completed', $fresh->status);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($fresh->sealed_pdf_path));
        $this->assertNotContains('page_count_mismatch', $fresh->events->pluck('event')->all(), 'the discount statement must not push the contract onto an extra page');
        // the discount rules survive the countersign untouched
        $this->assertSame('percent', $fresh->rr_data['variation_type']);
        $this->assertSame('3', $fresh->rr_data['variation_months']);
    }
}
