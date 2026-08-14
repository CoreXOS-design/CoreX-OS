<?php

declare(strict_types=1);

namespace Tests\Feature\Tva;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUG 2 — the TVA person scrape (by SA ID) must resolve to the SAME director
 * contact and upgrade its INITIALS placeholder ("HA Pretorius", from the
 * directorship table) to the full name ("Hendrik Pretorius") — resolve-and-
 * refresh, never a duplicate, never clobbering a real existing name.
 */
final class TvaPersonNameEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private const DIRECTOR_ID = '7004065141082'; // valid SA ID

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
        $this->user = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        Sanctum::actingAs($this->user);
    }

    private function personScrape(string $first, string $surname): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('v1.tva-contact-capture'), [
            'source'  => 'tva',
            'people'  => [[
                'id_number'  => self::DIRECTOR_ID,
                'first_name' => $first,
                'surname'    => $surname,
                'contacts'   => [['type' => 'cell', 'value' => '0821234567']],
            ]],
        ]);
    }

    public function test_person_scrape_upgrades_initials_to_full_name_no_duplicate(): void
    {
        // Director captured from the directorship table with initials only.
        $director = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'HA', 'last_name' => 'Pretorius',
            'phone' => '', 'id_number' => self::DIRECTOR_ID, 'id_number_source' => 'tva_directorship',
        ]);

        $this->personScrape('Hendrik', 'Pretorius')->assertOk();

        $director->refresh();
        $this->assertSame('Hendrik', $director->first_name, 'initials upgraded to full first name');
        $this->assertSame('Pretorius', $director->last_name);
        $this->assertSame(1, Contact::withoutGlobalScopes()->where('id_number', self::DIRECTOR_ID)->count(), 'no duplicate contact');
    }

    public function test_person_scrape_does_not_overwrite_a_real_existing_name(): void
    {
        // Contact already has a real (non-initials) name — must NOT be clobbered.
        $existing = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Johannes', 'last_name' => 'Pretorius',
            'phone' => '', 'id_number' => self::DIRECTOR_ID,
        ]);

        $this->personScrape('Hendrik', 'Pretorius')->assertOk();

        $existing->refresh();
        $this->assertSame('Johannes', $existing->first_name, 'a real existing first name is preserved');
    }
}
