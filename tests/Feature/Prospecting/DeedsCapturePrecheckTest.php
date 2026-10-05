<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\AgentActivityEvent;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyOwner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deeds-capture pre-check (.ai/specs/deeds-capture.md §9) — the endpoint the
 * Chrome extension calls BEFORE it reveals an owner ID on CMA Info (CMA
 * Info's own paid, R3-per-ID step), so an agent can be warned off a
 * duplicate before that charge is incurred.
 *
 * Wraps TrackedPropertyMatchOrCreateService::findExistingMatch() (proven
 * strategy-by-strategy in TrackedPropertyMatchOrCreateTest) — this file
 * proves the ENDPOINT: request shape, response shape, agency isolation, the
 * new owner-name+address signal (item 2), not-found, and the activity log.
 */
final class DeedsCapturePrecheckTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'admin',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function check(array $payload): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('v1.deeds-capture.check-duplicate'), $payload);
    }

    public function test_not_found_when_nothing_matches(): void
    {
        $response = $this->check([
            'property' => ['erf_number' => '99999', 'suburb' => 'Nowhere Beach'],
        ]);

        $response->assertOk()->assertJson(['status' => 'not_found', 'matches' => []]);
    }

    public function test_exists_via_erf_and_suburb_structural_match(): void
    {
        $captureUser = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $tp = TrackedProperty::create([
            'agency_id'              => $this->agency->id,
            'capture_kind'           => 'deeds_capture',
            'street_number'          => '37',
            'street_name'            => 'Bairn Road',
            'suburb'                 => 'Uvongo Beach',
            'erf_number'             => '234',
            'deeds_captured_by_user_id' => $captureUser->id,
            'deeds_captured_at'      => now()->subDays(3),
            'source_chain'           => [],
        ]);

        $response = $this->check([
            'property' => ['erf_number' => '234', 'suburb' => 'Uvongo Beach'],
        ]);

        $response->assertOk();
        $json = $response->json();
        $this->assertSame('exists', $json['status']);
        $this->assertCount(1, $json['matches']);
        $match = $json['matches'][0];
        $this->assertSame($tp->id, $match['tracked_property_id']);
        $this->assertSame('structural', $match['match_type']);
        $this->assertTrue($match['confident']);
        $this->assertSame($captureUser->name, $match['captured_by']);
        $this->assertNotNull($match['captured_at']);
        $this->assertStringContainsString('erf', mb_strtolower($match['reason']));
        $this->assertStringContainsString('open=tp-' . $tp->id, $match['deeplink']);
    }

    public function test_exists_via_source_ref_structural_match(): void
    {
        // matchOrCreate() both creates the TP AND writes the external ref —
        // the real mechanism a live ingest uses, same as content-cmainfo.js's
        // buildSourceRef() + a prior capture of this exact property.
        $tp = app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class)->matchOrCreate(
            $this->agency->id,
            ['street_name' => 'Lilliecrona Drive', 'suburb' => 'Manaba Beach'],
            ['type' => 'deeds_capture', 'ref' => 'cmainfo:natspat-4']
        );

        // Property facts deliberately point somewhere else entirely — only
        // the matching source_ref should resolve this, proving strategy 1
        // (source-ref exact) is reachable through the new endpoint.
        $response = $this->check([
            'source_ref' => 'cmainfo:natspat-4',
            'property'   => ['street_name' => 'A Totally Different Street', 'suburb' => 'A Totally Different Suburb'],
        ]);

        $response->assertOk();
        $json = $response->json();
        $this->assertSame('exists', $json['status']);
        $this->assertSame($tp->id, $json['matches'][0]['tracked_property_id']);
        $this->assertStringContainsString('Same source reference', $json['matches'][0]['reason']);
    }

    public function test_cross_agency_tracked_property_is_never_revealed(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Agency ' . uniqid(), 'slug' => 'other-' . uniqid()]);
        // withoutAgencyStamping() — otherwise BelongsToAgency's creating()
        // hook force-overrides agency_id to the CURRENTLY ACTING user's own
        // agency ($this->user, from setUp()) regardless of what's passed
        // here, which would silently defeat this exact test (the TP would
        // land in $this->agency, not $otherAgency, and "not found" would
        // pass for the wrong reason — same-agency isolation, not cross-).
        TrackedProperty::withoutAgencyStamping(fn () => TrackedProperty::create([
            'agency_id'    => $otherAgency->id,
            'capture_kind' => 'deeds_capture',
            'street_number' => '37',
            'street_name'   => 'Bairn Road',
            'suburb'        => 'Uvongo Beach',
            'erf_number'    => '234',
            'source_chain'  => [],
        ]));

        $response = $this->check([
            'property' => ['erf_number' => '234', 'suburb' => 'Uvongo Beach'],
        ]);

        $response->assertOk()->assertJson(['status' => 'not_found', 'matches' => []]);
    }

    public function test_possible_match_via_owner_name_and_suburb_when_structurally_unmatched(): void
    {
        $tp = TrackedProperty::create([
            'agency_id'    => $this->agency->id,
            'capture_kind' => 'deeds_capture',
            'street_number' => '12',
            'street_name'   => 'Park Street',
            'suburb'        => 'Margate',
            'erf_number'    => '4521',
            'source_chain'  => [],
        ]);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Mary', 'last_name' => 'Jones',
        ]);
        TrackedPropertyOwner::create([
            'tracked_property_id' => $tp->id,
            'contact_id'          => $contact->id,
            'name'                => 'JONES MARY',
            'role'                => TrackedPropertyOwner::ROLE_OWNER,
        ]);

        // Structurally unrelated identity (different erf/street) so strategies 0-5
        // in resolveMatch() find nothing — ONLY the owner-name+suburb signal fires.
        $response = $this->check([
            'property' => ['erf_number' => '999999', 'street_name' => 'Totally Different Road', 'suburb' => 'Margate'],
            'owners'   => [['name' => 'MARY JONES']],
        ]);

        $response->assertOk();
        $json = $response->json();
        $this->assertSame('possible_match', $json['status']);
        $this->assertCount(1, $json['matches']);
        $match = $json['matches'][0];
        $this->assertSame($tp->id, $match['tracked_property_id']);
        $this->assertSame('owner_name', $match['match_type']);
        $this->assertFalse($match['confident']);
        $this->assertContains('owner_name', $match['matched_fields']);
        $this->assertContains('suburb', $match['matched_fields']);
    }

    public function test_owner_name_alone_with_no_address_overlap_is_not_a_match(): void
    {
        $tp = TrackedProperty::create([
            'agency_id'    => $this->agency->id,
            'capture_kind' => 'deeds_capture',
            'suburb'       => 'Margate',
            'source_chain' => [],
        ]);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Mary', 'last_name' => 'Jones',
        ]);
        TrackedPropertyOwner::create([
            'tracked_property_id' => $tp->id,
            'contact_id'          => $contact->id,
            'name'                => 'JONES MARY',
            'role'                => TrackedPropertyOwner::ROLE_OWNER,
        ]);

        $response = $this->check([
            'property' => ['suburb' => 'A Completely Different Suburb'],
            'owners'   => [['name' => 'MARY JONES']],
        ]);

        $response->assertOk()->assertJson(['status' => 'not_found', 'matches' => []]);
    }

    public function test_entity_owner_matches_on_exact_registered_name(): void
    {
        $tp = TrackedProperty::create([
            'agency_id'    => $this->agency->id,
            'capture_kind' => 'deeds_capture',
            'street_number' => '58',
            'street_name'   => 'Avenue Svea',
            'suburb'        => 'Shelly Beach',
            'erf_number'    => '7001',
            'source_chain'  => [],
        ]);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Beachfront',
            'last_name' => 'Investments CC', 'contact_kind' => 'entity',
        ]);
        TrackedPropertyOwner::create([
            'tracked_property_id' => $tp->id,
            'contact_id'          => $contact->id,
            'name'                => 'BEACHFRONT INVESTMENTS CC',
            'id_type'             => 'company_reg',
            'role'                => TrackedPropertyOwner::ROLE_OWNER,
        ]);

        $response = $this->check([
            'property' => ['erf_number' => '999999', 'suburb' => 'Shelly Beach'],
            'owners'   => [['name' => 'BEACHFRONT INVESTMENTS CC', 'id_type' => 'company_reg']],
        ]);

        $response->assertOk();
        $json = $response->json();
        $this->assertSame('possible_match', $json['status']);
        $this->assertSame($tp->id, $json['matches'][0]['tracked_property_id']);
    }

    public function test_precheck_outcome_is_logged_to_agent_activity_events(): void
    {
        $this->check(['property' => ['erf_number' => '1', 'suburb' => 'Nowhere']])->assertOk();

        $this->assertDatabaseHas('agent_activity_events', [
            'agency_id'  => $this->agency->id,
            'user_id'    => $this->user->id,
            'event_type' => 'deeds_capture.precheck.not_found',
        ]);
    }

    public function test_decision_endpoint_logs_pulled_anyway_and_cancelled(): void
    {
        $this->postJson(route('v1.deeds-capture.check-duplicate.decision'), [
            'decision'   => 'pulled_anyway',
            'source_ref' => 'cmainfo:erf-1',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->postJson(route('v1.deeds-capture.check-duplicate.decision'), [
            'decision'   => 'cancelled',
            'source_ref' => 'cmainfo:erf-2',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('agent_activity_events', [
            'agency_id' => $this->agency->id, 'event_type' => 'deeds_capture.precheck.pulled_anyway',
        ]);
        $this->assertDatabaseHas('agent_activity_events', [
            'agency_id' => $this->agency->id, 'event_type' => 'deeds_capture.precheck.cancelled',
        ]);
    }

    public function test_decision_endpoint_rejects_an_unknown_decision_value(): void
    {
        $this->postJson(route('v1.deeds-capture.check-duplicate.decision'), [
            'decision' => 'maybe-later',
        ])->assertStatus(422);
    }

    // ── Input-space: optional fields omitted / malformed (BUILD_STANDARD §2/§5) ──

    public function test_empty_property_array_is_accepted_gracefully_and_returns_not_found(): void
    {
        $this->check(['property' => []])->assertOk()->assertJson(['status' => 'not_found', 'matches' => []]);
    }

    public function test_owners_with_blank_name_are_skipped_not_errored(): void
    {
        $this->check([
            'property' => ['suburb' => 'Margate'],
            'owners'   => [['name' => '   '], ['name' => '']],
        ])->assertOk()->assertJson(['status' => 'not_found', 'matches' => []]);
    }

    public function test_malformed_latitude_is_rejected_with_a_422_not_a_500(): void
    {
        $this->check([
            'property' => ['suburb' => 'Margate', 'latitude' => 'not-a-number'],
        ])->assertStatus(422);
    }

    public function test_missing_agency_context_is_rejected_not_500(): void
    {
        // Raw insert, deliberately bypassing Eloquent entirely — User uses
        // BOTH BelongsToAgency (force-stamps agency_id from whoever is
        // currently acting, no escape for an explicit null while
        // $this->user is still authenticated) AND BelongsToBranch (fills
        // branch_id from the acting user's OWN branch whenever the given
        // value is empty — a true null has no suppression flag at all,
        // unlike BelongsToAgency's withoutAgencyStamping()). A raw DB
        // insert runs neither creating() hook, so this is the only way to
        // actually persist a user with agency_id/branch_id both genuinely
        // null in this test.
        $tokenlessId = DB::table('users')->insertGetId([
            'name' => 'Tokenless Test User', 'email' => 'tokenless-' . uniqid() . '@example.test',
            'password' => Hash::make('password'), 'role' => 'admin',
            'agency_id' => null, 'branch_id' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // A null-agency_id row is an orphan under AgencyScope and would be
        // invisible to a plain find() while $this->user (a different
        // agency) is the acting user — read it back unscoped.
        $tokenless = User::queryWithoutAgencyScope()->find($tokenlessId);
        Sanctum::actingAs($tokenless);

        $this->check(['property' => ['suburb' => 'Margate']])->assertStatus(403);
    }
}
