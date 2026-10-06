<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementFidelity;
use App\Services\PlatformEsign\Agreement\AgreementLayout;
use App\Services\PlatformEsign\Agreement\AgreementService;
use App\Services\PlatformEsign\Agreement\AgreementTakeOn;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Take-on month (spec §11.19): RR sets it on the send form; the agreement start date is the 1st of that month and billing
 * (first debit) starts on the 1st of the NEXT month. The agency cannot change either date.
 */
class AgreementTakeOnTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    public static function months(): array
    {
        return [
            'October -> 1 November' => ['2026-10', '2026-10-01', '2026-11-01'],
            'November -> 1 December' => ['2026-11', '2026-11-01', '2026-12-01'],
            'December -> 1 January, next year' => ['2026-12', '2026-12-01', '2027-01-01'],
            'January' => ['2027-01', '2027-01-01', '2027-02-01'],
            'February (short month)' => ['2027-02', '2027-02-01', '2027-03-01'],
        ];
    }

    /** @dataProvider months */
    public function test_the_dates_follow_the_take_on_month(string $month, string $start, string $billing): void
    {
        $d = AgreementTakeOn::derive($month);
        $this->assertSame($start, $d['start_date']);
        $this->assertSame($billing, $d['billing_start']);
        $this->assertSame(['start_date' => $start, 'm_first_payment' => $billing], AgreementTakeOn::values($month));
    }

    public function test_this_month_is_allowed_a_past_month_and_junk_are_not(): void
    {
        $now = Carbon::parse('2026-10-17 10:00');
        $this->assertTrue(AgreementTakeOn::valid('2026-10', $now));
        $this->assertTrue(AgreementTakeOn::valid('2026-11', $now));
        $this->assertTrue(AgreementTakeOn::valid('2027-12', $now));
        $this->assertFalse(AgreementTakeOn::valid('2026-09', $now));
        $this->assertFalse(AgreementTakeOn::valid('2025-12', $now));
        foreach (['', '2026-13', '2026-00', '2026-1', 'October 2026', '2026-10-01', null] as $bad) {
            $this->assertFalse(AgreementTakeOn::valid($bad, $now), json_encode($bad));
        }
        $o = AgreementTakeOn::options(18, $now);
        $this->assertCount(18, $o);
        $this->assertSame('2026-10', $o[0]['value']);
        $this->assertSame('1 October 2026', $o[0]['start']);
        $this->assertSame('1 November 2026', $o[0]['billing']);
        $this->assertSame('2028-03', $o[17]['value']);
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);
    }

    private function sent(string $month = '2026-12'): array
    {
        Carbon::setTestNow('2026-10-17 10:00:00');
        Mail::fake();
        $doc = app(AgreementService::class)->send(['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'take_on_month' => $month], $this->owner()->id);

        return [$doc, Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token')];
    }

    private function save(string $token, array $values, int $rev = 0)
    {
        return $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => $rev, 'values' => $values]);
    }

    public function test_sending_stores_and_audits_the_take_on_month_and_pre_sets_both_dates(): void
    {
        [$doc] = $this->sent('2026-12');
        $this->assertSame('2026-12', $doc->rr_data['take_on_month']);
        $this->assertSame('2026-12-01', $doc->form_data['start_date']);
        $this->assertSame('2027-01-01', $doc->form_data['m_first_payment']);
        $e = $doc->events()->where('event', 'take_on_set')->firstOrFail();
        $this->assertStringContainsString('December 2026', $e->detail);
        $this->assertStringContainsString('1 December 2026', $e->detail);
        $this->assertStringContainsString('1 January 2027', $e->detail);
    }

    public function test_a_past_take_on_month_cannot_be_sent(): void
    {
        Carbon::setTestNow('2026-10-17 10:00:00');
        $this->expectException(\DomainException::class);
        app(AgreementService::class)->send(['name' => 'Pat', 'email' => 'pat@caprivi.test', 'take_on_month' => '2026-09'], $this->owner()->id);
    }

    public function test_the_send_form_requires_the_month_defaults_to_this_month_and_shows_both_dates(): void
    {
        Carbon::setTestNow('2026-10-17 10:00:00');
        $owner = $this->owner();
        $html = $this->actingAs($owner)->get(route('platform-esign.agreements.create'))->assertOk()->getContent();
        $this->assertStringContainsString('name="take_on_month"', $html);
        $this->assertMatchesRegularExpression('/<option value="2026-10" data-start="1 October 2026" data-billing="1 November 2026" selected>October 2026/', $html);
        $this->assertStringContainsString('id="take-on-dates"', $html);
        $this->assertStringNotContainsString('value="2026-09"', $html);

        Mail::fake();
        $base = ['name' => 'Pat Principal', 'email' => 'pat@caprivi.test'];
        $this->actingAs($owner)->post(route('platform-esign.agreements.store'), $base)->assertSessionHasErrors('take_on_month');
        $this->actingAs($owner)->post(route('platform-esign.agreements.store'), $base + ['take_on_month' => '2026-09'])->assertSessionHasErrors('take_on_month');
        $this->actingAs($owner)->post(route('platform-esign.agreements.store'), $base + ['take_on_month' => 'rubbish'])->assertSessionHasErrors('take_on_month');
        $this->assertSame(0, Document::count());
        $this->actingAs($owner)->post(route('platform-esign.agreements.store'), $base + ['take_on_month' => '2026-11'])->assertRedirect();
        $this->assertSame('2026-11', Document::firstOrFail()->rr_data['take_on_month']);
    }

    public function test_the_recipient_sees_both_dates_read_only_with_the_tip_and_cannot_change_them(): void
    {
        [$doc, $token] = $this->sent('2026-12');
        $this->save($token, ['start_date' => '2030-05-05', 'm_first_payment' => '2030-06-06', 'registered_name' => 'Caprivi'])->assertOk();
        $d = $doc->fresh();
        $this->assertSame('2026-12-01', $d->form_data['start_date']);
        $this->assertSame('2027-01-01', $d->form_data['m_first_payment']);
        $this->assertSame('Caprivi', $d->form_data['registered_name']);

        $html = $this->get(route('platform-esign.agreement.show', $token))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/value="1 December 2026" readonly tabindex="-1" data-derived="1"/', $html);
        $this->assertMatchesRegularExpression('/value="1 January 2027" readonly tabindex="-1" data-derived="1"/', $html);
        $this->assertSame(2, substr_count($html, 'Set by CoreX as agreed for your take-on month.'));
        $this->assertStringNotContainsString('name="start_date"', $html, 'no typeable start date');
        $this->assertStringNotContainsString('name="m_first_payment"', $html, 'no typeable first payment date');
    }

    public function test_the_wet_ink_pdf_and_the_review_carry_the_dates_but_never_the_tip(): void
    {
        [$doc] = $this->sent('2026-12');
        $svc = app(AgreementService::class);
        $doc = Document::findOrFail($doc->id);
        $ctx = $svc->context($doc);
        $this->assertSame('2026-12-01', $ctx['values']['start_date']);
        $renderer = app(\App\Services\PlatformEsign\Agreement\AgreementRenderer::class);
        foreach (['wet', 'pdf', 'rr', 'preview'] as $mode) {
            $a = html_entity_decode(strip_tags(implode("\n", $renderer->blocks($doc->wording, 'part_a', $mode, $ctx))));
            $m = html_entity_decode(strip_tags(implode("\n", $renderer->blocks($doc->wording, 'mandate', $mode, $ctx))));
            $this->assertMatchesRegularExpression('/Start date\s+1 December 2026/', $a, $mode);
            $this->assertMatchesRegularExpression('/\(date\)|1 January 2027/', $m, $mode);
            $this->assertStringContainsString('1 January 2027', $m, $mode);
            $this->assertStringNotContainsString('Set by CoreX', $a . $m, $mode);
        }
        $layout = app(AgreementLayout::class)->ensure($doc->wording);
        $text = AgreementFidelity::pdfText($svc->wetCopy($doc, $svc->agencySigner($doc), null), (int) $layout['total']);
        $this->assertStringContainsString('1 December 2026', $text);
        $this->assertStringContainsString('1 January 2027', $text);
        $this->assertStringNotContainsString('Set by CoreX', $text);
    }

    public function test_an_agreement_sent_without_a_take_on_month_keeps_its_own_typeable_dates(): void
    {
        Carbon::setTestNow('2026-10-17 10:00:00');
        Mail::fake();
        $doc = app(AgreementService::class)->send(['name' => 'Pat Principal', 'email' => 'pat@caprivi.test'], $this->owner()->id);
        $token = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token');
        $this->save($token, ['start_date' => '2026-11-15', 'm_first_payment' => '2026-12-15'])->assertOk();
        $this->assertSame('2026-11-15', $doc->fresh()->form_data['start_date']);
        $this->assertSame('2026-12-15', $doc->fresh()->form_data['m_first_payment']);
        $html = $this->get(route('platform-esign.agreement.show', $token))->getContent();
        $this->assertStringContainsString('name="start_date"', $html);
        $this->assertStringNotContainsString('Set by CoreX', $html);
    }
}
