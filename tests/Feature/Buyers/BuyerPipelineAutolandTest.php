<?php

declare(strict_types=1);

namespace Tests\Feature\Buyers;

use App\Models\AgencyContactSettings;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-72 — Buyer Pillar Build 2: auto-land buyers on the pipeline.
 *
 * Rule: creating a COUNTABLE wishlist (ContactMatch, isCountable() per AT-71)
 * makes the contact a buyer (is_buyer=true) and lands them on the Buyer
 * Pipeline as buyer_state='new', with an audited transition. An empty
 * (uncountable) wishlist does NOT land a buyer. An existing state is never
 * reset. The backfill command lands pre-AT-72 countable buyers idempotently.
 */
final class BuyerPipelineAutolandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyContactSettings::clearMinCountableCache();
        // Neutralise the AT-71 freshness job (saved()/deleted() dispatch).
        // Auto-land is a synchronous service call in created(), unaffected.
        Bus::fake();
    }

    // ── Auto-land on a countable wishlist ────────────────────────────────

    public function test_countable_wishlist_lands_a_new_buyer_on_the_pipeline(): void
    {
        [$agencyId] = $this->fixture();
        $c = $this->prospect($agencyId); // is_buyer=false, buyer_state=NULL

        $this->match($agencyId, $c->id, ['price_min' => 1_500_000]); // countable

        $c->refresh();
        $this->assertTrue((bool) $c->is_buyer, 'contact should become a buyer');
        $this->assertSame('new', $c->buyer_state, 'buyer should land in New');
        $this->assertNotNull($c->buyer_pipeline_entered_at, 'pipeline-entered stamp should be set');

        $this->assertDatabaseHas('buyer_state_transitions', [
            'contact_id' => $c->id,
            'from_state' => null,
            'to_state'   => 'new',
            'reason'     => 'wishlist_created',
        ]);
    }

    public function test_landed_buyer_appears_in_the_pipeline_new_column_query(): void
    {
        [$agencyId, $agent] = $this->fixture();
        $c = $this->prospect($agencyId);
        $this->match($agencyId, $c->id, ['beds_min' => 3]); // countable

        // Mirror BuyerPipelineController: buyers() + group by buyer_state.
        $newColumn = Contact::withoutGlobalScopes([AgencyScope::class, BranchScope::class])
            ->buyers()
            ->where('buyer_state', 'new')
            ->pluck('id');

        $this->assertContains($c->id, $newColumn->all(), 'landed buyer must show in the New column');
    }

    // ── Empty wishlist does NOT land ─────────────────────────────────────

    public function test_empty_wishlist_does_not_auto_land(): void
    {
        [$agencyId] = $this->fixture();
        $c = $this->prospect($agencyId);

        $this->match($agencyId, $c->id, []); // completely empty → uncountable

        $c->refresh();
        $this->assertFalse((bool) $c->is_buyer, 'empty wishlist must not flag is_buyer');
        $this->assertNull($c->buyer_state, 'empty wishlist must not set a buyer_state');
        $this->assertDatabaseMissing('buyer_state_transitions', ['contact_id' => $c->id]);
    }

    // ── Existing state is never reset ────────────────────────────────────

    public function test_existing_warm_buyer_stays_warm_when_adding_a_wishlist(): void
    {
        [$agencyId] = $this->fixture();
        $c = $this->prospect($agencyId, ['is_buyer' => true, 'buyer_state' => 'warm']);

        $this->match($agencyId, $c->id, ['price_max' => 2_000_000]); // countable

        $c->refresh();
        $this->assertSame('warm', $c->buyer_state, 'an existing Warm buyer must NOT be reset to New');
        $this->assertDatabaseMissing('buyer_state_transitions', [
            'contact_id' => $c->id,
            'to_state'   => 'new',
        ]);
    }

    // ── Idempotency: multiple wishlists land once ────────────────────────

    public function test_multiple_countable_wishlists_land_the_buyer_once(): void
    {
        [$agencyId] = $this->fixture();
        $c = $this->prospect($agencyId);

        $this->match($agencyId, $c->id, ['price_min' => 1_000_000]);
        $this->match($agencyId, $c->id, ['beds_min' => 4]);
        $this->match($agencyId, $c->id, ['property_types' => ['house']]);

        $c->refresh();
        $this->assertSame('new', $c->buyer_state);

        $newTransitions = DB::table('buyer_state_transitions')
            ->where('contact_id', $c->id)
            ->where('to_state', 'new')
            ->count();
        $this->assertSame(1, $newTransitions, 'only ONE auto-land transition should be recorded');
    }

    // ── Backfill command ─────────────────────────────────────────────────

    public function test_backfill_command_lands_pre_at72_countable_buyer_and_is_idempotent(): void
    {
        [$agencyId] = $this->fixture();
        $c = $this->prospect($agencyId);

        // Simulate a pre-AT-72 wishlist created WITHOUT the auto-land hook.
        ContactMatch::withoutEvents(function () use ($agencyId, $c) {
            ContactMatch::withoutGlobalScopes()->create([
                'agency_id'    => $agencyId,
                'contact_id'   => $c->id,
                'status'       => ContactMatch::STATUS_ACTIVE,
                'listing_type' => 'sale',
                'price_min'    => 1_250_000, // countable
            ]);
        });

        $c->refresh();
        $this->assertNull($c->buyer_state, 'pre-AT-72 wishlist left the buyer with no state');

        // Run the backfill.
        $this->artisan('buyers:autoland-pipeline', ['--agency' => $agencyId])
            ->assertExitCode(0);

        $c->refresh();
        $this->assertTrue((bool) $c->is_buyer);
        $this->assertSame('new', $c->buyer_state, 'backfill should land the buyer on New');

        // Re-run: idempotent — no reset, no duplicate transition.
        $this->artisan('buyers:autoland-pipeline', ['--agency' => $agencyId])
            ->assertExitCode(0);

        $c->refresh();
        $this->assertSame('new', $c->buyer_state);
        $this->assertSame(1, DB::table('buyer_state_transitions')
            ->where('contact_id', $c->id)->where('to_state', 'new')->count(),
            'backfill must not double-land');
    }

    public function test_backfill_skips_empty_wishlist_contacts(): void
    {
        [$agencyId] = $this->fixture();
        $c = $this->prospect($agencyId);

        ContactMatch::withoutEvents(function () use ($agencyId, $c) {
            ContactMatch::withoutGlobalScopes()->create([
                'agency_id'    => $agencyId,
                'contact_id'   => $c->id,
                'status'       => ContactMatch::STATUS_ACTIVE,
                'listing_type' => 'sale',
                // no criteria → uncountable
            ]);
        });

        $this->artisan('buyers:autoland-pipeline', ['--agency' => $agencyId])
            ->assertExitCode(0);

        $c->refresh();
        $this->assertNull($c->buyer_state, 'an empty-wishlist contact must NOT be landed by backfill');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function fixture(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin',
        ]);
        return [$agencyId, $agent];
    }

    /** A plain contact — NOT yet a buyer (is_buyer=false, buyer_state=NULL) unless overridden. */
    private function prospect(int $agencyId, array $extra = []): Contact
    {
        return Contact::withoutGlobalScopes()->create(array_merge([
            'agency_id'  => $agencyId, 'branch_id' => $agencyId,
            'is_buyer'   => false, 'buyer_state' => null,
            'first_name' => 'Thandi', 'last_name' => 'Nkosi',
            'phone'      => '082' . random_int(1000000, 9999999),
            'email'      => 'thandi-' . Str::random(5) . '@example.co.za',
        ], $extra));
    }

    private function match(int $agencyId, int $contactId, array $extra): ContactMatch
    {
        return ContactMatch::withoutGlobalScopes()->create(array_merge([
            'agency_id' => $agencyId, 'contact_id' => $contactId,
            'status' => ContactMatch::STATUS_ACTIVE, 'listing_type' => 'sale',
        ], $extra));
    }
}
