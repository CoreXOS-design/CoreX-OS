<?php

declare(strict_types=1);

namespace Tests\Feature\LeadResponse;

use App\Models\AgencyContactSettings;
use App\Models\PortalLead;
use App\Services\LeadResponse\LeadResponseResult;
use App\Services\LeadResponse\LeadResponseService;
use App\Services\Performance\Period;
use Carbon\CarbonImmutable;

/**
 * The ONE per-lead calculation (Johan, 2026-10-07): statuses, counted minutes, average / median, the not-measured
 * rule, whose lead it is, scope, per source, and that the drill-down rows are the same results as the figures.
 */
final class LeadResponseServiceTest extends LeadResponseTestCase
{
    private function period(): Period
    {
        return new Period(
            CarbonImmutable::parse('2026-10-01 00:00:00', 'Africa/Johannesburg'),
            CarbonImmutable::parse('2026-10-31 23:59:59', 'Africa/Johannesburg'),
            'October 2026',
            'custom',
        );
    }

    private function svc(): LeadResponseService
    {
        return app(LeadResponseService::class);
    }

    private function ids(): array
    {
        return [$this->agentA->id, $this->agentB->id, $this->agentC->id];
    }

    public function test_responded_in_target_late_waiting_and_overdue(): void
    {
        $this->lead(['received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:30:00']);         // 30 min → in target
        $this->lead(['received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 10:00:00']);         // 60 → in target (boundary)
        $this->lead(['received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 10:01:00']);         // 61 → late
        $this->lead(['received' => '2026-10-07 11:30:00']);                                                // 30 min ago → waiting, not overdue
        $this->lead(['received' => '2026-10-07 08:30:00']);                                                // 210 min ago → waiting, overdue

        $s = $this->svc()->summarise($this->svc()->results($this->agency->id, $this->period(), $this->ids()));

        $this->assertSame(5, $s['received']);
        $this->assertSame(2, $s['in_target']);
        $this->assertSame(1, $s['late']);
        $this->assertSame(2, $s['waiting']);
        $this->assertSame(1, $s['overdue'], 'only the lead already past 60 counted minutes is overdue — a lead 30 minutes old is never a miss');
        $this->assertSame(3, $s['responded']);
    }

    public function test_average_and_median_are_over_answered_leads_in_counted_minutes(): void
    {
        $this->lead(['received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:10:00']);   // 10
        $this->lead(['received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:20:00']);   // 20
        $this->lead(['received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 10:30:00']);   // 90
        $this->lead(['received' => '2026-10-07 09:00:00']);                                          // waiting: excluded from avg/median

        $s = $this->svc()->summarise($this->svc()->results($this->agency->id, $this->period(), $this->ids()));

        $this->assertSame(40, $s['avg']);      // (10+20+90)/3 = 40
        $this->assertSame(20, $s['median']);
    }

    public function test_median_of_an_even_count_is_the_middle_pair_average(): void
    {
        $this->lead(['received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:10:00']);   // 10
        $this->lead(['received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:30:00']);   // 30
        $s = $this->svc()->summarise($this->svc()->results($this->agency->id, $this->period(), $this->ids()));
        $this->assertSame(20, $s['median']);
    }

    public function test_only_counting_hours_count_and_the_agency_can_change_them(): void
    {
        // Arrived Mon 21:00 (after 20:00 close), answered Tue 08:30 → 30 counted minutes with the default hours.
        $this->lead(['received' => '2026-10-05 21:00:00', 'responded' => '2026-10-06 08:30:00']);
        $r = $this->svc()->results($this->agency->id, $this->period(), $this->ids())->first();
        $this->assertSame(30, $r->minutes);
        $this->assertSame(LeadResponseResult::IN_TARGET, $r->status);

        // The agency sets Tuesday to 09:00–17:00 and Sunday to not counted → the same lead now counts nothing until 09:00.
        $hours = \App\Support\LeadResponse\BusinessHours::defaults();
        $hours['tue'] = ['counted' => true, 'start' => '09:00', 'end' => '17:00'];
        AgencyContactSettings::forAgency($this->agency->id)->update(['lead_response_hours' => $hours]);
        $r = $this->svc()->results($this->agency->id, $this->period(), $this->ids())->first();
        $this->assertSame(0, $r->minutes);
    }

    public function test_the_target_is_the_agencys_setting(): void
    {
        $this->lead(['received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:30:00']);
        AgencyContactSettings::forAgency($this->agency->id)->update(['lead_response_target_minutes' => 15]);

        $s = $this->svc()->summarise($this->svc()->results($this->agency->id, $this->period(), $this->ids()));
        $this->assertSame(1, $s['late']);
        $this->assertSame(0, $s['in_target']);
    }

    public function test_leads_from_before_tracking_are_not_measured_and_left_out_of_every_figure(): void
    {
        $this->lead(['received' => '2026-10-05 09:00:00', 'tracked' => false]);                        // old, nothing provable
        $this->lead(['received' => '2026-10-05 09:00:00', 'tracked' => false, 'responded' => '2026-10-05 09:05:00']); // backfilled
        $this->lead(['received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:30:00']);

        $s = $this->svc()->summarise($this->svc()->results($this->agency->id, $this->period(), $this->ids()));

        $this->assertSame(2, $s['received']);
        $this->assertSame(1, $s['not_measured']);
        $this->assertSame(0, $s['waiting'], 'an unmeasurable old lead is never a "not contacted" failure');
    }

