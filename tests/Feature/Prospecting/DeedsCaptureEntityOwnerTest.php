<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deeds/CMA ingest owner handling (POST /api/v1/deeds-capture):
 *  - a genuine company owner is CAPTURED as an entity Contact
 *    (contact_kind='entity', entity_name, entity_reg_no) — Task 2, spec §6.1;
 *  - a natural person with a non-SA-ID (foreign national) is NO LONGER blocked
 *    as a company — Task 1 false-positive fix.
 */
final class DeedsCaptureEntityOwnerTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function ingest(array $owner, string $ref): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('v1.deeds-capture'), [
            'source'   => 'cmainfo',
            'captures' => [[
                'source_ref' => $ref,
                'property'   => ['street_number' => '12', 'street_name' => 'Radstock Road', 'suburb' => 'Southbroom', 'erf_number' => '765', 'deeds_office' => 'Pietermaritzburg'],
                'owners'     => [$owner],
            ]],
        ]);
    }

    public function test_company_owner_is_captured_as_an_entity_contact(): void
    {
        $resp = $this->ingest(
            ['name' => 'ACME PROPERTIES (PTY) LTD', 'id_number' => '2015/123456/07', 'id_type' => 'company_reg'],
            'ENTITY-' . Str::random(6)
        );
        $resp->assertOk();

        $contact = Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->first();
        $this->assertNotNull($contact, 'a Contact must be created — the company owner is no longer blocked');
        $this->assertSame(Contact::TYPE_ENTITY, $contact->contact_kind);
        $this->assertSame('ACME PROPERTIES (PTY) LTD', $contact->entity_name);
        $this->assertSame('2015/123456/07', $contact->entity_reg_no);

        // Linked as the tracked property's owner (what cc2's promote loop reads).
        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->firstOrFail();
        $this->assertDatabaseHas('tracked_property_owners', [
            'tracked_property_id' => $tp->id, 'contact_id' => $contact->id,
        ]);

        // Nothing blocked.
        $this->assertSame([], $resp->json('results.0.blocked_companies'));
    }

    public function test_ground_truth_0701_owner_is_captured_as_a_natural_person(): void
    {
        // CMA ref 0701 — "SAUNDERS BRANDON HENRY", ID 721135198089 (a short/
        // invalid SA ID). Previously flagged "company" by the id-validity-only
        // rule and DROPPED. Must now ingest as a natural person.
        $resp = $this->ingest(
            ['name' => 'SAUNDERS BRANDON HENRY', 'id_number' => '721135198089', 'id_type' => 'sa_id'],
            'PERSON-' . Str::random(6)
        );
        $resp->assertOk();

        $contact = Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->first();
        $this->assertNotNull($contact, 'the natural person must be captured, not blocked');
        $this->assertNotSame(Contact::TYPE_ENTITY, $contact->contact_kind, 'a person must NOT be an entity');
        $this->assertSame('721135198089', $contact->id_number);
        $this->assertSame([], $resp->json('results.0.blocked_companies'));
    }

    public function test_entity_owner_dedupes_on_registration_number(): void
    {
        $owner = ['name' => 'SUNSET INVESTMENTS CC', 'id_number' => '2011/998877/23', 'id_type' => 'company_reg'];
        $this->ingest($owner, 'DEDUP-A-' . Str::random(4))->assertOk();
        $this->ingest($owner, 'DEDUP-B-' . Str::random(4))->assertOk();

        $count = Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)
            ->where('entity_reg_no', '2011/998877/23')->count();
        $this->assertSame(1, $count, 'the same entity reg-no must resolve to ONE entity Contact');
    }
}
