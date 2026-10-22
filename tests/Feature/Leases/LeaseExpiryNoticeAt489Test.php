<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Mail\Rentals\RentalNoticeMail;
use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseSetting;
use App\Models\RentalNoticeTemplate;
use App\Models\RentalSettingAuditEntry;
use App\Services\Rentals\BusinessDayService;
use App\Services\Rentals\LeaseExpiryNoticeService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\LeaseNotices\BuildsNoticeFixture;
use Tests\TestCase;

/**
 * AT-489 - the agency's EXPIRY NOTICE to the tenant, on Consumer Protection Act law: due between end-80 and end-40 business days (CPA s2(6)
 * counting, SA public holidays), CoreX sends it on the agency's send day (40-80, default 60), alerts the lease agents at the alert day
 * (default 45, between 40 and the send day), and flags the lease overdue inside 40. Spec: .ai/specs/lease-notices.md section 10.
 */
final class LeaseExpiryNoticeAt489Test extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    /** Every South African public holiday between Sep 2026 and Apr 2027, written out by hand (Easter 2027 = 28 Mar). */
    private const HOLIDAYS = ['2026-09-24', '2026-12-16', '2026-12-25', '2027-01-01', '2027-03-22', '2027-03-26', '2027-03-29', '2027-04-27'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        BusinessDayService::flush();
        $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
        $this->buildNoticeFixture();
        $this->lease->forceFill(['start_date' => '2026-06-01', 'end_date' => '2027-03-31'])->save();
        $this->grant($this->agent, ['rental_notices.review', 'rental_notices.view']);
    }

    /** An independent count: business days in (from, to], using only the hand-written holidays above. */
    private function businessDaysAfter(string $from, string $to): int
    {
        $n = 0;
        for ($d = Carbon::parse($from)->addDay(); $d->lte(Carbon::parse($to)); $d->addDay()) {
            if (! $d->isWeekend() && ! in_array($d->toDateString(), self::HOLIDAYS, true)) {
                $n++;
            }
        }

        return $n;
    }

    private function service(): LeaseExpiryNoticeService
    {
        return app(LeaseExpiryNoticeService::class);
    }

    /** Notices mailed (AT-495 sends through the agent's mailbox path, which may send or queue). */
    private function tenantMailCount(): int
    {
        return Mail::sent(RentalNoticeMail::class)->count() + Mail::queued(RentalNoticeMail::class)->count();
    }

    private function template(): RentalNoticeTemplate
    {
        return RentalNoticeTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Our expiry notice', 'notice_type' => 'expiry_notice', 'is_active' => true,
            'body_html' => '<p>Dear {{tenant_name}}, your lease at {{property_address}} ends soon. {{agency_name}}</p>',
        ]);
    }

    // ── the counting (CPA s2(6), SA public holidays) ───────────────────────────────

    public function test_n_business_days_before_an_end_date_has_exactly_n_business_days_after_it_across_christmas_and_new_year(): void
    {
        $days = app(BusinessDayService::class);
        foreach (['2027-03-31', '2027-03-30', '2027-04-03' /* a Saturday */, '2027-03-28' /* a Sunday, Easter */, '2027-01-05', '2027-02-26'] as $end) {
            foreach ([80, 60, 45, 40] as $n) {
                $d = $days->daysBefore(Carbon::parse($end), $n);
                $this->assertFalse($d->isWeekend(), "the date for $n before $end is a business day");
                $this->assertSame($n, $this->businessDaysAfter($d->toDateString(), $end), "$n business days after {$d->toDateString()} up to and including $end");
            }
        }
    }

    public function test_the_christmas_new_year_window_is_pinned(): void
    {
        $days = app(BusinessDayService::class);

        // 31 Mar 2027 (a Wednesday): 80 business days back crosses 16 Dec, 25 Dec, 1 Jan, Human Rights Day (Mon 22 Mar) and Good Friday (26 Mar)
        $this->assertSame('2026-12-01', $days->daysBefore(Carbon::parse('2027-03-31'), 80)->toDateString());
        $this->assertSame('2027-01-29', $days->daysBefore(Carbon::parse('2027-03-31'), 40)->toDateString());
        // a short hop over the break: 5 business days before Tue 5 Jan 2027 is Mon 28 Dec 2026 (1 Jan is a holiday, 2/3 Jan a weekend)
        $this->assertSame('2026-12-28', $days->subtract(Carbon::parse('2027-01-05'), 5)->toDateString());
    }

    public function test_a_holiday_on_a_sunday_makes_the_monday_a_holiday_and_when_that_is_a_holiday_too_the_tuesday(): void
    {
        $days = app(BusinessDayService::class);

        // 2027: Day of Goodwill is Sunday 26 Dec -> Monday 27 Dec is a holiday: one business day before Tue 28 Dec is Fri 24 Dec
        $this->assertSame('2027-12-24', $days->subtract(Carbon::parse('2027-12-28'), 1)->toDateString());
        // 2022: Christmas Day is a Sunday and Monday 26 Dec is already Day of Goodwill, so Tuesday 27 Dec is the extra day
        $this->assertSame('2022-12-23', $days->subtract(Carbon::parse('2022-12-28'), 1)->toDateString());
        // 2023: New Year's Day is a Sunday -> Monday 2 Jan
        $this->assertSame('2022-12-28', $days->subtract(Carbon::parse('2023-01-03'), 3)->toDateString());
    }

    public function test_count_between_excludes_the_first_day_and_includes_the_last(): void
    {
        $days = app(BusinessDayService::class);

        $this->assertSame(1, $days->countBetween(Carbon::parse('2027-01-04'), Carbon::parse('2027-01-05')));
        $this->assertSame(0, $days->countBetween(Carbon::parse('2027-01-05'), Carbon::parse('2027-01-05')));
        $this->assertSame($this->businessDaysAfter('2026-10-21', '2027-03-31'), $days->countBetween(Carbon::parse('2026-10-21'), Carbon::parse('2027-03-31')));
    }

    // ── the settings (40-80, default 60; alert default 45, between 40 and the send day) ───────

    public function test_the_settings_default_to_60_and_45_and_are_held_inside_the_law(): void
    {
        $this->assertSame(60, LeaseSetting::expiryNoticeSendBusinessDaysFor($this->agency->id));
        $this->assertSame(45, LeaseSetting::expiryNoticeAlertBusinessDaysFor($this->agency->id));

        LeaseSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], ['expiry_notice_send_business_days' => 70, 'expiry_notice_alert_business_days' => 41]);
        $this->assertSame([70, 41], [LeaseSetting::expiryNoticeSendBusinessDaysFor($this->agency->id), LeaseSetting::expiryNoticeAlertBusinessDaysFor($this->agency->id)]);

        // a figure outside the window that got into the table anyway is held to it
        LeaseSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], ['expiry_notice_send_business_days' => 200, 'expiry_notice_alert_business_days' => 10]);
        $this->assertSame([80, 40], [LeaseSetting::expiryNoticeSendBusinessDaysFor($this->agency->id), LeaseSetting::expiryNoticeAlertBusinessDaysFor($this->agency->id)]);
    }

    public function test_the_settings_page_validates_saves_and_audits(): void
    {
        $post = fn (array $d) => $this->actingAs($this->agent)->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 60] + $d);
        $this->grant($this->agent, ['leases.manage_settings']);

        foreach ([['expiry_notice_send_business_days' => 39], ['expiry_notice_send_business_days' => 81], ['expiry_notice_alert_business_days' => 39]] as $bad) {
            $post($bad)->assertSessionHasErrors(array_key_first($bad));
        }
        // the alert day cannot be later than the send day
        $post(['expiry_notice_send_business_days' => 50, 'expiry_notice_alert_business_days' => 55])->assertSessionHasErrors('expiry_notice_alert_business_days');
        $post(['expiry_notice_send_business_days' => 41, 'expiry_notice_alert_business_days' => 45])->assertSessionHasErrors('expiry_notice_alert_business_days');
        $this->assertNull(LeaseSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->value('expiry_notice_send_business_days'));

        $post(['expiry_notice_send_business_days' => 70, 'expiry_notice_alert_business_days' => 50])->assertSessionHasNoErrors();
        $this->assertSame([70, 50], [LeaseSetting::expiryNoticeSendBusinessDaysFor($this->agency->id), LeaseSetting::expiryNoticeAlertBusinessDaysFor($this->agency->id)]);
        $rows = RentalSettingAuditEntry::where('agency_id', $this->agency->id)->whereIn('setting_key', ['expiry_notice_send_business_days', 'expiry_notice_alert_business_days'])->orderBy('id')->get();
        $this->assertSame([['expiry_notice_send_business_days', '60', '70'], ['expiry_notice_alert_business_days', '45', '50']], $rows->map(fn ($r) => [$r->setting_key, $r->old_value, $r->new_value])->all());

        // blank = back to the defaults
        $post(['expiry_notice_send_business_days' => '', 'expiry_notice_alert_business_days' => ''])->assertSessionHasNoErrors();
        $this->assertSame([60, 45], [LeaseSetting::expiryNoticeSendBusinessDaysFor($this->agency->id), LeaseSetting::expiryNoticeAlertBusinessDaysFor($this->agency->id)]);

        $html = $this->actingAs($this->agent)->get(route('corex.settings.leases.edit'))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="expiry-notice-send"', $html);
        $this->assertStringContainsString('data-qa="expiry-notice-alert"', $html);
        $this->assertStringContainsString('data-qa="expiry-template-missing"', $html, 'no template yet: the page says CoreX will alert instead of send');
    }

    public function test_the_old_months_setting_is_converted_to_business_days_and_audit_logged(): void
    {
        $other = \App\Models\Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()]);
        $third = \App\Models\Agency::create(['name' => 'Third', 'slug' => 't-' . uniqid()]);
        LeaseSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], ['default_earliest_notice_months' => 3]);
        LeaseSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $other->id], ['default_earliest_notice_months' => 1]);
        LeaseSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $third->id], ['default_earliest_notice_months' => 6]);

        (require base_path('database/migrations/2026_10_21_110000_expiry_notice_settings_and_lease_tracking.php'))->up();

        $this->assertSame(65, LeaseSetting::expiryNoticeSendBusinessDaysFor($this->agency->id), '3 months = 65 business days');
        $this->assertSame(40, LeaseSetting::expiryNoticeSendBusinessDaysFor($other->id), '1 month = 22, held to the 40 minimum');
        $this->assertSame(80, LeaseSetting::expiryNoticeSendBusinessDaysFor($third->id), '6 months = 130, held to the 80 maximum');
        $this->assertNull(LeaseSetting::withoutGlobalScopes()->where('agency_id', $this->agency->id)->value('default_earliest_notice_months'), 'the old value is cleared');
        $audit = RentalSettingAuditEntry::where('agency_id', $this->agency->id)->where('source', 'migration')->orderBy('id')->get();
        $this->assertSame([['expiry_notice_send_business_days', null, '65'], ['default_earliest_notice_months', '3', null]], $audit->map(fn ($r) => [$r->setting_key, $r->old_value, $r->new_value])->all());
    }

    // ── per lease: the dates and the state ───────────────────────────────────────

    public function test_the_lease_dates_and_where_it_stands(): void
    {
        $dates = $this->service()->dates($this->agency->id, Carbon::parse('2027-03-31'));
        $this->assertSame(['2026-12-01', '2027-01-29'], [$dates['from'], $dates['to']]);
        $this->assertSame($this->businessDaysAfter($dates['send_on'], '2027-03-31'), 60, 'CoreX sends it on end - 60 business days');
        $this->assertSame(80, $dates['max']);
        $this->assertSame(40, $dates['min']);

        $state = fn (string $today) => $this->service()->status($this->lease->fresh(), Carbon::parse($today))['state'];
        $this->assertSame('not_due', $state('2026-10-21'));                       // about 110 business days left
        $this->assertSame('due', $state($dates['send_on']));                      // 60 left: the send day
        $this->assertSame('alert', $state(app(BusinessDayService::class)->daysBefore(Carbon::parse('2027-03-31'), 45)->toDateString())); // 45 left: the alert day
        $this->assertSame('overdue', $state('2027-02-05'));                       // fewer than 40 left

        $this->lease->forceFill(['expiry_notice_sent_at' => now()])->save();
        $this->assertSame('sent', $state('2027-02-05'));

        $m2m = $this->lease->fresh();
        $m2m->forceFill(['expiry_notice_sent_at' => null, 'is_month_to_month' => true])->save();
        $this->assertSame('none', $state('2027-02-05'), 'month-to-month: nothing expires, so no expiry notice');
    }

    public function test_the_states_follow_the_agencys_alert_day_exactly(): void
    {
        $days = app(BusinessDayService::class);
        $stateWhenRemaining = fn (int $n) => $this->service()->status($this->lease->fresh(), $days->daysBefore(Carbon::parse('2027-03-31'), $n))['state'];

        $this->assertSame('not_due', $stateWhenRemaining(61));
        $this->assertSame('due', $stateWhenRemaining(60));
        $this->assertSame('due', $stateWhenRemaining(46));
        $this->assertSame('alert', $stateWhenRemaining(45));
        $this->assertSame('alert', $stateWhenRemaining(40));
        $this->assertSame('overdue', $stateWhenRemaining(39));

        LeaseSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], ['expiry_notice_send_business_days' => 70, 'expiry_notice_alert_business_days' => 55]);
        $this->assertSame('due', $stateWhenRemaining(70));
        $this->assertSame('alert', $stateWhenRemaining(55));
    }

    // ── the daily send and the alerts ────────────────────────────────────────────

    public function test_on_the_send_day_corex_sends_it_in_the_agencys_template_from_the_lease_agent_and_records_it(): void
    {
        $this->template();
        $sendOn = $this->service()->dates($this->agency->id, Carbon::parse('2027-03-31'))['send_on'];

        $this->travelTo(Carbon::parse($sendOn . ' 06:30:00'));
        Artisan::call('leases:expiry-notices', ['--lease' => $this->lease->id]);

        $this->assertSame(1, $this->tenantMailCount(), 'one notice, sent from the lease agent\'s mailbox path');
        $lease = $this->lease->fresh();
        $this->assertNotNull($lease->expiry_notice_sent_at);
        $this->assertSame('corex', $lease->expiry_notice_sent_via);
        $this->assertTrue(LeaseEvent::where('lease_id', $lease->id)->where('event_type', LeaseEvent::TYPE_EXPIRY_NOTICE_SENT)->exists());

        // idempotent: the next run sends nothing more
        Artisan::call('leases:expiry-notices', ['--lease' => $this->lease->id]);
        $this->assertSame(1, $this->tenantMailCount(), 'one notice, sent from the lease agent\'s mailbox path');
    }

    public function test_before_the_send_day_nothing_goes_and_a_dry_run_changes_nothing(): void
    {
        $this->template();
        Artisan::call('leases:expiry-notices', ['--lease' => $this->lease->id]);
        Mail::assertNothingSent();

        $sendOn = $this->service()->dates($this->agency->id, Carbon::parse('2027-03-31'))['send_on'];
        $this->travelTo(Carbon::parse($sendOn . ' 06:30:00'));
        Artisan::call('leases:expiry-notices', ['--lease' => $this->lease->id, '--dry-run' => true]);
        Mail::assertNothingSent();
        $this->assertNull($this->lease->fresh()->expiry_notice_sent_at);
    }

    public function test_with_no_template_corex_cannot_send_so_it_alerts_the_agents_once_at_the_alert_day(): void
    {
        $days = app(BusinessDayService::class);

        // on the send day: nothing to send with, but not yet the alert day - no alert
        $this->travelTo($days->daysBefore(Carbon::parse('2027-03-31'), 60)->setTime(6, 30));
        Artisan::call('leases:expiry-notices', ['--lease' => $this->lease->id]);
        $this->assertNull($this->lease->fresh()->expiry_notice_alerted_at);
        Mail::assertNothingSent();

        $this->travelTo($days->daysBefore(Carbon::parse('2027-03-31'), 45)->setTime(6, 30));
        Artisan::call('leases:expiry-notices', ['--lease' => $this->lease->id]);
        $alertedAt = $this->lease->fresh()->expiry_notice_alerted_at;
        $this->assertNotNull($alertedAt);
        $this->assertNull($this->lease->fresh()->expiry_notice_sent_at);
        $this->assertTrue(LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_EXPIRY_NOTICE_ALERTED)->exists());

        // once: the next day does not alert again
        $this->travelTo($days->daysBefore(Carbon::parse('2027-03-31'), 44)->setTime(6, 30));
        Artisan::call('leases:expiry-notices', ['--lease' => $this->lease->id]);
        $this->assertSame($alertedAt->toDateTimeString(), $this->lease->fresh()->expiry_notice_alerted_at->toDateTimeString());
        $this->assertSame(1, LeaseEvent::where('lease_id', $this->lease->id)->where('event_type', LeaseEvent::TYPE_EXPIRY_NOTICE_ALERTED)->count());
    }

    public function test_a_notice_an_agent_sends_by_hand_from_the_template_counts_as_sent(): void
    {
        $tpl = $this->template();

        $this->actingAs($this->agent)->post(route('corex.leases.notices.store', $this->lease), ['rental_notice_template_id' => $tpl->id, 'send_to_tenant' => 1, 'intent' => 'send'])
            ->assertSessionHasNoErrors();

        $lease = $this->lease->fresh();
        $this->assertNotNull($lease->expiry_notice_sent_at);
        $this->assertSame('agent', $lease->expiry_notice_sent_via);
    }

    // ── the lease screen, the Command Centre, the capture screen ──────────────────

    public function test_the_lease_screen_shows_the_dates_and_flags_overdue(): void
    {
        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();
        $this->assertStringContainsString('Expiry notice due between', $html);
        $this->assertStringContainsString('1 Dec 2026 and 29 Jan 2027', $html);
        $this->assertStringContainsString('CoreX sends it on', $html);
        $this->assertStringNotContainsString('data-qa="expiry-notice-banner"', $html, 'not yet due: no banner');

        $this->travelTo(Carbon::parse('2027-02-05 09:00:00'));
        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="expiry-notice-banner" data-state="overdue"', $html);
        $this->assertStringContainsString('Expiry notice OVERDUE', $html);
        $this->assertStringContainsString('data-qa="expiry-notice-send-now"', $html);
    }

    public function test_marking_it_as_given_clears_the_flag_and_is_logged(): void
    {
        $this->travelTo(Carbon::parse('2027-02-05 09:00:00'));

        $this->actingAs($this->agent)->post(route('corex.leases.expiry-notice.mark-given', $this->lease), ['note' => 'Handed over at the unit'])->assertSessionHas('success');

        $lease = $this->lease->fresh();
        $this->assertSame('manual', $lease->expiry_notice_sent_via);
        $this->assertSame('Handed over at the unit', $lease->expiry_notice_note);
        $this->assertSame($this->agent->id, (int) $lease->expiry_notice_sent_by_user_id);
        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-state="overdue"', $html);
        $this->assertStringContainsString('data-qa="expiry-notice-sent"', $html);
    }

    public function test_sending_now_without_a_template_says_so_in_words(): void
    {
        $this->actingAs($this->agent)->post(route('corex.leases.expiry-notice.send', $this->lease))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'no "Lease expiry notice" template'));
        $this->assertNull($this->lease->fresh()->expiry_notice_sent_at);
    }

    public function test_the_command_centre_lists_an_overdue_expiry_notice(): void
    {
        $this->grant($this->agent, ['rental_command_centre.view']);
        $this->travelTo(Carbon::parse('2027-02-05 09:00:00'));

        $items = app(\App\Services\Rentals\RentalCommandCentreService::class)->queueItems($this->agent, 'all');

        $row = $items->first(fn ($i) => $i['type'] === 'expiry_notice_overdue');
        $this->assertNotNull($row);
        $this->assertSame($this->lease->id, $row['lease']->id);
        $this->assertSame('corex.leases.show', $row['route']);

        $this->lease->forceFill(['expiry_notice_sent_at' => now()])->save();
        $this->assertNull(app(\App\Services\Rentals\RentalCommandCentreService::class)->queueItems($this->agent, 'all')->first(fn ($i) => $i['type'] === 'expiry_notice_overdue'));
    }

    public function test_the_capture_screen_shows_the_read_only_dates_and_no_typed_earliest_notice(): void
    {
        $this->grant($this->agent, ['leases.create']);

        $html = $this->actingAs($this->agent)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Expiry notice due between', $html);
        $this->assertStringContainsString('data-qa="expiry-notice-dates"', $html);
        $this->assertStringContainsString('data-qa="expiry-notice-send-on"', $html);
        $this->assertStringNotContainsString('name="notice[earliest_notice_date]"', $html, 'nothing is typed for the expiry notice any more');
        $this->assertStringContainsString('window.noticeTermsLive', $html);
        $this->assertMatchesRegularExpression('/\\\\u0022sendBd\\\\u0022:60/', $html);
    }

    public function test_the_tenants_20_business_day_early_cancellation_check_still_uses_the_settings_figure(): void
    {
        $this->assertSame(20, LeaseSetting::cpaEarlyCancelBusinessDaysFor($this->agency->id));
        $reviewed = app(\App\Services\Rentals\LeaseNoticeReviewService::class)->addBusinessDays(Carbon::parse('2026-12-14'), 20);

        // 20 business days after Mon 14 Dec 2026, skipping 16 Dec (holiday), 25 Dec and 1 Jan -> Thu 14 Jan 2027
        $this->assertSame('2027-01-14', $reviewed->toDateString());
        $this->assertSame(20, $this->businessDaysAfter('2026-12-14', '2027-01-14'));
    }
}
