<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\LeaseAgreementTerms;
use App\Services\Rentals\LeaseNoticeTermsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LeaseNotices\BuildsNoticeFixture;
use Tests\TestCase;

/**
 * AT-489 follow-up - CoreX no longer shows (or works out) an "earliest notice may be given" date of its own: the only such date left is the
 * lease's OWN captured clause, labelled "Lease clause: notice may be given from". Spec: .ai/specs/lease-notices.md section 11.
 */
final class LeaseEarliestNoticeCleanupAt489Test extends TestCase
{
    use BuildsNoticeFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
        $this->buildNoticeFixture();
        $this->lease->forceFill(['start_date' => '2026-06-01', 'end_date' => '2027-03-31'])->save();
        $this->grant($this->agent, ['rental_notices.review', 'rental_notices.view']);
    }

    public function test_a_date_is_never_worked_out_from_the_earliest_end_and_the_notice_period(): void
    {
        $w = app(LeaseNoticeTermsService::class)->workedDates($this->lease, [
            'notice_period' => 30, 'notice_period_unit' => 'days', 'earliest_termination_date' => '2027-02-28', 'earliest_notice_date' => null,
        ]);

        $this->assertNull($w['earliest_notice'], 'CoreX derives nothing: the expiry-notice window is the CPA one');
        $this->assertSame('2027-02-28', $w['earliest_end']);
    }

    public function test_the_lease_card_shows_the_expiry_window_and_no_earliest_notice_line_when_the_lease_states_none(): void
    {
        LeaseAgreementTerms::withoutAgencyStamping(fn () => LeaseAgreementTerms::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_period' => 30, 'notice_period_unit' => 'days',
            'earliest_termination_date' => '2027-02-28', 'notice_terms_source' => 'captured',
        ]));

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();

        $this->assertStringContainsString('Expiry notice due between', $html);
        $this->assertStringNotContainsString('Earliest notice may be given', $html);
        $this->assertStringNotContainsString('lease-clause-notice-from', $html);
    }

    public function test_a_clause_the_lease_itself_states_is_labelled_as_the_lease_clause(): void
    {
        LeaseAgreementTerms::withoutAgencyStamping(fn () => LeaseAgreementTerms::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'notice_period' => 30, 'notice_period_unit' => 'days',
            'earliest_notice_date' => '2027-01-31', 'notice_terms_source' => 'captured',
        ]));

        $html = $this->actingAs($this->agent)->get(route('corex.leases.show', $this->lease))->assertOk()->getContent();

        $this->assertStringContainsString('Lease clause: notice may be given from', $html);
        $this->assertStringContainsString('31 Jan 2027', $html);
        $this->assertStringNotContainsString('Earliest notice may be given', $html);
    }
}
