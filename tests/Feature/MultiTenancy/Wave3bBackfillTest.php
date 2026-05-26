<?php

namespace Tests\Feature\MultiTenancy;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\ContactSource;
use App\Models\Deal;
use App\Models\DealMoneyLine;
use App\Models\Property;
use App\Models\PropertyNote;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks in the Wave 3b additions: every newly-traited model auto-fills
 * agency_id from the authenticated user, AND the "single-agency fallback"
 * baked into BelongsToAgency fires ONLY when exactly one agency row exists.
 *
 * If a second agency exists and no auth user is present, the fallback MUST
 * NOT fire — otherwise unauthenticated console code could leak between
 * tenants by stamping the wrong agency_id.
 */
class Wave3bBackfillTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'A', 'slug' => 'a']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'admin',
        ]);
    }

    private function makeProperty(int $agencyId, int $branchId, int $agentId): Property
    {
        return Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id'     => $agencyId,
            'agent_id'      => $agentId,
            'branch_id'     => $branchId,
            'external_id'   => (string) Str::uuid(),
            'title'         => 'P',
            'suburb'        => 'Margate',
            'property_type' => 'house',
            'status'        => 'draft',
            'price'         => 0,
        ]);
    }

    private function makeContact(int $agencyId, int $branchId, int $userId): Contact
    {
        return Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id'          => $agencyId,
            'branch_id'          => $branchId,
            'created_by_user_id' => $userId,
            'first_name'         => 'C',
            'last_name'          => 'X',
            'phone'              => '0830000000',
        ]);
    }

    private function makeDeal(int $agencyId, int $branchId): Deal
    {
        return Deal::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id'        => $agencyId,
            'branch_id'        => $branchId,
            'period'           => '2026-05',
            'deal_date'        => '2026-05-01',
            'property_value'   => 100,
            'total_commission' => 10,
        ]);
    }

    // ──────────── A. agency_id auto-fills from auth user ────────────

    public function test_deal_money_line_inherits_agency_id_from_auth_user(): void
    {
        $this->actingAs($this->user);
        $deal = $this->makeDeal($this->agency->id, $this->branch->id);

        $line = DealMoneyLine::create([
            'deal_id'   => $deal->id,
            'user_id'   => $this->user->id,
            'period'    => '2026-05',
            'branch_id' => $this->branch->id,
            'side'      => 'listing',
        ]);

        $this->assertSame($this->agency->id, (int) $line->agency_id);
    }

    public function test_property_note_inherits_agency_id_from_auth_user(): void
    {
        $this->actingAs($this->user);
        $property = $this->makeProperty($this->agency->id, $this->branch->id, $this->user->id);

        $note = PropertyNote::create([
            'property_id' => $property->id,
            'user_id'     => $this->user->id,
            'content'     => 'hi',
        ]);

        $this->assertSame($this->agency->id, (int) $note->agency_id);
    }

    public function test_contact_note_inherits_agency_id_from_auth_user(): void
    {
        $this->actingAs($this->user);
        $contact = $this->makeContact($this->agency->id, $this->branch->id, $this->user->id);

        $note = ContactNote::create([
            'contact_id' => $contact->id,
            'user_id'    => $this->user->id,
            'body'       => 'hi',
        ]);

        $this->assertSame($this->agency->id, (int) $note->agency_id);
    }

    public function test_contact_source_inherits_agency_id_from_auth_user(): void
    {
        $this->actingAs($this->user);

        $source = ContactSource::create(['name' => 'Walk-in']);

        $this->assertSame($this->agency->id, (int) $source->agency_id);
    }

    public function test_contact_inherits_agency_id_from_auth_user(): void
    {
        $this->actingAs($this->user);

        $contact = Contact::create([
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $this->user->id,
            'first_name'         => 'Auto',
            'last_name'          => 'Fill',
            'phone'              => '0830000001',
        ]);

        $this->assertSame($this->agency->id, (int) $contact->agency_id);
    }

    // ─────────────── B. Single-agency fallback semantics ───────────────
    //
    // The trait's "console/seeder fallback" only fires when exactly one
    // agency row exists in the DB. With multiple agencies and no auth user,
    // it MUST NOT guess — that would be the very cross-agency leak we are
    // hardening against. The test DB ships with a pre-seeded HFC Coastal
    // agency and setUp() adds one more, so we already have multiple
    // agencies for the "must not fire" assertion below.

    public function test_single_agency_fallback_does_not_fire_when_multiple_agencies_exist(): void
    {
        // Ensure 2+ agencies exist (test-DB baseline already gives us this).
        $this->assertGreaterThanOrEqual(2, Agency::count(),
            'Test fixture invariant: multiple agencies must exist for this scenario.');

        // No actingAs() — Auth::user() is null. With 2+ agencies and no auth,
        // the trait must NOT stamp any agency_id (that would be a leak —
        // arbitrarily picking one agency's data over the other). The DB
        // schema enforces agency_id NOT NULL, so the insert MUST throw
        // rather than silently land in the wrong agency.
        $this->expectException(\Illuminate\Database\QueryException::class);

        ContactSource::withoutGlobalScope(AgencyScope::class)
            ->create(['name' => 'Multi-Agency Source ' . uniqid()]);
    }
}
