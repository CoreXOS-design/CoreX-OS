<?php

declare(strict_types=1);

namespace Tests\Feature\RentalNotices;

use App\Models\Lease;
use App\Models\LeaseBreach;
use App\Models\LeaseSetting;
use App\Models\RentalNoticeTemplate;
use App\Services\Rentals\RentalNoticeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\LeaseNotices\BuildsNoticeFixture;
use Tests\TestCase;

/**
 * AT-462 - the agency's own breach notice / notice to vacate documents: the letterhead comes from the agency record, a template carries a
 * default clause block the agent edits per notice, the remedy window can count calendar days, and a notice to vacate captures its date and
 * time and quotes the breach notices on file. Everything is the fixture agency's (Karoo Lettings) - no real agency's wording anywhere.
 */
final class AgencyNoticeDocumentsTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    private const CLAUSES = "Clause 4.1 - rent is payable on the 1st of each month.\nClause 16.1 - a breach not remedied within 7 days of written notice may be cancelled.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        Storage::fake('local');
        $this->withoutVite();
    }

    private function breachTemplate(string $type = 'breach_other'): RentalNoticeTemplate
    {
        return RentalNoticeTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Our breach ' . $type, 'notice_type' => $type, 'is_active' => true, 'default_clause' => self::CLAUSES,
            'body_html' => '<h2>Notice of breach</h2><p>Attention: {{property_address}} - {{tenant_name}}</p><p>Breach: {{reason}}</p><p>{{clause}}</p>'
                . '<p>Pay by {{remedy_deadline}} ({{remedy_days}} days) and send proof to {{agency_email}}. Kind regards, {{agent_name}}, {{agency_name}}</p>',
        ]);
    }

    private function vacateTemplate(): RentalNoticeTemplate
    {
        return RentalNoticeTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Our vacate', 'notice_type' => 'notice_to_vacate', 'is_active' => true,
            'body_html' => '<p>{{tenant_name}}<br>{{property_address}}<br>{{tenant_email}}</p><p>Breaches: {{breach_summary}}.</p><p>Be out by {{vacate_time}} on {{vacate_date}}.</p>',
        ]);
    }

    private function oneOff(array $over = [])
    {
        return $this->actingAs($this->agent)->post(route('corex.leases.breaches.store', $this->lease), $over + [
            'breach_party' => 'tenant', 'clause' => self::CLAUSES, 'reason' => 'Pool not maintained - clause 12.3',
            'rental_notice_template_id' => RentalNoticeTemplate::withoutGlobalScopes()->where('notice_type', 'breach_other')->value('id'), 'intent' => 'send',
        ]);
    }

    private function pastDeadline(LeaseBreach $breach): void
    {
        $breach->forceFill(['remedy_deadline' => Carbon::today()->subDay()->toDateString()])->save();
    }

    // ── letterhead ─────────────────────────────────────────────────────────

    public function test_the_notice_pdf_letterhead_is_read_from_the_agency_record_and_leaves_out_what_is_not_filled_in(): void
    {
        $this->agency->forceFill(['reg_no' => '2001/123456/07', 'ffc_no' => 'FFC-998877', 'vat_no' => '4000111222', 'vat_registered' => true, 'phone_secondary' => '049 555 0202'])->save();
        $data = fn ($agency) => ['notice' => null, 'bodyHtml' => '<p>Body</p>', 'logo' => null, 'agencyName' => $agency->name, 'agency' => $agency];

        $html = view('corex.rental-notices.pdf', $data($this->agency->fresh()))->render();

        foreach (['Karoo Lettings', '3 Church Street, Graaff-Reinet', '049 555 0101', '049 555 0202', 'desk@karoo.test', 'Reg. no. 2001/123456/07', 'FFC FFC-998877', 'VAT 4000111222'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->assertStringContainsString('data-letterhead', $html);

        $bare = \App\Models\Agency::create(['name' => 'Bare Agency', 'slug' => 'bare-' . uniqid()]);
        $bareHtml = view('corex.rental-notices.pdf', $data($bare))->render();
        $this->assertStringNotContainsString('FFC', $bareHtml);
        $this->assertStringNotContainsString('Reg. no.', $bareHtml);
        $this->assertStringContainsString('Bare Agency', $bareHtml);
        $this->assertStringNotContainsString('Karoo', $bareHtml, 'no other agency\'s details leak in');
    }

    public function test_the_letterhead_vat_number_is_only_printed_for_a_vat_registered_agency(): void
    {
        $this->agency->forceFill(['vat_no' => '4000111222', 'vat_registered' => false])->save();

        $html = view('corex.rental-notices.pdf', ['notice' => null, 'bodyHtml' => '', 'logo' => null, 'agencyName' => 'Karoo Lettings', 'agency' => $this->agency->fresh()])->render();

        $this->assertStringNotContainsString('4000111222', $html);
    }

    // ── clause block + remedy window ───────────────────────────────────────

    public function test_the_one_off_form_starts_from_the_templates_clause_block(): void
    {
        $this->breachTemplate();

        $html = $this->actingAs($this->agent)->get(route('corex.leases.breaches.create', $this->lease))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="breach-clause"', $html);
        $this->assertStringContainsString('Clause 16.1 - a breach not remedied within 7 days', $html);
        $this->assertStringContainsString('data-default-clause=', $html);
    }

    public function test_a_payment_breach_with_no_typed_clause_prints_the_templates_default_clause_with_line_breaks(): void
    {
        $tpl = $this->breachTemplate('breach_payment');
        $this->actingAs($this->agent)->post(route('corex.breaches.bulk.store'), ['rental_notice_template_id' => $tpl->id, 'amount' => [$this->lease->id => '9500'], 'figures_raw' => 'reason=Rent unpaid'])->assertOk();
        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();

        $html = app(RentalNoticeService::class)->render($tpl, ['reason' => 'Rent unpaid'], $this->lease, $breach, $this->agent);

        $this->assertStringContainsString("Clause 4.1 - rent is payable on the 1st of each month.<br>\nClause 16.1", $html);
        $this->assertSame([], app(RentalNoticeService::class)->unfilled($html), 'nothing left empty');
    }

    public function test_the_agents_typed_clause_replaces_the_default_and_the_notice_carries_agency_email_and_agent(): void
    {
        $this->breachTemplate();

        $this->oneOff(['clause' => 'Clause 12.3 - the tenant keeps the pool clean.'])->assertSessionHasNoErrors();
        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $html = app(RentalNoticeService::class)->render(RentalNoticeTemplate::withoutGlobalScopes()->firstOrFail(), [], $this->lease, $breach, $this->agent);

        $this->assertStringContainsString('Clause 12.3 - the tenant keeps the pool clean.', $html);
        $this->assertStringNotContainsString('Clause 4.1', $html);
        $this->assertStringContainsString('Pool not maintained - clause 12.3', $html);
        $this->assertStringContainsString('desk@karoo.test', $html);
        $this->assertStringContainsString('Anja Agent', $html);
        $this->assertStringContainsString('4 Vlei Court', $html);
    }

    public function test_the_remedy_window_can_count_calendar_days_and_the_breach_remembers_which(): void
    {
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['breach_remedy_business_days' => 7, 'breach_remedy_day_basis' => 'calendar']);
        $this->breachTemplate();

        $this->oneOff()->assertSessionHasNoErrors();

        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('calendar', $breach->remedy_day_basis);
        $this->assertSame(Carbon::today()->addDays(7)->toDateString(), $breach->remedy_deadline->toDateString());
        $this->assertSame('7 calendar days', $breach->remedyWindowLabel());
        $show = $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->assertOk()->getContent();
        $this->assertStringContainsString('7 calendar days', $show);
        $this->assertStringContainsString('7 calendar days are allowed', $this->actingAs($this->agent)->get(route('corex.leases.breaches.create', $this->lease))->getContent());
    }

    public function test_the_default_remedy_window_is_still_twenty_business_days(): void
    {
        $this->breachTemplate();

        $this->oneOff()->assertSessionHasNoErrors();

        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('business', $breach->remedy_day_basis);
        $this->assertSame('20 business days', $breach->remedyWindowLabel());
    }

    // ── notice to vacate ───────────────────────────────────────────────────

    public function test_the_vacate_form_captures_date_and_time_and_starts_from_the_breaches_on_file(): void
    {
        $this->breachTemplate();
        $this->vacateTemplate();
        $this->oneOff()->assertSessionHasNoErrors();
        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $this->pastDeadline($breach);

        $html = $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="vacate-date"', $html);
        $this->assertStringContainsString('data-qa="vacate-time"', $html);
        $this->assertStringContainsString('value="12:00"', $html, 'the agency\'s default time');
        $this->assertStringContainsString(Carbon::today()->format('j F Y') . ' - Pool not maintained - clause 12.3', $html);
    }

    public function test_the_vacate_time_default_is_the_agencys_setting(): void
    {
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['vacate_time' => '10:30']);
        $this->breachTemplate();
        $this->vacateTemplate();
        $this->oneOff();
        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $this->pastDeadline($breach);

        $this->assertStringContainsString('value="10:30"', $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->getContent());
        $this->assertSame('10:30', LeaseSetting::vacateTimeFor($this->agency->id));
        $this->assertSame('12:00', LeaseSetting::vacateTimeFor(null), 'the neutral default');
    }

    public function test_the_vacate_preview_prints_the_date_the_time_the_tenants_email_and_the_breach_summary(): void
    {
        $this->breachTemplate();
        $vacate = $this->vacateTemplate();
        $this->oneOff();
        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $this->pastDeadline($breach);

        $this->actingAs($this->agent)->post(route('corex.breaches.vacate', $breach->id), [
            'rental_notice_template_id' => $vacate->id, 'intent' => 'preview', 'vacate_date' => '2026-11-30', 'vacate_time' => '12:00',
            'breach_summary' => Carbon::today()->format('j F Y') . ' - Pool not maintained - clause 12.3',
        ])->assertSessionHasNoErrors();

        $html = session('breach_preview')['html'];
        $this->assertStringContainsString('Be out by 12h00 on 30 November 2026.', $html);
        $this->assertStringContainsString('Pool not maintained - clause 12.3', $html);
        $this->assertStringContainsString($this->tenant->email, $html);
        $this->assertStringContainsString('4 Vlei Court', $html);
        $this->assertSame([], session('breach_preview')['missing']);
    }

    public function test_a_notice_to_vacate_needs_a_date_that_is_not_in_the_past_and_files_date_and_time_on_the_breach(): void
    {
        $this->breachTemplate();
        $vacate = $this->vacateTemplate();
        $this->oneOff();
        $breach = LeaseBreach::withoutGlobalScopes()->firstOrFail();
        $this->pastDeadline($breach);
        $post = fn (array $over) => $this->actingAs($this->agent)->post(route('corex.breaches.vacate', $breach->id), $over + ['rental_notice_template_id' => $vacate->id, 'intent' => 'send']);

        $post([])->assertSessionHasErrors('vacate_date');
        $post(['vacate_date' => Carbon::yesterday()->toDateString()])->assertSessionHasErrors('vacate_date');
        $this->assertSame('issued', $breach->fresh()->status, 'nothing was sent');

        $post(['vacate_date' => '2026-11-30', 'vacate_time' => '12:00'])->assertSessionHasNoErrors();

        $fresh = $breach->fresh();
        $this->assertSame('escalated', $fresh->status);
        $this->assertSame('2026-11-30', $fresh->vacate_date->toDateString());
        $this->assertSame('12:00', $fresh->vacate_time);
        $this->assertSame('12h00', LeaseBreach::vacateTimeText($fresh->vacate_time));
        $this->assertNotNull($fresh->vacateNotice->document_id);
        $this->assertStringContainsString('tenant to be out by 12h00 on 30 November 2026', $this->actingAs($this->agent)->get(route('corex.breaches.show', $breach->id))->getContent());
        $panel = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->getContent();
        $this->assertStringContainsString('data-qa="vacate-pdf-link"', $panel);
        $this->assertStringContainsString('data-qa="breach-pdf-link"', $panel);
    }

    public function test_notice_to_vacate_is_not_offered_where_no_breach_notice_has_been_issued(): void
    {
        $this->vacateTemplate();

        // the lease with no breach: no breach panel, nothing to open, no vacate form anywhere
        $lease = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-qa="lease-breach-panel"', $lease);
        $this->assertStringNotContainsString('data-qa="vacate-form"', $lease);
        $this->assertSame(0, LeaseBreach::withoutGlobalScopes()->where('lease_id', $this->lease->id)->count());

        // and there is no way to reach the vacate action without a breach id that exists
        $this->actingAs($this->agent)->post(route('corex.breaches.vacate', 999999), ['rental_notice_template_id' => 1, 'intent' => 'send', 'vacate_date' => '2026-11-30'])->assertNotFound();
        $this->assertFalse((new LeaseBreach(['status' => 'issued', 'breach_party' => 'tenant']))->canIssueVacate(), 'a breach with no deadline is never ready');
    }

    // ── settings + wizard ──────────────────────────────────────────────────

    public function test_the_two_new_settings_save_from_the_settings_page_and_an_absent_key_leaves_them_alone(): void
    {
        $admin = $this->agent;
        $this->grant($admin, ['leases.manage_settings']);

        $this->actingAs($admin)->post(route('corex.settings.leases.update'), [
            'expiry_notice_window_days' => 60, 'breach_remedy_business_days' => 7, 'breach_remedy_day_basis' => 'calendar', 'vacate_time' => '11:15',
        ])->assertSessionHasNoErrors();
        $this->assertSame('calendar', LeaseSetting::breachRemedyDayBasisFor($this->agency->id));
        $this->assertSame('11:15', LeaseSetting::vacateTimeFor($this->agency->id));
        $this->assertSame(7, LeaseSetting::breachRemedyBusinessDaysFor($this->agency->id));

        // a wizard step that never rendered them posts neither - they must survive
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 45])->assertSessionHasNoErrors();
        $this->assertSame('calendar', LeaseSetting::breachRemedyDayBasisFor($this->agency->id));
        $this->assertSame('11:15', LeaseSetting::vacateTimeFor($this->agency->id));

        // blank = back to the neutral defaults; rubbish is refused
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 45, 'breach_remedy_day_basis' => '', 'vacate_time' => ''])->assertSessionHasNoErrors();
        $this->assertSame('business', LeaseSetting::breachRemedyDayBasisFor($this->agency->id));
        $this->assertSame('12:00', LeaseSetting::vacateTimeFor($this->agency->id));
        $this->actingAs($admin)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 45, 'vacate_time' => '25:99'])->assertSessionHasErrors('vacate_time');
    }

    public function test_both_new_settings_are_in_the_setup_wizard_copy_with_an_explanation_and_a_consequence(): void
    {
        $found = [];
        $walk = function ($node) use (&$walk, &$found) {
            if (is_array($node)) {
                if (isset($node['key'], $node['explain'], $node['affects'])) {
                    $found[$node['key']] = $node;
                }
                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };
        $walk(config('agency-onboarding-copy'));

        foreach (['breach_remedy_day_basis', 'vacate_time'] as $key) {
            $this->assertArrayHasKey($key, $found, $key . ' is surfaced in the wizard');
            $this->assertSame('leases', $found[$key]['source']);
            $this->assertGreaterThan(40, strlen($found[$key]['explain']), $key . ' explains itself');
            $this->assertGreaterThan(40, strlen($found[$key]['affects']), $key . ' says what it changes');
        }
    }

    public function test_the_template_form_has_a_default_clause_block_that_saves(): void
    {
        $this->actingAs($this->agent)->post(route('corex.rental-notice-templates.store'), [
            'name' => 'Our breach', 'notice_type' => 'breach_other', 'body_html' => '<p>{{clause}}</p>', 'default_clause' => self::CLAUSES,
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::CLAUSES, RentalNoticeTemplate::withoutGlobalScopes()->firstOrFail()->default_clause);
        $this->assertStringContainsString('data-qa="template-default-clause"', $this->actingAs($this->agent)->get(route('corex.rental-notice-templates.create'))->getContent());
    }
}
