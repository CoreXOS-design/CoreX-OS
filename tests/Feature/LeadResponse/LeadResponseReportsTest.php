<?php

declare(strict_types=1);

namespace Tests\Feature\LeadResponse;

use App\Models\PortalLead;

/**
 * The Lead Response report (Johan, 2026-10-07) — its own page under Reports: every figure clickable, opens the
 * leads behind it, under the Buyers Report's own / branch / agency scope. It is no longer a section inside the
 * Buyers Report or the Performance & ROI report (one obvious home).
 */
final class LeadResponseReportsTest extends LeadResponseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->grantReports();

        // Anna (branch 1): one in target, one late, one waiting+overdue. Ben (branch 1): one waiting. Cara (branch 2): one in target.
        $this->lead(['by' => $this->agentA, 'name' => 'AnnaFast', 'received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 09:10:00', 'channel' => 'message']);
        $this->lead(['by' => $this->agentA, 'name' => 'AnnaSlow', 'received' => '2026-10-05 09:00:00', 'responded' => '2026-10-05 12:00:00', 'channel' => 'shared_link', 'responder' => $this->manager]);
        $this->lead(['by' => $this->agentA, 'name' => 'AnnaWait', 'portal' => PortalLead::PORTAL_PP, 'received' => '2026-10-07 08:00:00']);
        $this->lead(['by' => $this->agentB, 'name' => 'BenWait', 'portal' => PortalLead::PORTAL_WEBSITE, 'received' => '2026-10-07 11:50:00']);
        $this->lead(['by' => $this->agentC, 'name' => 'CaraFast', 'received' => '2026-10-06 09:00:00', 'responded' => '2026-10-06 09:05:00']);
    }

    private function bDrill(array $q, $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->getJson(route('lead-response-report.drilldown', array_merge(['period' => 'this_month'], $q)));
    }

    // ── The page ─────────────────────────────────────────────────────────

    public function test_the_page_shows_every_lead_response_figure_as_a_clickable_number(): void
    {
        $html = $this->actingAs($this->admin)->get(route('lead-response-report.index', ['period' => 'this_month']))->assertOk()->getContent();

        $this->assertStringContainsString('Lead Response', $html);
        foreach (['Leads received', 'Responded in target', 'Responded late', 'Not yet contacted', 'Average response', 'Median response'] as $label) {
            $this->assertStringContainsString($label, $html);
            $this->assertStringContainsString("drill('lead_response', '{$label}'", $html, "{$label} opens the leads behind it");
        }
        $this->assertStringContainsString('Lead response by agent', $html);
        $this->assertStringContainsString('Lead response by source', $html);
        $this->assertStringContainsString('Anna Agent', $html);
        $this->assertStringContainsString('Property24', $html);
        $this->assertStringContainsString('Private Property', $html);
        $this->assertStringContainsString('first contact within 60 min', $html, 'the report states the target');
        $this->assertStringContainsString('08:00–20:00', $html, 'and the counting hours');
        $this->assertStringContainsString('a note alone does not count', $html);
        $this->assertStringContainsString('row.href', $html, 'the popup can link a lead to its contact');
        $this->assertStringContainsString(route('lead-response-report.drilldown'), str_replace('\\/', '/', $html), 'the popup reads this report\'s own drill-down');
        $this->assertStringContainsString(route('lead-response-report.print'), $html);
        $this->assertStringContainsString(route('lead-response-report.pdf'), $html);
    }

    public function test_the_page_follows_the_period_and_a_bad_custom_range_never_500s(): void
    {
        $this->actingAs($this->admin)->get(route('lead-response-report.index', ['period' => 'this_year']))->assertOk();
        $this->actingAs($this->admin)->get(route('lead-response-report.index', ['period' => 'custom']))->assertOk();
        $this->actingAs($this->admin)->get(route('lead-response-report.index', ['period' => 'nonsense']))->assertOk();
    }

    public function test_the_old_homes_no_longer_carry_a_lead_response_section(): void
    {
        $buyers = $this->actingAs($this->admin)->get(route('buyers-report.index', ['period' => 'this_month']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Lead response by agent', $buyers);
        $this->assertStringNotContainsString("drill('lead_response'", $buyers);

        $perf = $this->actingAs($this->admin)->get(route('performance.agency-report', ['period' => 'this_month']))->assertOk()->getContent();
        $this->assertStringNotContainsString('Lead response by agent', $perf);
        $this->assertStringNotContainsString("drill('lead_response'", $perf);
    }

    public function test_print_and_pdf_carry_the_summary_under_the_same_scope(): void
    {
        $print = $this->actingAs($this->admin)->get(route('lead-response-report.print', ['scope' => 'agency', 'period' => 'this_month']))->assertOk()->getContent();
        $this->assertStringContainsString('Lead Response', $print);
        $this->assertStringContainsString('Responded in target', $print);
        $this->assertStringContainsString('first contact within 60 min', $print);
        $this->assertStringContainsString('Anna Agent', $print);

        $agentPrint = $this->actingAs($this->agentA)->get(route('lead-response-report.print', ['scope' => 'agency', 'period' => 'this_month']))->assertOk()->getContent();
        $this->assertStringContainsString('Anna Agent', $agentPrint);
        $this->assertStringNotContainsString('Cara Agent', $agentPrint);

        $this->actingAs($this->admin)->get(route('lead-response-report.pdf', ['scope' => 'agency', 'period' => 'this_month']))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_it_needs_the_report_access_permission(): void
    {
        $nobody = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch1->id, 'role' => 'viewer_none', 'is_active' => true]);
        $this->actingAs($nobody)->get(route('lead-response-report.index'))->assertForbidden();
        $this->actingAs($nobody)->getJson(route('lead-response-report.drilldown', ['subtype' => 'received']))->assertForbidden();
    }

    public function test_each_figure_opens_exactly_the_leads_behind_it_with_the_required_columns(): void
    {
        $all = $this->bDrill(['scope' => 'agency', 'subtype' => 'received'])->assertOk()->json();
        $this->assertSame(5, $all['total']);
        $this->assertSame(['lead', 'source', 'property', 'agent', 'arrived', 'first_contact', 'responder', 'how', 'minutes', 'result'], array_column($all['columns'], 'key'));

        $this->assertSame(2, $this->bDrill(['scope' => 'agency', 'subtype' => 'in_target'])->json('total'));   // AnnaFast, CaraFast
        $this->assertSame(1, $this->bDrill(['scope' => 'agency', 'subtype' => 'late'])->json('total'));       // AnnaSlow
        $this->assertSame(2, $this->bDrill(['scope' => 'agency', 'subtype' => 'waiting'])->json('total'));    // AnnaWait, BenWait
        $this->assertSame(1, $this->bDrill(['scope' => 'agency', 'subtype' => 'overdue'])->json('total'));    // AnnaWait only (BenWait is 10 minutes old)
        $this->assertSame(3, $this->bDrill(['scope' => 'agency', 'subtype' => 'responded'])->json('total'));

        $late = $this->bDrill(['scope' => 'agency', 'subtype' => 'late'])->json('rows')[0];
        $this->assertSame('AnnaSlow Lead', $late['lead']);
        $this->assertSame('Anna Agent', $late['agent']);
        $this->assertSame('Mo Manager', $late['responder'], 'a manager responding is shown as the responder, on the agent\'s lead');
        $this->assertSame('Link shared', $late['how']);
        $this->assertSame('3 h 00 min', $late['minutes']);
        $this->assertStringContainsString('Late', $late['result']);
    }

    public function test_drilling_by_agent_and_by_source(): void
    {
        $this->assertSame(3, $this->bDrill(['scope' => 'agency', 'subtype' => 'received', 'agent_id' => $this->agentA->id])->json('total'));
        $this->assertSame(1, $this->bDrill(['scope' => 'agency', 'subtype' => 'received', 'source' => 'pp'])->json('total'));
        $this->assertSame(1, $this->bDrill(['scope' => 'agency', 'subtype' => 'received', 'source' => 'website'])->json('total'));
        $this->bDrill(['scope' => 'agency', 'subtype' => 'received', 'source' => 'carrier_pigeon'])->assertStatus(422);
    }

    public function test_the_own_branch_agency_scope_switch_applies_to_lead_response_too(): void
    {
        // An agent sees only their own leads, however they ask.
        $own = $this->bDrill(['scope' => 'agency', 'subtype' => 'received'], $this->agentA)->assertOk()->json();
        $this->assertSame(3, $own['total']);
        $this->assertSame(['Anna Agent'], array_values(array_unique(array_column($own['rows'], 'agent'))));
        $this->assertSame(0, $this->bDrill(['scope' => 'agency', 'subtype' => 'received', 'agent_id' => $this->agentB->id], $this->agentA)->json('total'), 'a colleague\'s leads are unreachable by agent_id');
        $this->assertSame(0, $this->bDrill(['scope' => 'agency', 'subtype' => 'received', 'agent_id' => $this->agentC->id], $this->agentA)->json('total'));

        // A branch manager sees their branch (Anna + Ben), never Cara's branch.
        $branch = $this->bDrill(['scope' => 'agency', 'subtype' => 'received'], $this->manager)->assertOk()->json();
        $this->assertSame(4, $branch['total']);
        $this->assertNotContains('Cara Agent', array_column($branch['rows'], 'agent'));

        // Admin: the whole agency.
        $this->assertSame(5, $this->bDrill(['scope' => 'agency', 'subtype' => 'received'])->json('total'));

        // The page itself carries the same ceiling.
        $html = $this->actingAs($this->agentA)->get(route('lead-response-report.index', ['period' => 'this_month']))->assertOk()->getContent();
        $this->assertStringContainsString('Anna Agent', $html);
        $this->assertStringNotContainsString('Ben Agent', $html);
        $this->assertStringNotContainsString('Cara Agent', $html);
    }

    public function test_another_agencys_leads_never_appear(): void
    {
        $other = \App\Models\Agency::create(['name' => 'Elsewhere', 'slug' => 'else-' . uniqid()]);
        $ob = \App\Models\Branch::create(['agency_id' => $other->id, 'name' => 'X']);
        $oa = \App\Models\User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'role' => 'agent', 'is_active' => true]);
        $oc = \App\Models\Contact::withoutGlobalScopes()->create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'first_name' => 'Fo', 'last_name' => 'Reign', 'is_buyer' => true, 'phone' => '0829998888']);
        PortalLead::withoutGlobalScopes()->create(['agency_id' => $other->id, 'portal' => 'p24', 'lead_type' => 'enquiry', 'contact_id' => $oc->id, 'received_by_user_id' => $oa->id,
            'name' => 'Foreign', 'lead_source_raw' => [], 'received_at' => now()->subHour(), 'response_tracked' => true]);

        $this->assertSame(5, $this->bDrill(['scope' => 'agency', 'subtype' => 'received'])->json('total'));
    }
}
