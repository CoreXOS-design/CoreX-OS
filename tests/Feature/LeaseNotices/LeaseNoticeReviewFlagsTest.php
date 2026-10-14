<?php

declare(strict_types=1);

namespace Tests\Feature\LeaseNotices;

use App\Models\Lease;
use App\Models\LeaseAgreementTerms;
use App\Models\LeaseSetting;
use App\Services\Rentals\LeaseNoticeReviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * lease-notices.md §5.3 - what the agent is told about a notice: against the lease's own terms (else the agency's defaults) and the CPA.
 * Every figure comes from the lease or the agency's settings.
 */
final class LeaseNoticeReviewFlagsTest extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    private LeaseNoticeReviewService $review;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildNoticeFixture();
        $this->review = app(LeaseNoticeReviewService::class);
    }

    private function flagsFor(string $givenBy, Carbon $given, Carbon $end, ?Lease $lease = null): array
    {
        return collect($this->review->flags($lease ?? $this->lease, $givenBy, $given, $end))->keyBy('code')->all();
    }

    public function test_a_clean_notice_ending_on_the_lease_end_date_has_nothing_to_check(): void
    {
        // the lease runs 6 months more; notice given today ends it on its end date: 30-day default notice is met
        $flags = $this->flagsFor('tenant', Carbon::today(), Carbon::parse($this->lease->end_date));
        $this->assertSame([], array_keys(array_filter($flags, fn ($f) => $f['severity'] === 'warn')), 'no warnings');
        $this->assertArrayHasKey('terms_unconfirmed', $flags, 'the agency defaults are not confirmed terms - an information note');
        $this->assertFalse($this->review->hasWarning(array_values($flags)));
    }

    public function test_a_tenant_cancelling_a_fixed_term_early_is_flagged_under_the_cpa_with_the_earliest_date(): void
    {
        $given = Carbon::today();
        $flags = $this->flagsFor('tenant', $given, $given->copy()->addDays(10));

        $this->assertArrayHasKey('cpa_early_cancellation', $flags);
        $this->assertArrayHasKey('cpa_notice_too_short', $flags);
        $expected = $this->review->addBusinessDays($given, 20)->toDateString();
        $this->assertSame($expected, $flags['cpa_notice_too_short']['suggested_end_date'], 'twenty business days from the date given');
        $this->assertStringContainsString('20 business days', $flags['cpa_notice_too_short']['title']);
        // the date that satisfies BOTH the Act and the lease's own early-cancellation notice (30 days by default) is the later of the two
        $this->assertSame(max($expected, $given->copy()->addDays(30)->toDateString()), $this->review->suggestedEndDate(array_values($flags)));
        $this->assertTrue($this->review->hasWarning(array_values($flags)));
    }

    public function test_the_cpa_business_days_are_the_agencys_setting(): void
    {
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['cpa_early_cancel_business_days' => 15]);
        $given = Carbon::today();
        $flags = $this->flagsFor('tenant', $given, $given->copy()->addDays(8));

        $this->assertSame($this->review->addBusinessDays($given, 15)->toDateString(), $flags['cpa_notice_too_short']['suggested_end_date']);
        $this->assertStringContainsString('15 business days', $flags['cpa_notice_too_short']['title']);
        $this->assertSame(15, $this->review->termsInForce($this->lease)['cpa_business_days']);
    }

    public function test_business_days_skip_weekends_and_loaded_public_holidays(): void
    {
        $monday = Carbon::parse('2026-11-02');   // a Monday
        $this->assertSame('2026-11-09', $this->review->addBusinessDays($monday, 5)->toDateString(), 'five business days = the next Monday');

        \App\Models\Leave\PublicHoliday::create(['country_code' => 'ZA', 'holiday_date' => '2026-11-04', 'name' => 'Test holiday', 'applies_to_year' => 2026]);
        $this->assertSame('2026-11-10', $this->review->addBusinessDays($monday, 5)->toDateString(), 'a public holiday in between pushes it a day');
    }

    public function test_a_lease_that_says_early_cancellation_is_not_allowed_is_flagged_for_the_agent_to_check(): void
    {
        LeaseAgreementTerms::forceCreate([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_period' => 60, 'notice_period_unit' => 'days',
            'early_cancellation_allowed' => 'no', 'notice_terms_source' => 'captured',
        ]);
        $flags = $this->flagsFor('tenant', Carbon::today(), Carbon::today()->addDays(40));

        $this->assertStringContainsString('not allowed', $flags['cpa_early_cancellation']['detail']);
    }

    public function test_a_notice_shorter_than_the_leases_own_notice_period_names_the_earliest_end_date(): void
    {
        // month-to-month lease, 2 months' notice in the lease
        $this->lease->forceFill(['is_month_to_month' => true, 'end_date' => null])->save();
        LeaseAgreementTerms::forceCreate([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_period' => 2, 'notice_period_unit' => 'months', 'notice_terms_source' => 'captured',
        ]);
        $given = Carbon::today();
        $flags = $this->flagsFor('tenant', $given, $given->copy()->addDays(30), $this->lease->fresh());

        $this->assertArrayHasKey('notice_period_short', $flags);
        $this->assertSame($given->copy()->addMonthsNoOverflow(2)->toDateString(), $flags['notice_period_short']['suggested_end_date']);
        $this->assertStringContainsString('2 months', $flags['notice_period_short']['detail']);

        $ok = $this->flagsFor('tenant', $given, $given->copy()->addMonthsNoOverflow(2)->addDay(), $this->lease->fresh());
        $this->assertArrayNotHasKey('notice_period_short', $ok);
    }

    public function test_the_agencys_default_notice_period_applies_when_the_lease_holds_none(): void
    {
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['tenant_notice_period_days' => 60, 'tenant_notice_period_unit' => 'days']);
        $given = Carbon::today();
        // a notice that ends the fixed term on its end date but whose 60 days are not met is impossible (end is 6 months away); use month-to-month
        $this->lease->forceFill(['is_month_to_month' => true, 'end_date' => null])->save();
        $flags = $this->flagsFor('tenant', $given, $given->copy()->addDays(45), $this->lease->fresh());

        $this->assertArrayHasKey('notice_period_short', $flags);
        $this->assertSame($given->copy()->addDays(60)->toDateString(), $flags['notice_period_short']['suggested_end_date']);
    }

    public function test_notice_given_before_the_earliest_date_the_lease_allows_is_flagged(): void
    {
        LeaseAgreementTerms::forceCreate([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_period' => 30, 'notice_period_unit' => 'days',
            'earliest_notice_date' => Carbon::today()->addMonths(2)->toDateString(), 'notice_terms_source' => 'captured',
        ]);
        $flags = $this->flagsFor('tenant', Carbon::today(), Carbon::parse($this->lease->end_date));

        $this->assertArrayHasKey('notice_too_early', $flags);
    }

    public function test_a_landlord_cannot_end_a_fixed_term_early_on_notice_alone(): void
    {
        $flags = $this->flagsFor('landlord', Carbon::today(), Carbon::today()->addDays(45));

        $this->assertArrayHasKey('landlord_ends_fixed_term', $flags);
        $this->assertSame(Carbon::parse($this->lease->end_date)->toDateString(), $flags['landlord_ends_fixed_term']['suggested_end_date']);
        $this->assertArrayNotHasKey('cpa_early_cancellation', $flags, 'the CPA right is the tenant\'s');
    }

    public function test_wrong_dates_are_flagged(): void
    {
        $past = $this->flagsFor('tenant', Carbon::today()->subDays(20), Carbon::today()->subDays(2));
        $this->assertArrayHasKey('end_in_past', $past);

        $backwards = $this->flagsFor('tenant', Carbon::today(), Carbon::today()->subDay());
        $this->assertArrayHasKey('end_before_notice', $backwards);

        $future = $this->flagsFor('tenant', Carbon::today()->addDays(5), Carbon::today()->addMonths(3));
        $this->assertArrayHasKey('notice_in_future', $future);

        $beforeStart = $this->flagsFor('tenant', Carbon::parse($this->lease->start_date)->subDays(3), Carbon::today()->addMonths(2));
        $this->assertArrayHasKey('notice_before_start', $beforeStart);

        $far = $this->flagsFor('tenant', Carbon::today(), Carbon::today()->addYears(3));
        $this->assertArrayHasKey('end_too_far', $far);
    }

    public function test_confirmed_terms_drop_the_unconfirmed_note(): void
    {
        LeaseAgreementTerms::forceCreate([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_period' => 30, 'notice_period_unit' => 'days',
            'notice_terms_source' => 'captured', 'notice_terms_confirmed_at' => now(),
        ]);
        $flags = $this->flagsFor('tenant', Carbon::today(), Carbon::parse($this->lease->end_date));

        $this->assertArrayNotHasKey('terms_unconfirmed', $flags);
    }

    public function test_a_second_agency_reads_its_own_settings_not_the_first_agencys(): void
    {
        LeaseSetting::updateOrCreate(['agency_id' => $this->agency->id], ['cpa_early_cancel_business_days' => 12]);
        $other = \App\Models\Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);

        $this->assertSame(12, LeaseSetting::cpaEarlyCancelBusinessDaysFor($this->agency->id));
        $this->assertSame(20, LeaseSetting::cpaEarlyCancelBusinessDaysFor($other->id), 'the default - nothing of the first agency\'s setting');
        $this->assertSame(20, LeaseSetting::cpaEarlyCancelBusinessDaysFor(null));
    }
}
