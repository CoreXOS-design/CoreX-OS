<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Deal;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportCompRow;
use App\Models\MarketReports\SchemeOwner;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * On the demo box every property, deal, market report, comp row and scheme owner is saved is_demo = 1 by the code itself
 * (the DB trigger that used to do it is no longer needed). Tracked properties are deliberately left out. Any other host: untouched.
 */
final class DemoRowFlagTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Flag Agency ' . uniqid(), 'slug' => 'flag-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
    }

    /** @return array<string,mixed> one row of each flagged model, made through Eloquent exactly as a screen or a job would */
    private function makeAll(): array
    {
        $property = Property::withoutAgencyStamping(fn () => Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Flag test', 'address' => '1 Test Road', 'suburb' => 'Margate',
            'status' => 'active', 'listing_type' => 'sale',
        ]));
        $deal = Deal::withoutAgencyStamping(fn () => Deal::create([
            'agency_id' => $this->agency->id, 'period' => '2026-10', 'deal_date' => '2026-10-01', 'property_value' => 1_000_000, 'total_commission' => 50_000,
        ]));
        $report = MarketReport::withoutAgencyStamping(fn () => MarketReport::create([
            'agency_id' => $this->agency->id, 'uploaded_by_user_id' => $this->agent->id, 'file_path' => 'x.pdf', 'file_name' => 'x.pdf',
            'file_hash' => Str::random(40), 'report_date' => '2026-10-01',
        ]));
        $row = MarketReportCompRow::withoutAgencyStamping(fn () => MarketReportCompRow::create([
            'agency_id' => $this->agency->id, 'market_report_id' => $report->id, 'row_type' => 'comp',
        ]));
        $owner = SchemeOwner::create([
            'agency_id' => $this->agency->id, 'market_report_id' => $report->id, 'scheme_name' => 'Sea View', 'owner_name' => 'A Owner',
        ]);

        return compact('property', 'deal', 'report', 'row', 'owner');
    }

    public function test_on_the_demo_box_every_flow_saves_these_rows_as_demo(): void
    {
        config(['corex.instance.role' => 'demo']);

        foreach ($this->makeAll() as $name => $model) {
            $this->assertTrue((bool) $model->fresh()->is_demo, "{$name} is saved is_demo = 1 on the demo box");
        }
    }

    public function test_an_explicit_not_demo_value_does_not_survive_on_the_demo_box(): void
    {
        config(['corex.instance.role' => 'demo']);

        $p = Property::withoutAgencyStamping(fn () => Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id, 'external_id' => (string) Str::uuid(),
            'title' => 'Flag test', 'address' => '2 Test Road', 'suburb' => 'Margate', 'status' => 'active', 'listing_type' => 'sale', 'is_demo' => false,
        ]));

        $this->assertTrue((bool) $p->fresh()->is_demo);
    }

    public function test_tracked_properties_are_not_flagged_on_the_demo_box(): void
    {
        config(['corex.instance.role' => 'demo']);

        $tp = TrackedProperty::withoutAgencyStamping(fn () => TrackedProperty::create([
            'agency_id' => $this->agency->id, 'suburb' => 'Margate', 'suburb_normalised' => 'margate', 'status' => 'active', 'source_chain' => [],
        ]));

        $this->assertFalse((bool) $tp->fresh()->is_demo, 'tracked properties are shared market intelligence, not flagged by this hook');
    }

    public function test_on_every_other_host_nothing_is_flagged(): void
    {
        config(['corex.instance.role' => 'primary']);

        foreach ($this->makeAll() as $name => $model) {
            $this->assertFalse((bool) $model->fresh()->is_demo, "{$name} stays is_demo = 0 off the demo box");
        }
    }
}
