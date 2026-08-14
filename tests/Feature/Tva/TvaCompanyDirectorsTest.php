<?php

declare(strict_types=1);

namespace Tests\Feature\Tva;

use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * TVA company DIRECTORSHIP capture (POST /api/v1/tva-company-directors):
 * directors become natural-person Contacts linked to the company ENTITY
 * Contact via contact_representatives. No number scraping.
 */
final class TvaCompanyDirectorsTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    private const REG_NO = '201001792823';
    private const COMPANY = '1502 BEAUMONT PROP CC';

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

    private function payload(array $directors = null): array
    {
        return [
            'source'   => 'tva_company',
            'company'  => ['registration_number' => self::REG_NO, 'name' => self::COMPANY],
            'directors' => $directors ?? [
                ['id_number' => '7004065141082', 'full_name' => 'PRETORIUS, HA', 'gender' => 'M'],
                ['id_number' => '8202025009087', 'full_name' => 'NDLOVU, TS', 'gender' => 'F'],
            ],
        ];
    }

    private function entity(): ?Contact
    {
        return Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)
            ->where('entity_reg_no', self::REG_NO)->first();
    }

    public function test_directors_link_to_an_existing_company_entity_contact(): void
    {
        // Entity already created by an earlier CMA/deeds capture.
        $entity = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => self::COMPANY,
            'entity_reg_no' => self::REG_NO, 'first_name' => self::COMPANY, 'last_name' => '', 'phone' => '',
        ]);

        $resp = $this->postJson(route('v1.tva-company-directors'), $this->payload());
        $resp->assertOk();
        $this->assertSame($entity->id, $resp->json('entity_contact_id'), 'must MATCH the existing entity, not create a new one');

        // No duplicate entity.
        $this->assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)
            ->where('entity_reg_no', self::REG_NO)->count());

        // Directors created as natural persons with parsed names.
        $pret = Contact::withoutGlobalScopes()->where('id_number', '7004065141082')->firstOrFail();
        $this->assertSame(Contact::TYPE_NATURAL_PERSON, $pret->contact_kind);
        $this->assertSame('PRETORIUS', $pret->last_name);
        $this->assertSame('HA', $pret->first_name);

        // Both linked to the entity; first director is primary.
        $links = ContactRepresentative::where('entity_contact_id', $entity->id)->get();
        $this->assertCount(2, $links);
        $primary = $links->firstWhere('representative_contact_id', $pret->id);
        $this->assertTrue((bool) $primary->is_primary, 'first director marked primary when entity had none');
    }

    public function test_entity_is_created_when_it_does_not_exist_yet(): void
    {
        $resp = $this->postJson(route('v1.tva-company-directors'), $this->payload());
        $resp->assertOk();

        $entity = $this->entity();
        $this->assertNotNull($entity, 'entity created so the director link has a target');
        $this->assertSame(Contact::TYPE_ENTITY, $entity->contact_kind);
        $this->assertSame(self::COMPANY, $entity->entity_name);
        $this->assertSame(2, ContactRepresentative::where('entity_contact_id', $entity->id)->count());
    }

    public function test_recapture_dedupes_contacts_and_links(): void
    {
        $this->postJson(route('v1.tva-company-directors'), $this->payload())->assertOk();
        $this->postJson(route('v1.tva-company-directors'), $this->payload())->assertOk();

        $this->assertSame(1, Contact::withoutGlobalScopes()->where('agency_id', $this->agencyId)->where('entity_reg_no', self::REG_NO)->count(), 'one entity');
        $this->assertSame(1, Contact::withoutGlobalScopes()->where('id_number', '7004065141082')->count(), 'one director contact per ID');
        $this->assertSame(2, ContactRepresentative::where('entity_contact_id', $this->entity()->id)->count(), 'no duplicate links');
    }

    public function test_directors_land_in_deeds_on_the_reg_matched_property(): void
    {
        // CMA/deeds capture already created the company's property, with the
        // company owner-row keyed on the registration number (as id_number).
        $companyOwner = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Beaumont Prop Cc', 'last_name' => '1502',
            'phone' => '', 'id_number' => self::REG_NO,
        ]);
        $tpId = (int) DB::table('tracked_properties')->insertGetId([
            'agency_id' => $this->agencyId, 'external_id' => (string) Str::uuid(),
            'street_number' => '1502', 'street_name' => 'Beaumont Drive', 'suburb' => 'Ramsgate',
            'capture_kind' => 'deeds_capture', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tracked_property_owners')->insert([
            'tracked_property_id' => $tpId, 'contact_id' => $companyOwner->id,
            'name' => 'Beaumont Prop Cc 1502', 'id_number' => self::REG_NO, 'is_primary' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $resp = $this->postJson(route('v1.tva-company-directors'), $this->payload());
        $resp->assertOk();
        $this->assertSame($tpId, $resp->json('tracked_property_id'), 'directors matched to the company property by reg-no');

        // Each director now surfaces on that property (deeds-capture list) as a DIRECTOR, not an owner.
        $this->assertDatabaseHas('tracked_property_owners', ['tracked_property_id' => $tpId, 'id_number' => '7004065141082', 'role' => 'director']);
        $this->assertDatabaseHas('tracked_property_owners', ['tracked_property_id' => $tpId, 'id_number' => '8202025009087', 'role' => 'director']);
        $pret = DB::table('tracked_property_owners')->where('tracked_property_id', $tpId)->where('id_number', '7004065141082')->first();
        $this->assertEquals(0, (int) $pret->is_primary, 'director is not primary');
        $this->assertSame('director', $pret->role, 'director row is role=director, NOT owner');
        // The company owner row stays role=owner — the company is the sole owner.
        $companyRow = DB::table('tracked_property_owners')->where('tracked_property_id', $tpId)->where('id_number', self::REG_NO)->first();
        $this->assertSame('owner', $companyRow->role, 'the company is the sole owner');
        $this->assertTrue(collect($resp->json('directors'))->firstWhere('id_number', '7004065141082')['landed_in_deeds']);

        // Re-capture does not duplicate the owner row.
        $this->postJson(route('v1.tva-company-directors'), $this->payload())->assertOk();
        $this->assertSame(1, DB::table('tracked_property_owners')->where('tracked_property_id', $tpId)->where('id_number', '7004065141082')->count());
    }

    public function test_no_property_match_still_captures_contacts_and_links(): void
    {
        // Company not CMA-captured yet — tracked_property_id null, but the entity
        // + director contacts + representative links are still created.
        $resp = $this->postJson(route('v1.tva-company-directors'), $this->payload());
        $resp->assertOk();
        $this->assertNull($resp->json('tracked_property_id'));
        $this->assertNotNull($this->entity());
        $this->assertSame(2, ContactRepresentative::where('entity_contact_id', $this->entity()->id)->count());
    }

    public function test_existing_person_by_id_is_reused_not_duplicated(): void
    {
        // The director is already a contact in CoreX (one person, one record — NN#10).
        $existing = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Existing', 'last_name' => 'Pretorius',
            'phone' => '', 'id_number' => '7004065141082',
        ]);

        $this->postJson(route('v1.tva-company-directors'), $this->payload([
            ['id_number' => '7004065141082', 'full_name' => 'PRETORIUS, HA', 'gender' => 'M'],
        ]))->assertOk();

        $this->assertSame(1, Contact::withoutGlobalScopes()->where('id_number', '7004065141082')->count(), 'existing person reused, not duplicated');
        $existing->refresh();
        $this->assertSame('Existing', $existing->first_name, 'existing name preserved (not clobbered)');
        $this->assertSame(1, ContactRepresentative::where('representative_contact_id', $existing->id)->count(), 'existing person linked to the entity');
    }
}
