<?php

declare(strict_types=1);

namespace Tests\Feature\MultiTenancy;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DepositInterestCalculation;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\PortalCapture;
use App\Models\Presentation;
use App\Models\Rental;
use App\Models\Role;
use App\Models\TrainingCourse;
use App\Models\TrainingLesson;
use App\Models\TvMessage;
use App\Models\User;
use App\Services\CommandCenter\CommandCentreService;
use App\Services\MarketDataSnapshotService;
use App\Services\Oversight\OversightService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-424 — Agency scan fixes. The 2026-09-21 isolation scan created a brand-new
 * agency and logged in as its Admin; every leak it (and the follow-up code
 * review) found is pinned here. "A" is the established agency whose data must
 * stay private; "B" is the new agency trying to reach it.
 */
final class AgencyScanFixesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agencyA;
    private Agency $agencyB;
    private Branch $branchA;
    private Branch $branchB;
    private User $adminA;
    private User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyA = Model::withoutEvents(fn () => Agency::create(['name' => 'Agency A', 'slug' => 'agency-a-' . uniqid()]));
        $this->agencyB = Model::withoutEvents(fn () => Agency::create(['name' => 'Agency B', 'slug' => 'agency-b-' . uniqid()]));
        $this->branchA = Branch::withoutAgencyStamping(fn () => Branch::create(['agency_id' => $this->agencyA->id, 'name' => 'A Main', 'code' => 'A1']));
        $this->branchB = Branch::withoutAgencyStamping(fn () => Branch::create(['agency_id' => $this->agencyB->id, 'name' => 'B Main', 'code' => 'B1']));

        $this->adminA = $this->user($this->agencyA, $this->branchA, 'admin', 'Alice Agencya');
        $this->adminB = $this->user($this->agencyB, $this->branchB, 'admin', 'Bob Agencyb');
    }

    private function user(Agency $agency, Branch $branch, string $role, string $name): User
    {
        return User::withoutAgencyStamping(fn () => User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => $role,
            'name' => $name, 'is_active' => true, 'is_admin' => $role === 'admin',
        ]));
    }

    private function rentalFor(Agency $agency, Branch $branch, string $address): Rental
    {
        return Rental::withoutAgencyStamping(fn () => Rental::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'lease_address' => $address, 'lease_start_date' => '2026-01-01', 'is_active' => true,
        ]));
    }

    // ── 1. Rentals ────────────────────────────────────────────────────────

    public function test_rentals_register_hides_another_agencys_rentals(): void
    {
        $this->rentalFor($this->agencyA, $this->branchA, '1 Agency A Street');
        $this->rentalFor($this->agencyB, $this->branchB, '9 Agency B Road');

        $this->actingAs($this->adminB)->get('/rentals')
            ->assertOk()
            ->assertSee('9 Agency B Road')
            ->assertDontSee('1 Agency A Street');
    }

    public function test_another_agencys_rental_cannot_be_opened_or_saved_by_id(): void
    {
        $rentalA = $this->rentalFor($this->agencyA, $this->branchA, '1 Agency A Street');

        $this->actingAs($this->adminB)->get(route('rentals.edit', $rentalA->id))->assertNotFound();
        $this->actingAs($this->adminB)->post(route('rentals.update', $rentalA->id), [
            'branch_id' => $this->branchB->id, 'lease_address' => 'hijacked', 'lease_start_date' => '2026-01-01',
        ])->assertNotFound();

        $this->assertSame('1 Agency A Street', DB::table('rentals')->where('id', $rentalA->id)->value('lease_address'));
    }

    public function test_a_rental_cannot_be_created_in_another_agencys_branch(): void
    {
        $this->actingAs($this->adminB)->post(route('rentals.store'), [
            'branch_id' => $this->branchA->id, 'lease_address' => 'Planted', 'lease_start_date' => '2026-01-01',
            'effective_from' => '2026-01-01', 'rent_incl' => 1, 'rent_excl' => 1, 'commission_incl' => 1, 'commission_excl' => 1,
        ])->assertForbidden();

        $this->assertDatabaseMissing('rentals', ['lease_address' => 'Planted']);
    }

    public function test_a_new_rental_is_stamped_with_its_branchs_agency(): void
    {
        $this->actingAs($this->adminB)->post(route('rentals.store'), [
            'branch_id' => $this->branchB->id, 'lease_address' => 'Own rental', 'lease_start_date' => '2026-01-01',
            'rental_agents' => [$this->adminA->id],
            'effective_from' => '2026-01-01', 'rent_incl' => 1, 'rent_excl' => 1, 'commission_incl' => 1, 'commission_excl' => 1,
        ])->assertRedirect(route('rentals.index'));

        $rental = Rental::withoutGlobalScopes()->where('lease_address', 'Own rental')->firstOrFail();
        $this->assertSame($this->agencyB->id, (int) $rental->agency_id);
        $this->assertSame(0, DB::table('rental_agents')->where('rental_id', $rental->id)->where('user_id', $this->adminA->id)->count(),
            'another agency\'s agent must not be linkable to a rental');
    }

    // ── 2. Dashboard training card ────────────────────────────────────────

    public function test_training_card_shows_only_own_agencys_live_courses(): void
    {
        TrainingCourse::withoutAgencyStamping(fn () => TrainingCourse::create(['agency_id' => $this->agencyA->id, 'title' => 'A Secret Course', 'is_required' => true, 'is_published' => true]));
        $deleted = TrainingCourse::withoutAgencyStamping(fn () => TrainingCourse::create(['agency_id' => $this->agencyB->id, 'title' => 'B Deleted Course', 'is_required' => true, 'is_published' => true]));
        $deleted->delete();
        TrainingCourse::withoutAgencyStamping(fn () => TrainingCourse::create(['agency_id' => $this->agencyB->id, 'title' => 'B Live Course', 'is_required' => true, 'is_published' => true]));

        $this->actingAs($this->adminB);
        $service = app(CommandCentreService::class);
        $method = new \ReflectionMethod($service, 'myTraining');
        $method->setAccessible(true);
        $titles = collect($method->invoke($service, $this->adminB->id)['items'] ?? [])->pluck('title')->all();

        $this->assertContains('B Live Course', $titles);
        $this->assertNotContains('A Secret Course', $titles);
        $this->assertNotContains('B Deleted Course', $titles);
    }

    // ── 3. TV messages ────────────────────────────────────────────────────

    public function test_tv_messages_admin_list_and_edit_are_agency_scoped(): void
    {
        $msgA = TvMessage::withoutAgencyStamping(fn () => TvMessage::create(['agency_id' => $this->agencyA->id, 'message' => 'A only message', 'is_enabled' => true]));

        $this->actingAs($this->adminB)->get(route('admin.tv-messages'))->assertOk()->assertDontSee('A only message');
        $this->actingAs($this->adminB)->post(route('admin.tv-messages.update', $msgA->id), ['message' => 'hijacked'])->assertNotFound();
        $this->actingAs($this->adminB)->post(route('admin.tv-messages.delete', $msgA->id))->assertNotFound();

        $this->assertSame('A only message', DB::table('tv_messages')->where('id', $msgA->id)->value('message'));
        $this->assertNull(DB::table('tv_messages')->where('id', $msgA->id)->value('deleted_at'));
    }

    public function test_tv_message_cannot_target_another_agencys_branch(): void
    {
        $this->actingAs($this->adminB)->post(route('admin.tv-messages.store'), [
            'branch_id' => $this->branchA->id, 'message' => 'Planted on A TV',
        ])->assertSessionHasErrors('branch_id');

        $this->assertDatabaseMissing('tv_messages', ['message' => 'Planted on A TV']);
    }

    public function test_all_branches_message_plays_only_on_its_own_agencys_tvs(): void
    {
        TvMessage::withoutAgencyStamping(fn () => TvMessage::create(['agency_id' => $this->agencyA->id, 'branch_id' => null, 'message' => 'A global', 'is_enabled' => true]));
        TvMessage::withoutAgencyStamping(fn () => TvMessage::create(['agency_id' => $this->agencyB->id, 'branch_id' => null, 'message' => 'B global', 'is_enabled' => true]));

        // TV screens are unauthenticated.
        Auth::logout();
        $onB = TvMessage::query()->activeForBranch($this->branchB->id)->pluck('message')->all();

        $this->assertContains('B global', $onB);
        $this->assertNotContains('A global', $onB);
    }

    // ── 4. Worksheet Market ───────────────────────────────────────────────

    public function test_worksheet_market_lists_only_own_agencys_agents_and_branches(): void
    {
        $this->user($this->agencyA, $this->branchA, 'agent', 'Zara Agencyagent');
        $this->user($this->agencyB, $this->branchB, 'agent', 'Yusuf Ownagent');

        $this->actingAs($this->adminB)->get('/admin/worksheet-market')
            ->assertOk()
            ->assertSee('Yusuf Ownagent')
            ->assertDontSee('Zara Agencyagent')
            ->assertDontSee('A Main');
    }

    // ── 5. Oversight digest ───────────────────────────────────────────────

    public function test_oversight_agency_scope_stays_inside_the_managers_agency_without_a_login(): void
    {
        Role::forceCreate(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $this->agencyB->id, 'oversight_scope' => 'agency']);
        Role::clearCache();
        $agentA = $this->user($this->agencyA, $this->branchA, 'agent', 'Agent Of A');
        $agentB = $this->user($this->agencyB, $this->branchB, 'agent', 'Agent Of B');

        Auth::logout(); // the hourly job has no authenticated user
        $ids = app(OversightService::class)->agentsInScope($this->adminB->fresh())->pluck('id')->all();

        $this->assertContains($agentB->id, $ids);
        $this->assertNotContains($agentA->id, $ids);
    }

    public function test_stale_listings_does_not_match_other_agents_listings(): void
    {
        $agentA = $this->user($this->agencyA, $this->branchA, 'agent', 'Agent Of A');
        $agentB = $this->user($this->agencyB, $this->branchB, 'agent', 'Agent Of B');
        $old = now()->subDays(60);
        foreach ([[$agentA, $this->agencyA, $this->branchA, 'A far expiry'], [$agentB, $this->agencyB, $this->branchB, 'B far expiry']] as [$agent, $agency, $branch, $title]) {
            DB::table('properties')->insert([
                'external_id' => (string) Str::uuid(), 'title' => $title, 'agent_id' => $agent->id,
                'branch_id' => $branch->id, 'agency_id' => $agency->id,
                'expiry_date' => now()->addDays(90)->toDateString(), 'created_at' => $old, 'updated_at' => $old,
            ]);
        }

        Auth::logout();
        $service = app(OversightService::class);
        $method = new \ReflectionMethod($service, 'staleListings');
        $method->setAccessible(true);
        $rows = $method->invoke($service, [$agentB->id], collect([$agentB->id => $agentB]), 24);

        $this->assertCount(1, $rows, 'only the manager\'s own agents\' listings may match');
        $this->assertSame($agentB->id, $rows->first()['agent_id']);
    }

    // ── 6. E-sign resend email ────────────────────────────────────────────

    public function test_resend_email_rejects_a_signing_request_from_another_agencys_document(): void
    {
        Mail::fake();
        $docA = Document::withoutAgencyStamping(fn () => Document::create(['name' => 'A doc', 'owner_id' => $this->adminA->id, 'agency_id' => $this->agencyA->id]));
        $templateA = SignatureTemplate::create(['document_id' => $docA->id, 'document_hash' => Str::random(64), 'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $this->adminA->id]);
        $requestA = SignatureRequest::create([
            'signature_template_id' => $templateA->id, 'party_role' => 'buyer', 'signer_name' => 'A Signer',
            'signer_email' => 'a.signer@example.test', 'token' => Str::random(48), 'token_expires_at' => now()->addDays(14),
            'status' => SignatureRequest::STATUS_PENDING,
        ]);
        $docB = Document::withoutAgencyStamping(fn () => Document::create(['name' => 'B doc', 'owner_id' => $this->adminB->id, 'agency_id' => $this->agencyB->id]));

        $this->actingAs($this->adminB)
            ->post("/docuperfect/documents/{$docB->id}/resend-email/{$requestA->id}")
            ->assertNotFound();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    // ── 7. Portal captures ────────────────────────────────────────────────

    public function test_portal_capture_attach_only_accepts_the_callers_own_free_capture(): void
    {
        $presB = Presentation::withoutAgencyStamping(fn () => Presentation::create([
            'agency_id' => $this->agencyB->id, 'branch_id' => $this->branchB->id, 'created_by_user_id' => $this->adminB->id, 'title' => 'B pres',
        ]));
        $foreign = PortalCapture::factory()->create(['user_id' => $this->adminA->id, 'presentation_id' => null]);
        $own = PortalCapture::factory()->create(['user_id' => $this->adminB->id, 'presentation_id' => null]);

        $this->actingAs($this->adminB)->post("/presentations/{$presB->id}/portal-captures/{$foreign->id}/attach")->assertNotFound();
        $this->assertNull($foreign->fresh()->presentation_id);

        $this->actingAs($this->adminB)->post("/presentations/{$presB->id}/portal-captures/{$own->id}/attach")->assertOk();
        $this->assertSame($presB->id, (int) $own->fresh()->presentation_id);
    }

    // ── 8. Deposit Interest Calculator history ────────────────────────────

    public function test_deposit_calculator_admin_sees_only_own_agencys_calculations(): void
    {
        $make = fn (User $u, string $name) => DepositInterestCalculation::create([
            'user_id' => $u->id, 'property_name' => $name, 'deposit_amount' => 1000, 'invest_date' => '2026-01-01',
            'refund_date' => '2026-02-01', 'total_deposited' => 1000, 'total_interest' => 5, 'grand_total' => 1005, 'breakdown' => [],
        ]);
        $calcA = $make($this->adminA, 'Tenant of Agency A');
        $make($this->user($this->agencyB, $this->branchB, 'agent', 'B Agent'), 'Tenant of Agency B');

        $this->actingAs($this->adminB)->get('/deposit-interest-calculator/history')
            ->assertOk()
            ->assertSee('Tenant of Agency B')
            ->assertDontSee('Tenant of Agency A');
        $this->actingAs($this->adminB)->get("/deposit-interest-calculator/history/{$calcA->id}")->assertForbidden();
        $this->actingAs($this->adminB)->delete("/deposit-interest-calculator/history/{$calcA->id}")->assertForbidden();
        $this->assertDatabaseHas('deposit_interest_calculations', ['id' => $calcA->id]);
    }

    // ── 9. Market position figures ────────────────────────────────────────

    public function test_area_averages_use_only_own_agencys_sold_records(): void
    {
        foreach ([[$this->agencyA, 9_000_000], [$this->agencyB, 1_000_000]] as [$agency, $price]) {
            DB::table('property_sold_records')->insert([
                'suburb' => 'Uvongo', 'sold_price' => $price, 'sold_date' => now()->subMonths(2)->toDateString(),
                'agency_id' => $agency->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $avg = app(MarketDataSnapshotService::class)->calculateAreaAverages('Uvongo', $this->agencyB->id);
        $this->assertEquals(1_000_000, $avg['avg_price'], 'agency A\'s private sold price must not move agency B\'s area average');
    }

    // ── 11. Training lesson progress ──────────────────────────────────────

    public function test_training_progress_cannot_be_recorded_on_another_agencys_lesson(): void
    {
        $courseA = TrainingCourse::withoutAgencyStamping(fn () => TrainingCourse::create(['agency_id' => $this->agencyA->id, 'title' => 'A course', 'is_published' => true]));
        $lessonA = TrainingLesson::create(['course_id' => $courseA->id, 'title' => 'A lesson']);

        $this->actingAs($this->adminB)->post(route('training.start-lesson', $lessonA->id))->assertNotFound();
        $this->assertDatabaseMissing('training_progress', ['user_id' => $this->adminB->id, 'lesson_id' => $lessonA->id]);
    }
}
