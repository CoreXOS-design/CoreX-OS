<?php

declare(strict_types=1);

namespace Tests\Feature\LeadResponse;

use App\Models\PortalLead;

/**
 * Lead response on the two interactive reports (Johan, 2026-10-07): built with each report's own components —
 * every figure clickable, opens the leads behind it — and under each report's own / branch / agency scope.
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
        return $this->actingAs($as ?? $this->admin)->getJson(route('buyers-report.drilldown', array_merge(['metric' => 'lead_response', 'period' => 'this_month'], $q)));
    }

    // ── Buyers Report ────────────────────────────────────────────────────

    public function test_the_buyers_report_shows_every_lead_response_figure_as_a_clickable_number(): void
    {
        $html = $this->actingAs($this->admin)->get(route('buyers-report.index', ['period' => 'this_month']))->assertOk()->getContent();

        $this->assertStringContainsString('Lead response', $html);
        foreach (['Leads received', 'Responded in target', 'Responded late', 'Not yet contacted', 'Average response', 'Median response'] as $label) {
            $this->assertStringContainsString($label, $html);
            $this->assertStringContainsString("drill('lead_response', '{$label}'", $html, "{$label} opens the leads behind it");
        }
        $this->assertStringContainsString('Lead response by agent', $html);
        $this->assertStringContainsString('Lead response by source', $html);
        $this->assertStringContainsString("drill('lead_response', ", $html);
        $this->assertStringContainsString('Anna Agent', $html);
        $this->assertStringContainsString('Property24', $html);
        $this->assertStringContainsString('Private Property', $html);
        $this->assertStringContainsString('first contact within 60 min', $html, 'the report states the target');
        $this->assertStringContainsString('08:00–20:00', $html, 'and the counting hours');
        $this->assertStringContainsString('a note alone does not count', $html);
        // the popup can link a lead to its contact
        $this->assertStringContainsString('row.href', $html);
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
        $html = $this->actingAs($this->agentA)->get(route('buyers-report.index', ['period' => 'this_month']))->assertOk()->getContent();
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

    public function test_the_agent_and_branch_pages_and_print_carry_the_same_block(): void
    {
        $this->actingAs($this->admin)->get(route('buyers-report.agent', ['user' => $this->agentA->id, 'period' => 'this_month']))
            ->assertOk()->assertSee('Lead response')->assertSee('Leads received');
        $this->actingAs($this->admin)->get(route('buyers-report.branch', ['branch' => $this->branch1->id, 'period' => 'this_month']))
            ->assertOk()->assertSee('Lead response by source');

        $print = $this->actingAs($this->admin)->get(route('buyers-report.print', ['scope' => 'agency', 'period' => 'this_month']))->assertOk()->getContent();
        $this->assertStringContainsString('Lead response', $print);
        $this->assertStringContainsString('Responded in target', $print);
        $this->assertStringContainsString('first contact within 60 min', $print);

        // a colleague's dedicated page is still blocked
        $this->actingAs($this->agentA)->get(route('buyers-report.agent', ['user' => $this->agentB->id]))->assertNotFound();
    }

    // ── Performance & ROI report ─────────────────────────────────────────

    private function pDrill(array $q, $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->getJson(route('performance.agency-report.drilldown', array_merge(['metric' => 'lead_response', 'period' => 'this_month', 'level' => 'company'], $q)));
    }

    public function test_the_performance_report_shows_the_same_figures_each_clickable(): void
    {
        $html = $this->actingAs($this->admin)->get(route('performance.agency-report', ['period' => 'this_month']))->assertOk()->getContent();

        $this->assertStringContainsString('Lead response', $html);
        foreach (['Leads received', 'Responded in target', 'Responded late', 'Not yet contacted', 'Average response', 'Median response'] as $label) {
            $this->assertStringContainsString("drill('lead_response', 'company', null, '{$label}'", $html);
        }
        $this->assertStringContainsString("drill('lead_response', 'agent', {$this->agentA->id}", $html);
        $this->assertStringContainsString("source=pp", $html);
        $this->assertStringContainsString('Anna Agent', $html);
    }

    public function test_the_performance_drilldown_lists_the_leads_and_obeys_that_reports_scope(): void
    {
        $this->assertSame(5, $this->pDrill(['subtype' => 'received'])->assertOk()->json('total'));
        $this->assertSame(1, $this->pDrill(['subtype' => 'late'])->json('total'));
        $this->assertSame(3, $this->pDrill(['subtype' => 'received', 'level' => 'agent', 'id' => $this->agentA->id])->json('total'));
        $this->assertSame(1, $this->pDrill(['subtype' => 'received', 'source' => 'website'])->json('total'));
        $this->pDrill(['subtype' => 'received', 'source' => 'nope'])->assertStatus(422);

        // own: only my leads; a colleague's agent drill is refused
        $this->assertSame(3, $this->pDrill(['subtype' => 'received'], $this->agentA)->json('total'));
        $this->pDrill(['subtype' => 'received', 'level' => 'agent', 'id' => $this->agentB->id], $this->agentA)->assertForbidden();
        // branch manager: branch only
        $this->assertSame(4, $this->pDrill(['subtype' => 'received'], $this->manager)->json('total'));
    }

    public function test_the_performance_print_carries_lead_response(): void
    {
        $print = $this->actingAs($this->admin)->get(route('performance.agency-report.print', ['period' => 'this_month']))->assertOk()->getContent();
        $this->assertStringContainsString('Lead response', $print);
        $this->assertStringContainsString('Source: Property24', $print);
    }
}