    public function test_whose_lead_it_is_received_by_then_listing_agent_then_contact_agent(): void
    {
        $this->lead(['by' => $this->agentB, 'name' => 'ByB']);                                                // received_by wins
        $this->lead(['by' => null, 'name' => 'ListingA']);                                                    // listing agent (A)
        $this->lead(['by' => null, 'listing' => false, 'contact' => $this->contact('Cx', $this->agentC), 'name' => 'ContactC']); // contact agent (C)

        $byAgent = $this->svc()->results($this->agency->id, $this->period(), $this->ids())->groupBy('agentId')->map->count();

        $this->assertSame(1, $byAgent[$this->agentB->id]);
        $this->assertSame(1, $byAgent[$this->agentA->id]);
        $this->assertSame(1, $byAgent[$this->agentC->id]);
    }

    public function test_a_manager_responding_counts_on_the_agents_lead_and_is_shown_as_responder(): void
    {
        $this->lead(['by' => $this->agentA, 'received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:20:00', 'responder' => $this->manager]);

        $r = $this->svc()->results($this->agency->id, $this->period(), [$this->agentA->id])->first();

        $this->assertSame($this->agentA->id, $r->agentId, 'the time counts on the agent\'s lead');
        $this->assertSame('Mo Manager', $r->responderName);
        $this->assertSame(20, $r->minutes);
        $this->assertSame(1, $this->svc()->summarise(collect([$r]))['in_target']);
    }

    public function test_only_agents_in_the_cohort_and_leads_in_the_period_are_returned(): void
    {
        $this->lead(['by' => $this->agentA]);
        $this->lead(['by' => $this->agentC]);
        $this->lead(['by' => $this->agentA, 'received' => '2026-09-15 09:00:00']);   // before the period

        $this->assertCount(1, $this->svc()->results($this->agency->id, $this->period(), [$this->agentA->id]));
        $this->assertCount(0, $this->svc()->results($this->agency->id, $this->period(), []));
    }

    public function test_the_report_groups_by_agent_and_by_source(): void
    {
        $this->lead(['by' => $this->agentA, 'portal' => PortalLead::PORTAL_P24, 'received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:10:00']);
        $this->lead(['by' => $this->agentA, 'portal' => PortalLead::PORTAL_PP, 'received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 11:00:00']);
        $this->lead(['by' => $this->agentB, 'portal' => PortalLead::PORTAL_WEBSITE]);

        $rep = $this->svc()->report($this->agency->id, $this->period(), $this->ids());

        $this->assertSame(3, $rep['company']['received']);
        $this->assertSame(2, $rep['agents'][$this->agentA->id]['received']);
        $this->assertSame(1, $rep['agents'][$this->agentB->id]['received']);
        $this->assertSame(1, $rep['sources']['p24']['in_target']);
        $this->assertSame(1, $rep['sources']['pp']['late']);
        $this->assertSame('Website', $rep['sources']['website']['label']);
        $this->assertSame(60, $rep['target']);
    }

    public function test_drilldown_rows_are_the_same_results_as_each_figure(): void
    {
        $this->lead(['by' => $this->agentA, 'received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:10:00', 'channel' => 'message']);
        $this->lead(['by' => $this->agentA, 'received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 11:00:00', 'channel' => 'shared_link']);
        $this->lead(['by' => $this->agentB, 'received' => '2026-10-07 08:00:00']);

        $rep = $this->svc()->report($this->agency->id, $this->period(), $this->ids());
        foreach (['received', 'in_target', 'late', 'waiting', 'overdue', 'responded'] as $sub) {
            $rows = $this->svc()->rows($this->agency->id, $this->period(), $this->ids(), $sub);
            $expected = $sub === 'responded' ? $rep['company']['responded'] : $rep['company'][$sub];
            $this->assertSame($expected, $rows['count'], "the list behind '{$sub}' has exactly as many leads as the figure");
            $this->assertCount($expected, $rows['rows']);
        }

        $late = $this->svc()->rows($this->agency->id, $this->period(), $this->ids(), 'late')['rows'][0];
        $this->assertSame('Link shared', $late['how']);
        $this->assertSame('Anna Agent', $late['agent']);
        $this->assertSame('2 h 00 min', $late['minutes']);
        $this->assertSame('Late', $late['result']);
        $this->assertNotEmpty($late['href']);
        foreach (['lead', 'source', 'arrived', 'first_contact', 'responder', 'how', 'minutes'] as $col) {
            $this->assertContains($col, array_column($this->svc()->columns(), 'key'));
        }

        // narrowing to one agent / one source only ever narrows inside the cohort
        $this->assertSame(2, $this->svc()->rows($this->agency->id, $this->period(), $this->ids(), 'received', $this->agentA->id)['count']);
        $this->assertSame(0, $this->svc()->rows($this->agency->id, $this->period(), [$this->agentA->id], 'received', $this->agentC->id)['count'], 'an agent outside the cohort is never reachable');
        $this->assertSame(3, $this->svc()->rows($this->agency->id, $this->period(), $this->ids(), 'received', null, 'p24')['count']);
        $this->assertSame(0, $this->svc()->rows($this->agency->id, $this->period(), $this->ids(), 'received', null, 'pp')['count']);
    }

    public function test_formatting_and_hours_summary(): void
    {
        $this->assertSame('45 min', $this->svc()->formatMinutes(45));
        $this->assertSame('1 h 05 min', $this->svc()->formatMinutes(65));
        $this->assertSame('1 d 3 h', $this->svc()->formatMinutes(1440 + 180));
        $h = \App\Support\LeadResponse\BusinessHours::defaults();
        $h['sun']['counted'] = false;
        $this->assertSame('Mon, Tue, Wed, Thu, Fri, Sat 08:00–20:00 · Sun not counted', $this->svc()->hoursSummary($h));
    }
}
