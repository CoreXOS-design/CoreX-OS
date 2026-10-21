<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\User;
use App\Services\Rentals\LeaseNoticeTermsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * AT-489 - the earliest-notice date on the lease capture screen (and the lease screen's Notice terms card) is worked out LIVE with
 * the server's formula and re-checked with the server's rules, in words under the field. The server rules are unchanged.
 * The browser behaviour itself was proved in headless Chromium against the rendered partial (see .ai/specs/leases.md AT-489).
 * Spec: .ai/specs/leases.md (AT-489).
 */
final class LeaseNoticeLiveDatesAt489Test extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-21 09:00:00'));
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Cape Town']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_active' => true]);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->admin->id,
            'title' => 'Unit 1', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function tenant(): Contact
    {
        return Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Thandi', 'last_name' => 'Test', 'email' => 't' . uniqid() . '@example.test']);
    }

    private function lease(array $over = []): Lease
    {
        $lease = Lease::create($over + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'status' => Lease::STATUS_ACTIVE,
            'rental_amount' => 9000, 'start_date' => '2025-11-01', 'end_date' => '2026-10-31', 'source' => 'manual', 'created_by_user_id' => $this->admin->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant()->id, 'is_primary' => true]);

        return $lease;
    }

    private function liveConfig(string $html): array
    {
        $this->assertSame(1, preg_match("/x-data=\"noticeTermsLive\\(JSON\\.parse\\('(.*?)'\\)\\)\" x-init=\"live\\(\\)\"/s", html_entity_decode($html), $m), 'the notice block is the live component');

        // Js::from() writes JSON.parse('...') with \u0022 for the quotes (a JS string escape).
        return json_decode(str_replace('\\u0022', '"', $m[1]), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_capture_screen_carries_the_agencys_months_and_the_live_component(): void
    {
        LeaseSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], ['default_earliest_notice_months' => 3]);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $cfg = $this->liveConfig($html);
        $this->assertSame(3, $cfg['months']);
        $this->assertFalse($cfg['shiftWithStart']);
        $this->assertNull($cfg['fixedStart']);
        $this->assertStringContainsString('data-qa="notice-earliest-live-error"', $html);
        $this->assertStringContainsString('window.noticeTermsLive', $html);
    }

    public function test_an_agency_without_the_months_setting_gets_no_invented_date(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.leases.create'))->assertOk()->getContent();

        $this->assertNull($this->liveConfig($html)['months']);
    }

    public function test_a_renewals_carried_forward_dates_move_with_the_start_date(): void
    {
        $current = $this->lease();
        app(LeaseNoticeTermsService::class)->save($current, ['notice_period' => 30, 'notice_period_unit' => 'days', 'earliest_notice_date' => '2026-02-01'], $this->admin, 'captured');

        $html = $this->actingAs($this->admin)->get(route('corex.leases.renewal.create', $current))->assertOk()->getContent();

        $this->assertTrue($this->liveConfig($html)['shiftWithStart']);
    }

    public function test_the_lease_screens_notice_card_knows_the_fixed_start_date(): void
    {
        LeaseSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], ['default_earliest_notice_months' => 2]);
        $lease = $this->lease(['start_date' => '2026-12-01', 'end_date' => '2027-11-30']);

        $html = $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))->assertOk()->getContent();

        $cfg = $this->liveConfig($html);
        $this->assertSame('2026-12-01', $cfg['fixedStart']);
        $this->assertSame(2, $cfg['months']);
    }

    public function test_the_server_rules_are_unchanged(): void
    {
        $svc = app(LeaseNoticeTermsService::class);

        $this->assertSame(
            ['notice.earliest_notice_date' => 'Earliest date notice may be given cannot be before the lease starts.'],
            $svc->crossErrors(['earliest_notice_date' => '2026-11-15'], '2026-12-01'),
        );
        $this->assertSame(
            ['notice.earliest_notice_date' => 'Notice cannot first be given after the earliest date the lease may end.'],
            $svc->crossErrors(['earliest_notice_date' => '2027-06-01', 'earliest_termination_date' => '2027-03-01'], '2026-12-01'),
        );
        $this->assertSame([], $svc->crossErrors(['earliest_notice_date' => '2027-03-01', 'earliest_termination_date' => '2027-03-01'], '2026-12-01'));
        // the formula the screen mirrors: start + the agency's months, month ends not overflowing
        LeaseSetting::withoutGlobalScopes()->updateOrCreate(['agency_id' => $this->agency->id], ['default_earliest_notice_months' => 3]);
        $this->assertSame('2027-04-30', $svc->defaultsFor($this->agency->id, Carbon::parse('2027-01-31'))['earliest_notice_date']);
    }
}
