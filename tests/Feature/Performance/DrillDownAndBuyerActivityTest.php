<?php

namespace Tests\Feature\Performance;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\User;
use App\Services\Performance\AgencyPerformanceReportService;
use App\Services\Performance\BuyerActivityService;
use App\Services\Performance\PeriodResolver;
use App\Services\Performance\PerformanceScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-366-D (branch drill-down) + AT-366-E (Q7 buyer-activity engine).
 *
 * The through-line both phases must hold is RECONCILIATION: agent totals sum to
 * their branch, and branches sum to the company — for the 12 report metrics and
 * for the buyer-activity metrics alike.
 */
class DrillDownAndBuyerActivityTest extends TestCase
{
    use RefreshDatabase;

    private function period()
    {
        return (new PeriodResolver())->resolve('this_month', null, null, CarbonImmutable::parse('2026-08-15'));
    }

    /** AT-366-D — branch drill-down reconciles with the company rollup. */
    public function test_branch_drilldown_reconciles_agents_to_branch_to_company(): void
    {
        $agency = Agency::create(['name' => 'A', 'slug' => 'a']);
        $b1 = Branch::create(['agency_id' => $agency->id, 'name' => 'B1']);
        $b2 = Branch::create(['agency_id' => $agency->id, 'name' => 'B2']);

        $a1 = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b1->id, 'role' => 'agent']);
        $a2 = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b1->id, 'role' => 'agent']);
        $a3 = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b2->id, 'role' => 'agent']);
        $this->actingAs($a1);

        // contacts_created in period: a1=2, a2=1 (branch B1=3); a3=3 (branch B2=3). Company=6.
        $this->makeContact($agency, $b1, $a1, '2026-08-05');
        $this->makeContact($agency, $b1, $a1, '2026-08-06');
        $this->makeContact($agency, $b1, $a2, '2026-08-07');
        $this->makeContact($agency, $b2, $a3, '2026-08-05');
        $this->makeContact($agency, $b2, $a3, '2026-08-06');
        $this->makeContact($agency, $b2, $a3, '2026-08-07');

        $service = app(AgencyPerformanceReportService::class);
        $company = $service->build(new PerformanceScope($agency->id), $this->period());

        // Company = sum of branches.
        $branchSum = array_sum(array_map(fn ($b) => $b['metrics']['contacts_created'], $company['branches']));
        $this->assertSame(6, $company['company']['contacts_created']);
        $this->assertSame(6, $branchSum, 'branches must sum to company');

        // Branch drill-down for B1.
        $drill = $service->branchJourney($agency->id, (string) $b1->id, $this->period());
        $cc = collect($drill['metrics'])->firstWhere('key', 'contacts_created');
        $this->assertSame(3, $cc['value'], 'B1 branch rollup = a1(2)+a2(1)');
        $this->assertSame($company['branches'][(string) $b1->id]['metrics']['contacts_created'], $cc['value'], 'drill == company branch bucket');

        // The drill lists exactly B1's agents, and their metrics sum to the branch.
        $drillAgentIds = array_column($drill['agents'], 'user_id');
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], $drillAgentIds);
        $agentSum = array_sum(array_map(fn ($a) => $a['metrics']['contacts_created'], $drill['agents']));
        $this->assertSame(3, $agentSum, 'agents in branch must sum to branch total');

        // Unknown branch → 404 signal.
        $this->assertSame(['branch' => null], $service->branchJourney($agency->id, '99999', $this->period()));
    }

    /** AT-366-E — buyer-activity metrics are counted and reconcile up the hierarchy. */
    public function test_buyer_activity_metrics_and_rollup(): void
    {
        $agency = Agency::create(['name' => 'A', 'slug' => 'a']);
        $b1 = Branch::create(['agency_id' => $agency->id, 'name' => 'B1']);
        $a1 = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b1->id, 'role' => 'agent']);
        $a2 = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $b1->id, 'role' => 'agent']);
        $this->actingAs($a1);

        // a1 holds 2 buyers, a2 holds 1. Company buyers held = 3.
        $buyer1 = $this->makeBuyer($agency, $b1, $a1, 'active', '2026-07-01');
        $buyer2 = $this->makeBuyer($agency, $b1, $a1, 'nurture', '2026-07-10');
        $this->makeBuyer($agency, $b1, $a2, 'active', '2026-07-05');

        // a1 activity in period on buyer1: 1 appointment, 1 email, 1 whatsapp.
        DB::table('calendar_events')->insert([
            'user_id' => $a1->id, 'agency_id' => $agency->id, 'event_type' => 'property',
            'category' => 'viewing', 'title' => 'V', 'contact_id' => $buyer1,
            'event_date' => '2026-08-05 10:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->linkComm($agency, $a1, $buyer1, 'email', '2026-08-06 09:00:00');
        $this->linkComm($agency, $a1, $buyer1, 'whatsapp', '2026-08-07 09:00:00');
        // Out-of-period email → excluded.
        $this->linkComm($agency, $a1, $buyer1, 'email', '2026-07-02 09:00:00');

        // a1 loses buyer2 in period (still lost), pre-approval R 1,500,000.
        DB::table('buyer_lost_records')->insert([
            'contact_id' => $buyer2, 'agency_id' => $agency->id,
            'reason_code' => 'bought_elsewhere', 'reason_label' => 'Bought elsewhere',
            'agent_owner_user_id_at_loss' => $a1->id, 'preapproval_amount_at_loss' => 1500000,
            'buyer_state_at_loss' => 'nurture', 'days_in_pipeline_at_loss' => 40,
            'recorded_at' => '2026-08-08 12:00:00', 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $svc = app(BuyerActivityService::class);
        $rollup = $svc->rollup(new PerformanceScope($agency->id), $this->period());

        $a1row = collect($rollup['agents'])->firstWhere('user_id', $a1->id)['metrics'];
        $this->assertSame(2, $a1row['buyers'], 'a1 holds 2 buyers');
        $this->assertSame(1, $a1row['appointments'], '1 in-period appointment on a buyer');
        $this->assertSame(1, $a1row['comms_email'], '1 in-period buyer email (July excluded)');
        $this->assertSame(1, $a1row['comms_whatsapp'], '1 in-period buyer whatsapp');
        $this->assertSame(1, $a1row['lost'], '1 buyer lost in period');
        $this->assertEquals(1500000.0, $a1row['lost_value'], 'lost pre-approval value');

        // Reconciliation: company buyers = 3; branch B1 = 3; agents sum to branch.
        $this->assertSame(3, $rollup['company']['buyers']);
        $this->assertSame(3, $rollup['branches'][(string) $b1->id]['metrics']['buyers']);
        $agentSum = array_sum(array_map(fn ($a) => $a['metrics']['buyers'], $rollup['agents']));
        $this->assertSame($rollup['company']['buyers'], $agentSum, 'agents sum to company');

        // agentDetail surfaces the per-buyer breakdown + the lost list.
        $detail = $svc->agentDetail($agency->id, $a1->id, $this->period());
        $this->assertCount(2, $detail['buyers']);
        $b1detail = collect($detail['buyers'])->firstWhere('contact_id', $buyer1);
        $this->assertSame(1, $b1detail['appointments'], 'per-buyer appointment count');
        $this->assertSame(2, $b1detail['comms'], 'per-buyer comms = email + whatsapp in period');
        $this->assertCount(1, $detail['lost']);
        $this->assertSame('Bought elsewhere', $detail['lost'][0]['reason']);
        $this->assertEquals(1500000.0, $detail['lost'][0]['value']);
    }

    private function makeContact(Agency $agency, Branch $branch, User $creator, string $date): void
    {
        $c = new Contact();
        $c->forceFill([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'C', 'last_name' => 'X', 'created_by_user_id' => $creator->id,
        ])->save();
        DB::table('contacts')->where('id', $c->id)->update(['created_at' => $date . ' 09:00:00']);
    }

    private function makeBuyer(Agency $agency, Branch $branch, User $owner, string $state, string $enteredAt): int
    {
        $c = new Contact();
        $c->forceFill([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Buyer', 'last_name' => $state, 'created_by_user_id' => $owner->id,
            'agent_id' => $owner->id, 'is_buyer' => 1, 'buyer_state' => $state,
            'buyer_pipeline_entered_at' => $enteredAt . ' 09:00:00',
            'last_contacted_at' => '2026-08-06 09:00:00', 'last_activity_at' => '2026-08-05 09:00:00',
        ])->save();
        // The observer may recompute is_buyer/state on save — pin them for the test.
        DB::table('contacts')->where('id', $c->id)->update([
            'is_buyer' => 1, 'buyer_state' => $state, 'agent_id' => $owner->id,
            'buyer_pipeline_entered_at' => $enteredAt . ' 09:00:00',
        ]);
        return (int) $c->id;
    }

    private function linkComm(Agency $agency, User $owner, int $contactId, string $channel, string $occurredAt): void
    {
        $commId = DB::table('communications')->insertGetId([
            'agency_id' => $agency->id, 'channel' => $channel, 'direction' => 'inbound',
            'external_id' => 'ext' . uniqid(), 'owner_user_id' => $owner->id,
            'occurred_at' => $occurredAt, 'captured_at' => $occurredAt,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('communication_links')->insert([
            'agency_id' => $agency->id, 'communication_id' => $commId,
            'linkable_type' => Contact::class, 'linkable_id' => $contactId,
            'link_method' => 'deterministic', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
