<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionPhoto;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * .ai/specs/rental-inspections.md §45.8 — Build I-6a, security: H2 (property-level
 * endpoints), H3 (create/store/inspector), H4 (own-scope parity for the inspector).
 *
 * Test posture: the unseeded-grants default (agent = own, branch_manager = branch,
 * admin = all, every permission allowed) except where a test seeds its own grants to
 * pull the inspections scope below the properties scope.
 */
final class RentalInspectionI6aScopingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $otherAgency;
    private Branch $branch1;
    private Branch $branch2;
    private User $agentA;      // the property's agent, branch 1
    private User $colleague;   // another agent, SAME branch, own scope
    private User $bm1;         // branch manager, branch 1
    private User $bm2;         // branch manager, branch 2
    private User $admin;       // admin, all
    private User $outsider;    // admin of ANOTHER agency
    private Property $property;
    private Lease $lease;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        config(['mail.non_production_redirect' => 'test-redirect@example.test']);

        $this->agency = Agency::create(['name' => 'I6a Agency', 'slug' => 'i6a-' . uniqid()]);
        $this->otherAgency = Agency::create(['name' => 'I6a Other Agency', 'slug' => 'i6a-other-' . uniqid()]);
        $this->branch1 = Branch::forceCreate(['name' => 'Branch One', 'agency_id' => $this->agency->id]);
        $this->branch2 = Branch::forceCreate(['name' => 'Branch Two', 'agency_id' => $this->agency->id]);
        $otherBranch = Branch::forceCreate(['name' => 'Other Agency Branch', 'agency_id' => $this->otherAgency->id]);

        $mk = fn (string $role, Branch $b, Agency $a, string $name) => User::factory()->create([
            'agency_id' => $a->id, 'branch_id' => $b->id, 'role' => $role, 'name' => $name,
        ]);
        $this->agentA = $mk('agent', $this->branch1, $this->agency, 'Agent A');
        $this->colleague = $mk('agent', $this->branch1, $this->agency, 'Colleague');
        $this->bm1 = $mk('branch_manager', $this->branch1, $this->agency, 'BM One');
        $this->bm2 = $mk('branch_manager', $this->branch2, $this->agency, 'BM Two');
        $this->admin = $mk('admin', $this->branch1, $this->agency, 'Admin');
        $this->outsider = $mk('admin', $otherBranch, $this->otherAgency, 'Outsider');

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agentA->id, 'branch_id' => $this->branch1->id,
            'title' => '14 Jackson Street, Margate', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch1->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->agentA->id,
        ]);
    }

    private function inspection(string $type = RentalInspection::TYPE_IN, ?User $creator = null, array $extra = []): RentalInspection
    {
        return RentalInspection::create(array_merge([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => $type,
            'created_by_user_id' => ($creator ?? $this->agentA)->id,
        ], $extra));
    }

    // ═══ H2 — property-level endpoints ═══════════════════════════════════════════

    /** One request per property-level endpoint, as the CURRENT acting user. @return array<string, \Closure(): TestResponse> */
    private function propertyLevelRequests(): array
    {
        Storage::fake('public');
        $in = $this->inspection(RentalInspection::TYPE_IN);
        $out = $this->inspection(RentalInspection::TYPE_OUT);
        $item = RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Bedroom 1', 'created_by_user_id' => $this->agentA->id,
        ]);

        $this->actingAs($this->agentA);
        $this->postJson(route('corex.rental-inspections.photos.store', $in), ['photos' => [UploadedFile::fake()->image('in.jpg')]])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.photos.store', $out), ['photos' => [UploadedFile::fake()->image('out.jpg')]])->assertStatus(201);
        $photoIn = RentalInspectionPhoto::where('rental_inspection_id', $in->id)->firstOrFail();
        $photoOut = RentalInspectionPhoto::where('rental_inspection_id', $out->id)->firstOrFail();
        $group = \App\Models\RentalInspectionPhotoMatchGroup::linkPhotos($photoIn, $photoOut, $this->agentA);
        $member = $group->members()->where('rental_inspection_photo_id', $photoOut->id)->firstOrFail();

        $p = $this->property;

        return [
            'tabData' => fn () => $this->getJson(route('corex.properties.rental-inspection-tab.data', $p)),
            'start' => fn () => $this->postJson(route('corex.properties.rental-inspections.start', $p), ['type' => 'in']),
            'next' => fn () => $this->postJson(route('corex.properties.rental-inspections.next', [$p, $out]), ['type' => 'ad_hoc']),
            'storeItem' => fn () => $this->postJson(route('corex.properties.rental-inspection-items.store', $p), ['kind' => 'meter', 'label' => 'Water meter']),
            'assignType' => fn () => $this->postJson(route('corex.properties.rental-inspection-items.assign-type', [$p, $item]), ['space_type' => 'bedroom']),
            'retireItem' => fn () => $this->postJson(route('corex.properties.rental-inspection-items.retire', [$p, $item])),
            'restoreItem' => fn () => $this->postJson(route('corex.properties.rental-inspection-items.restore', [$p, $item])),
            'renameItem' => fn () => $this->postJson(route('corex.properties.rental-inspection-items.rename', [$p, $item]), ['label' => 'Renamed']),
            'reorderItems' => fn () => $this->postJson(route('corex.properties.rental-inspection-items.reorder', $p), ['property_room_id' => 1, 'item_ids' => [$item->id]]),
            'seedFromAdvertising' => fn () => $this->postJson(route('corex.properties.rental-inspection-items.seed-from-advertising', $p)),
            'applyDefaultRoomOrder' => fn () => $this->postJson(route('corex.properties.rental-inspection-rooms.apply-default-order', $p)),
            'reorderRooms' => fn () => $this->postJson(route('corex.properties.rental-inspection-rooms.reorder', $p), ['room_ids' => [1]]),
            'storePhotoMatch' => fn () => $this->postJson(route('corex.properties.rental-inspection-photo-matches.store', $p), ['photo_id' => $photoIn->id, 'anchor_photo_id' => $photoOut->id]),
            'destroyPhotoMatch' => fn () => $this->deleteJson(route('corex.properties.rental-inspection-photo-matches.destroy', [$p, $member])),
            'autoPairPhotoMatches' => fn () => $this->postJson(route('corex.properties.rental-inspection-photo-matches.auto-pair', $p)),
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function blockedUsers(): array
    {
        return [
            'colleague in the same branch (own scope)' => ['colleague'],
            'branch manager of ANOTHER branch' => ['bm2'],
            'admin of ANOTHER agency' => ['outsider'],
        ];
    }

    /**
     * Every property-level endpoint refuses a user who cannot see the property — by direct URL, not
     * just an absent link. 403 from the new guard, or 404 where the bound inspection / the agency
     * scope hides the record even earlier; never a 2xx/422 that reached the action.
     *
     *
     * (data provider: blockedUsers)
     */
    #[\PHPUnit\Framework\Attributes\DataProvider("blockedUsers")]
    public function test_every_property_level_endpoint_refuses_a_user_outside_the_property_scope(string $who): void
    {
        $requests = $this->propertyLevelRequests();
        $this->actingAs($this->{$who});

        $itemsBefore = RentalInspectionItem::count();
        $inspectionsBefore = RentalInspection::count();
        $matchesBefore = \App\Models\RentalInspectionPhotoMatchGroupMember::count();

        foreach ($requests as $name => $request) {
            $status = $request()->getStatusCode();
            $this->assertContains($status, [403, 404], "{$name} answered {$status} to {$who}; it must be refused (403/404)");
        }

        $this->assertSame($itemsBefore, RentalInspectionItem::count(), "no item written by {$who}");
        $this->assertSame($inspectionsBefore, RentalInspection::count(), "no inspection written by {$who}");
        $this->assertSame($matchesBefore, \App\Models\RentalInspectionPhotoMatchGroupMember::count(), "no photo match changed by {$who}");
    }

    public function test_the_property_agent_a_branch_manager_in_the_branch_and_an_admin_all_still_work(): void
    {
        $requests = $this->propertyLevelRequests();

        foreach (['agentA', 'bm1', 'admin'] as $who) {
            $this->actingAs($this->{$who});

            $this->assertSame(200, $requests['tabData']()->getStatusCode(), "{$who} reads the tab");
            $this->assertSame(200, $requests['storeItem']()->getStatusCode(), "{$who} adds a meter");
            $this->assertSame(200, $requests['autoPairPhotoMatches']()->getStatusCode(), "{$who} auto-pairs");
        }
    }

    public function test_an_inspections_scope_narrower_than_the_properties_scope_is_a_real_ceiling(): void
    {
        // This user may see EVERY property in the agency (properties = all) but only their own
        // BRANCH's inspections. A property in the other branch must be refused by the inspections
        // ceiling even though the property scope alone would let them in.
        $role = Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        foreach ([
            ['properties.view', 'all'],
            ['rental_inspections.view', 'branch'],
            ['rental_inspections.create', null],
            ['access_properties', null],
        ] as [$key, $scope]) {
            RolePermission::updateOrCreate(
                ['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id],
                ['scope' => $scope],
            );
        }
        PermissionService::clearCache();

        $otherBranchProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->bm2->id, 'branch_id' => $this->branch2->id,
            'title' => '9 Marine Drive, Ramsgate', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->actingAs($this->colleague);
        $this->getJson(route('corex.properties.rental-inspection-tab.data', $otherBranchProperty))->assertForbidden();
        $this->postJson(route('corex.properties.rental-inspection-items.store', $otherBranchProperty), ['kind' => 'meter', 'label' => 'Meter'])->assertForbidden();
        $this->assertSame(0, RentalInspectionItem::count());

        // …and their own branch's property is fine.
        $this->getJson(route('corex.properties.rental-inspection-tab.data', $this->property))->assertOk();
    }

    public function test_photo_matching_is_refused_when_the_current_inspection_is_a_colleagues_and_the_actor_is_own_scoped(): void
    {
        // The property is branch 1's; the actor sees it (properties scope all) but may only record on
        // their OWN inspections. The chain tail is agentA's, so matching on it is refused.
        $role = Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        foreach ([['properties.view', 'all'], ['rental_inspections.view', 'own'], ['rental_inspections.create', null], ['access_properties', null]] as [$key, $scope]) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();

        $this->inspection(RentalInspection::TYPE_IN, $this->agentA);

        $this->actingAs($this->colleague);
        $this->postJson(route('corex.properties.rental-inspection-photo-matches.auto-pair', $this->property))->assertForbidden();
    }

    // ═══ H4 — own-scope parity: the inspector ════════════════════════════════════

    public function test_an_inspector_booked_on_someone_elses_inspection_can_work_it_under_own_scope(): void
    {
        $inspection = $this->inspection(RentalInspection::TYPE_IN, $this->agentA, ['inspector_user_id' => $this->colleague->id]);

        // Route binding already lets the inspector in (scopeVisibleTo 'own' = creator OR inspector);
        // before the fix the per-record guard then answered 403.
        $this->actingAs($this->colleague)
            ->postJson(route('corex.rental-inspections.overall-notes.update', $inspection), ['overall_notes' => 'Walked it with the tenant.'])
            ->assertOk();

        $this->assertSame('Walked it with the tenant.', $inspection->fresh()->overall_notes);
    }

    public function test_a_user_who_is_neither_creator_nor_inspector_is_still_refused(): void
    {
        $inspection = $this->inspection(RentalInspection::TYPE_IN, $this->agentA, ['inspector_user_id' => $this->colleague->id]);
        $stranger = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch1->id, 'role' => 'agent']);

        $this->actingAs($stranger)
            ->postJson(route('corex.rental-inspections.overall-notes.update', $inspection), ['overall_notes' => 'x'])
            ->assertNotFound();
    }

    public function test_the_guard_itself_refuses_when_the_binding_is_bypassed(): void
    {
        // Direct call of the guard (the binding is a separate, earlier layer): an own-scoped user who is
        // neither creator nor inspector gets a 403; the inspector does not.
        $inspection = $this->inspection(RentalInspection::TYPE_IN, $this->agentA, ['inspector_user_id' => $this->colleague->id]);
        $probe = new class {
            use \App\Http\Controllers\Concerns\AuthorizesRentalRecordScope {
                guardRentalRecordScope as public guard;
            }
        };

        $this->actingAs($this->colleague);
        $probe->guard($inspection, 'rental_inspections', $this->branch1->id); // no exception: the inspector

        $stranger = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch2->id, 'role' => 'agent']);
        $this->actingAs($stranger);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $probe->guard($inspection, 'rental_inspections', $this->branch1->id);
    }

    // ═══ H3 — create picker, store(), inspector ══════════════════════════════════

    public function test_the_new_inspection_picker_only_offers_properties_in_the_users_scope(): void
    {
        $offered = fn (User $u) => $this->actingAs($u)->get(route('corex.rental-inspections.create'))
            ->assertOk()->viewData('properties')->pluck('id')->all();

        $this->assertContains($this->property->id, $offered($this->agentA));
        $this->assertContains($this->property->id, $offered($this->bm1));
        $this->assertContains($this->property->id, $offered($this->admin));
        $this->assertNotContains($this->property->id, $offered($this->colleague), 'own scope: a colleague\'s listing is not offered');
        $this->assertNotContains($this->property->id, $offered($this->bm2), 'branch scope: another branch\'s listing is not offered');
        $this->assertNotContains($this->property->id, $offered($this->outsider), 'another agency never sees it');
    }

    public function test_store_refuses_a_property_outside_the_users_scope_and_writes_nothing(): void
    {
        foreach (['colleague', 'bm2', 'outsider'] as $who) {
            $this->actingAs($this->{$who})
                ->post(route('corex.rental-inspections.store'), ['property_id' => $this->property->id, 'type' => 'in'])
                ->assertForbidden();
        }
        $this->assertSame(0, RentalInspection::count());

        // A property id that does not exist at all is still the ordinary validation error, never a 500.
        $this->actingAs($this->agentA)
            ->post(route('corex.rental-inspections.store'), ['property_id' => 99999999, 'type' => 'in'])
            ->assertSessionHasErrors('property_id');
    }

    public function test_store_still_starts_an_inspection_for_a_property_in_scope(): void
    {
        $this->actingAs($this->agentA)
            ->post(route('corex.rental-inspections.store'), ['property_id' => $this->property->id, 'type' => 'in'])
            ->assertRedirect(route('corex.properties.show', ['property' => $this->property->id, 'tab' => 'inspections']));

        $this->assertSame(1, RentalInspection::count());

        // Scheduling goes through the same resolver.
        $this->actingAs($this->colleague)
            ->post(route('corex.rental-inspections.store'), [
                'property_id' => $this->property->id, 'type' => 'out', 'intent' => 'schedule',
                'scheduled_for' => now()->addDays(5)->toDateString(),
            ])->assertForbidden();
        $this->assertSame(1, RentalInspection::count());
    }

    public function test_scheduling_refuses_an_inspector_from_another_agency(): void
    {
        Mail::fake();
        $foreign = User::factory()->create(['agency_id' => $this->otherAgency->id, 'role' => 'agent', 'email' => 'foreign@example.test']);

        $this->actingAs($this->agentA)
            ->post(route('corex.rental-inspections.store'), [
                'property_id' => $this->property->id, 'type' => 'in', 'intent' => 'schedule',
                'scheduled_for' => now()->addDays(5)->toDateString(), 'inspector_user_id' => $foreign->id,
            ])->assertSessionHasErrors('inspector_user_id');

        $this->assertSame(0, RentalInspection::count());
        Mail::assertNothingSent();
    }

    public function test_scheduling_accepts_a_same_agency_inspector_and_an_empty_one(): void
    {
        Mail::fake();

        $this->actingAs($this->agentA)->post(route('corex.rental-inspections.store'), [
            'property_id' => $this->property->id, 'type' => 'in', 'intent' => 'schedule',
            'scheduled_for' => now()->addDays(5)->toDateString(), 'inspector_user_id' => $this->colleague->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->colleague->id, RentalInspection::first()->inspector_user_id);

        // Optional-and-empty: the lazy-but-valid shortcut — no inspector chosen defaults to the booker.
        $this->actingAs($this->agentA)->post(route('corex.rental-inspections.store'), [
            'property_id' => $this->property->id, 'type' => 'ad_hoc', 'intent' => 'schedule',
            'scheduled_for' => now()->addDays(6)->toDateString(), 'inspector_user_id' => '',
        ])->assertSessionHasNoErrors();
        $this->assertSame($this->agentA->id, RentalInspection::orderByDesc('id')->first()->inspector_user_id);
    }

    public function test_rescheduling_refuses_an_inspector_from_another_agency_and_leaves_the_booking_alone(): void
    {
        Mail::fake();
        $inspection = RentalInspection::schedule($this->property, RentalInspection::TYPE_IN, $this->agentA, [
            'scheduled_for' => now()->addDays(5)->toDateString(), 'inspector_user_id' => $this->colleague->id,
        ]);
        $foreign = User::factory()->create(['agency_id' => $this->otherAgency->id, 'role' => 'agent']);

        $this->actingAs($this->agentA)
            ->post(route('corex.rental-inspections.reschedule', $inspection), [
                'scheduled_for' => now()->addDays(9)->toDateString(), 'inspector_user_id' => $foreign->id,
            ])->assertSessionHasErrors('inspector_user_id');

        $fresh = $inspection->fresh();
        $this->assertSame($this->colleague->id, $fresh->inspector_user_id);
        $this->assertSame(now()->addDays(5)->toDateString(), $fresh->scheduled_for->toDateString());

        $this->actingAs($this->agentA)
            ->post(route('corex.rental-inspections.reschedule', $inspection), [
                'scheduled_for' => now()->addDays(9)->toDateString(), 'inspector_user_id' => $this->bm1->id,
            ])->assertSessionHasNoErrors();
        $this->assertSame($this->bm1->id, $inspection->fresh()->inspector_user_id);
    }
}
