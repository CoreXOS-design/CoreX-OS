<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\PropertyAdTemplate;
use App\Models\PropertyMarketingPost;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Owner agency lock (Johan, 2026-10-18: "yes do that" — follow-up R1/R2 of
 * .ai/audits/2026-10-18-company-ads-agency-isolation-audit.md).
 *
 * A CoreX owner account is not limited by AgencyScope until it uses the agency switcher. The property Template Manager
 * list and Scheduled Posts now show only the agency being worked in for that owner too, and another agency's template /
 * post by id is a 404 there. Ordinary users are unchanged (they were already limited by AgencyScope).
 */
final class OwnerAgencyLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 20, 8, 0, 0, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        PermissionService::clearCache();
        Role::clearCache();
        parent::tearDown();
    }

    public function test_an_owner_sees_only_the_property_templates_of_the_agency_it_is_working_in(): void
    {
        [$a, $b, $owner, $userB] = $this->world();
        $this->template($a, $owner, 'ALPHA-TEMPLATE');
        $theirs = $this->template($b, $userB, 'BRAVO-TEMPLATE');
        $theirsArchived = $this->template($b, $userB, 'BRAVO-OLD-TEMPLATE', true);
        $this->actingAs($owner);

        $this->get(route('tools.ad-manager.templates', ['status' => 'all']))->assertOk()
            ->assertSee('ALPHA-TEMPLATE')->assertDontSee('BRAVO-TEMPLATE')->assertDontSee('BRAVO-OLD-TEMPLATE');

        $this->post(route('tools.ad-manager.templates.archive', $theirs))->assertNotFound();
        $this->post(route('tools.ad-manager.templates.restore', $theirsArchived))->assertNotFound();
        $this->assertNull(PropertyAdTemplate::withoutGlobalScopes()->find($theirs)->deleted_at);
        $this->assertNotNull(PropertyAdTemplate::withoutGlobalScopes()->find($theirsArchived)->deleted_at);
    }

    public function test_an_owner_sees_and_touches_only_the_scheduled_posts_of_the_agency_it_is_working_in(): void
    {
        [$a, $b, $owner, $userB] = $this->world();
        $this->post_($a, $this->userIn($a, 'agent'), 'ALPHA post text'); // an owner cannot be a listing agent
        $theirs = $this->post_($b, $userB, 'BRAVO post text');
        $this->actingAs($owner);

        $this->get(route('tools.ad-manager.scheduled'))->assertOk()->assertSee('ALPHA post text')->assertDontSee('BRAVO post text');
        $this->post(route('tools.ad-manager.scheduled.cancel', $theirs))->assertNotFound();
        $this->post(route('tools.ad-manager.scheduled.update', $theirs), ['copy' => 'x', 'date' => '2026-10-27', 'time' => '09:00'])->assertNotFound();
        $this->assertSame('scheduled', PropertyMarketingPost::withoutGlobalScopes()->find($theirs)->status);
    }

    public function test_ordinary_users_are_unchanged(): void
    {
        [$a, $b, , $userB] = $this->world();
        $adminB = $this->userIn($b, 'admin');
        $this->template($b, $userB, 'BRAVO-TEMPLATE');
        $this->post_($b, $userB, 'BRAVO post text');
        $this->template($a, $this->userIn($a, 'admin'), 'ALPHA-TEMPLATE');

        $this->actingAs($adminB)->get(route('tools.ad-manager.templates'))->assertOk()->assertSee('BRAVO-TEMPLATE')->assertDontSee('ALPHA-TEMPLATE');
        $this->get(route('tools.ad-manager.scheduled'))->assertOk()->assertSee('BRAVO post text');
    }

    // ── Rentals (audit 2026-10-18, A-L12) ────────────────────────────────────

    /** a rental property in $agency with a fault report titled $title and an active lease */
    private function rentalRecords(int $agency, User $user, string $title): array
    {
        $property = \App\Models\Property::create([
            'agency_id' => $agency, 'branch_id' => $user->branch_id, 'agent_id' => $user->id, 'title' => $title . ' house',
            'status' => 'active', 'listing_type' => 'rental', 'property_type' => 'house',
        ]);
        $fault = \App\Models\RentalFaultReport::create([
            'agency_id' => $agency, 'property_id' => $property->id, 'reported_by_type' => \App\Models\RentalFaultReport::REPORTED_BY_AGENT_NOTICED,
            'reported_by_user_id' => $user->id, 'reported_channel' => \App\Models\RentalFaultReport::CHANNEL_IN_PERSON, 'title' => $title,
            'description' => 'x', 'captured_by_user_id' => $user->id, 'created_by_user_id' => $user->id, 'reported_at' => now(),
            'status' => \App\Models\RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => \App\Models\RentalFaultReport::APPROVAL_NOT_REQUIRED,
        ]);
        $lease = \App\Models\Lease::create([
            'agency_id' => $agency, 'branch_id' => $user->branch_id, 'property_id' => $property->id, 'status' => \App\Models\Lease::STATUS_ACTIVE,
            'rental_amount' => 9500, 'start_date' => now()->subDays(10), 'end_date' => now()->addDays(10), 'created_by_user_id' => $user->id,
        ]);

        return [$property, $fault, $lease];
    }

    public function test_an_owner_is_held_to_the_working_agency_on_the_rentals_api_reports_and_command_centre(): void
    {
        [$a, $b, $owner, $userB] = $this->world();
        $this->rentalRecords($a, $this->userIn($a, 'agent'), 'ALPHA fault');
        $this->rentalRecords($b, $userB, 'BRAVO fault');

        $this->actingAs($owner);
        $report = $this->getJson('/api/v1/rentals/reports/fault-reports')->assertOk();
        $this->assertStringContainsString('ALPHA fault', json_encode($report->json('rows')));
        $this->assertStringNotContainsString('BRAVO fault', json_encode($report->json('rows')));

        $expiries = $this->getJson('/api/v1/rentals/reports/lease-expiries')->assertOk();
        $this->assertSame(1, (int) $expiries->json('count'));

        $centre = $this->getJson('/api/v1/rentals/command-centre')->assertOk();
        $this->assertSame(1, (int) $centre->json('tiles.all'));
        $this->assertSame(1, (int) $centre->json('properties.total'));
    }

    public function test_an_owner_gets_403_on_another_agencys_rental_record_by_direct_url(): void
    {
        [$a, $b, $owner, $userB] = $this->world();
        [, , $leaseB] = $this->rentalRecords($b, $userB, 'BRAVO fault');
        [, , $leaseA] = $this->rentalRecords($a, $this->userIn($a, 'agent'), 'ALPHA fault');

        $this->actingAs($owner);
        $this->get(route('corex.leases.show', $leaseB))->assertForbidden();
        $this->assertNotContains($this->get(route('corex.leases.show', $leaseA))->getStatusCode(), [403, 404]);
    }

    public function test_an_owner_cannot_read_another_agencys_buyer_notes(): void
    {
        [$a, $b, $owner, $userB] = $this->world();
        $contactA = \App\Models\Contact::create(['agency_id' => $a, 'first_name' => 'Alpha', 'last_name' => 'Buyer', 'agent_id' => $owner->id]);
        $contactB = \App\Models\Contact::create(['agency_id' => $b, 'first_name' => 'Bravo', 'last_name' => 'Buyer', 'agent_id' => $userB->id]);

        $visible = (new \App\Services\Buyers\BuyerNotesAccess())->visibleContactIds($owner, [$contactA->id, $contactB->id]);

        $this->assertSame([$contactA->id], $visible);
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** @return array{0:int,1:int,2:User,3:User} agency A, agency B, an owner whose home agency is A (no switch), a user of B */
    private function world(): array
    {
        Role::forceCreate(['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => true]);
        Role::clearCache();
        $a = $this->agency();
        $b = $this->agency();
        $owner = $this->userIn($a, 'super_admin');
        $this->assertTrue($owner->isOwnerRole());

        return [$a, $b, $owner, $this->userIn($b, 'admin')];
    }

    private function agency(): int
    {
        return (int) DB::table('agencies')->insertGetId(['name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function userIn(int $agency, string $role): User
    {
        $branch = DB::table('branches')->where('agency_id', $agency)->value('id')
            ?? (int) DB::table('branches')->insertGetId(['agency_id' => $agency, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

        return User::factory()->create(['agency_id' => $agency, 'branch_id' => $branch, 'role' => $role]);
    }

    private function template(int $agency, User $user, string $name, bool $archived = false): int
    {
        return (int) DB::table('property_ad_templates')->insertGetId([
            'agency_id' => $agency, 'user_id' => $user->id, 'name' => $name,
            'layout_json' => json_encode(['canvasW' => 1200, 'canvasH' => 628, 'elements' => []]),
            'is_global' => 0, 'ai_generated' => 0, 'created_at' => now(), 'updated_at' => now(), 'deleted_at' => $archived ? now() : null,
        ]);
    }

    private function post_(int $agency, User $user, string $copy): int
    {
        // Made before anyone is signed in, so the explicit agency is kept (the same way ScheduledPostsTest makes listings).
        $property = (int) \App\Models\Property::create([
            'agency_id' => $agency, 'branch_id' => $user->branch_id, 'agent_id' => $user->id, 'title' => 'House ' . Str::random(4),
            'status' => 'active', 'listing_type' => 'sale', 'property_type' => 'house',
        ])->id;

        return (int) DB::table('property_marketing_posts')->insertGetId([
            'agency_id' => $agency, 'property_id' => $property, 'user_id' => $user->id, 'platform' => 'facebook', 'ad_copy' => $copy,
            'image_urls' => json_encode([]), 'status' => 'scheduled', 'scheduled_for' => '2026-10-25 07:00:00', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
