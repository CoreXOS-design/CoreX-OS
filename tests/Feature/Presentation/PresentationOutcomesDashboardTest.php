<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationOutcome;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CX (Johan, 2026-08-20) — "numbers should always be true." Regression guard for
 * the Outcomes Dashboard date-column bug: the window filter was applied to
 * recorded_at (when an outcome was logged into CoreX) while every row DISPLAYS
 * decision_at (when the client actually decided) — so an outcome decided months
 * outside the picked window could still appear, because it happened to be typed
 * into the system inside it. Fixed by filtering on decision_at, which everything
 * downstream (tiles, avg days, loss reasons, the list) derives from via the same
 * $base query.
 */
final class PresentationOutcomesDashboardTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Shelly Beach',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agencyId]);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_presentations', 'agency_id' => $this->agencyId]);
        // Every real agent role carries this (it's what un-collapses the sidebar's
        // "Agents" nav section) — without it the badge test's page never reaches
        // the Real Estate / Presentations block at all. Matches production reality,
        // not a workaround for the fix under test.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'sidebar.section.agents', 'agency_id' => $this->agencyId]);
        Role::clearCache();
        PermissionService::clearCache();

        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent', 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        PermissionService::clearCache();
        parent::tearDown();
    }

    /** @return array{0: Presentation, 1: PresentationOutcome} */
    private function outcomeRow(string $address, string $decisionAt, string $recordedAt, string $presentationCreatedAt, string $outcome = PresentationOutcome::OUTCOME_WON_MANDATE): array
    {
        $presentation = Presentation::withoutEvents(fn () => Presentation::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'created_by_user_id' => $this->agent->id,
            'title' => $address, 'property_address' => $address, 'status' => 'finalized',
        ]));
        DB::table('presentations')->where('id', $presentation->id)->update(['created_at' => $presentationCreatedAt]);

        $out = PresentationOutcome::create([
            'presentation_id' => $presentation->id,
            'agency_id' => $this->agencyId,
            'outcome' => $outcome,
            'decision_at' => $decisionAt,
            'recorded_by_user_id' => $this->agent->id,
            'recorded_at' => $recordedAt,
        ]);

        return [$presentation, $out];
    }

    public function test_retha_in_the_window_returns_2_not_4(): void
    {
        // The exact live scenario: 4 outcomes total, only 2 with a decision_at
        // inside 22 May – 20 Aug 2026. Both other two were DECIDED outside the
        // window but RECORDED inside it — the old bug's trap.
        $this->outcomeRow('34 Marine Drive', '2026-08-12', '2026-08-12 13:52:38', '2026-08-12 13:49:33');
        $this->outcomeRow('7 Dolfynsig', '2026-07-10', '2026-08-03 08:54:31', '2026-07-01 15:36:25');
        $this->outcomeRow('303 Juanita Flats', '2026-02-20', '2026-07-21 09:09:19', '2026-05-21 16:04:28', PresentationOutcome::OUTCOME_WON_SALE);
        $this->outcomeRow('4 Barcelona', '2026-05-17', '2026-07-21 09:06:48', '2026-05-21 14:54:02', PresentationOutcome::OUTCOME_WON_SALE);

        $response = $this->actingAs($this->agent)->get(route('corex.presentations.outcomes.index', [
            'from' => '2026-05-22', 'to' => '2026-08-20',
        ]));

        $response->assertOk();
        $response->assertViewHas('totalOutcomes', 2);

        $addresses = collect($response->viewData('outcomes')->items())
            ->map(fn ($o) => $o->presentation->property_address)->all();
        $this->assertContains('34 Marine Drive', $addresses);
        $this->assertContains('7 Dolfynsig', $addresses);
    }

    public function test_a_row_with_decision_date_outside_the_window_does_not_appear(): void
    {
        $this->outcomeRow('Out Of Window', '2026-02-20', '2026-07-21 09:09:19', '2026-05-21 16:04:28');
        $this->outcomeRow('In Window', '2026-07-10', '2026-08-03 08:54:31', '2026-07-01 15:36:25');

        $response = $this->actingAs($this->agent)->get(route('corex.presentations.outcomes.index', [
            'from' => '2026-05-22', 'to' => '2026-08-20',
        ]));

        $response->assertOk();
        $addresses = collect($response->viewData('outcomes')->items())
            ->map(fn ($o) => $o->presentation->property_address)->all();
        $this->assertNotContains('Out Of Window', $addresses);
        $this->assertContains('In Window', $addresses);
    }

    public function test_avg_days_computes_from_decision_at_not_recorded_at(): void
    {
        // presentation created 2026-05-01, decided 2026-05-11 (10 days), but
        // RECORDED much later on 2026-08-01 (92 days) — if avgDays still used
        // recorded_at it would report ~92, not 10.
        $this->outcomeRow('Slow To Log', '2026-05-11', '2026-08-01 10:00:00', '2026-05-01 09:00:00');

        $response = $this->actingAs($this->agent)->get(route('corex.presentations.outcomes.index', [
            'from' => '2026-05-01', 'to' => '2026-08-20',
        ]));

        $response->assertOk();
        $response->assertViewHas('avgDays', 10);
    }

    public function test_outside_window_rows_are_excluded_from_the_total_tile_and_avg(): void
    {
        $this->outcomeRow('In Window A', '2026-07-01', '2026-07-01 10:00:00', '2026-06-01 09:00:00');
        $this->outcomeRow('In Window B', '2026-07-15', '2026-07-15 10:00:00', '2026-06-20 09:00:00');
        $this->outcomeRow('Way Before', '2026-01-01', '2026-07-01 10:00:00', '2025-12-01 09:00:00');

        $response = $this->actingAs($this->agent)->get(route('corex.presentations.outcomes.index', [
            'from' => '2026-05-22', 'to' => '2026-08-20',
        ]));

        $response->assertOk();
        $response->assertViewHas('totalOutcomes', 2);
    }

    /**
     * Johan (2026-08-20), on the "5 due" badge: "This makes no sense. You are
     * showing outcomes and on the screen its about outcomes. yet you are
     * showing me 5 due? ... I would just remove it." A count on a menu item
     * must count what THAT screen is for — this screen is a read-only
     * dashboard of already-recorded outcomes, not where outcomes are
     * captured, so no count belongs beside it at all. Removed outright, not
     * relabelled. Proves the link stays reachable and NO badge/count markup
     * renders, however many stale (no-outcome) presentations exist.
     */
    public function test_no_badge_renders_beside_the_outcomes_nav_item(): void
    {
        $this->outcomeRow('Recorded Outcome', '2026-07-01', '2026-07-01 10:00:00', '2026-06-01 09:00:00');

        Presentation::withoutEvents(function () {
            foreach (['Stale A', 'Stale B'] as $addr) {
                $p = Presentation::create([
                    'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
                    'created_by_user_id' => $this->agent->id, 'title' => $addr, 'property_address' => $addr, 'status' => 'finalized',
                ]);
                DB::table('presentations')->where('id', $p->id)->update(['created_at' => now()->subDays(45)]);
            }
        });

        $response = $this->actingAs($this->agent)->get(route('corex.presentations.outcomes.index', [
            'from' => '2026-05-22', 'to' => '2026-08-20',
        ]));

        $response->assertOk();
        $response->assertViewHas('totalOutcomes', 1);

        // The nav item itself is still there, still reachable, and — proven by
        // the regex, not just presence — has NO sibling badge/count element
        // between the label and the link's closing tag, in any form.
        $content = $response->getContent();
        $this->assertMatchesRegularExpression('#<span>Outcomes</span>\s*</a>#', $content);
        $this->assertStringNotContainsString('outcomePendingCount', $content);
    }
}
