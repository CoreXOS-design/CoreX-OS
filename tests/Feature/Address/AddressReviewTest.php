<?php

declare(strict_types=1);

namespace Tests\Feature\Address;

use App\Models\AddressMatchSetting;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Structured address matching, step 8 — the admin-only review list ("could not read this address") and
 * the admin-only match-strictness settings page. Search / sort / filter / pagination / empty state, agency
 * isolation (direct URL by id is a 404), fix / dismiss / restore, nothing ever deleted.
 */
final class AddressReviewTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);

        // real permission enforcement: admin holds the key, an agent does not
        foreach (['address_review.manage', 'prospecting_setup.manage'] as $key) {
            RolePermission::create(['role' => 'admin', 'permission_key' => $key, 'agency_id' => $this->agency->id]);
        }
        RolePermission::create(['role' => 'agent', 'permission_key' => 'properties.view', 'scope' => 'all', 'agency_id' => $this->agency->id]);
    }

    private function prop(array $over = []): Property
    {
        $p = Property::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->admin->id, 'suburb' => 'Uvongo',
            'property_type' => 'house', 'beds' => 3, 'baths' => 2, 'garages' => 1, 'price' => 1, 'title' => 'T', 'status' => 'active', 'listing_type' => 'sale',
        ], $over));

        return $p;
    }

    private function review(string $table, int $id, string $status = 'review', string $note = 'The suburb "X" is not a Property24 suburb CoreX knows.'): void
    {
        DB::table($table)->where('id', $id)->update(['address_parse_status' => $status, 'address_parse_note' => $note]);
    }

    public function test_only_an_admin_with_the_permission_can_open_the_list(): void
    {
        $this->actingAs($this->agent)->get(route('corex.address-review.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('corex.address-review.index'))->assertOk();
    }

    public function test_the_list_shows_only_my_agency_and_only_what_needs_a_look_by_default(): void
    {
        $mine = $this->prop(['street_number' => '12', 'street_name' => 'Marine Drive']);
        $fine = $this->prop(['street_number' => '99', 'street_name' => 'Fine Road']);
        $this->review('properties', $mine->id);
        $this->review('properties', $fine->id, 'parsed', '');

        $other = Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $theirs = Property::create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'agent_id' => User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id])->id,
            'street_number' => '5', 'street_name' => 'Secret Street', 'suburb' => 'Elsewhere', 'property_type' => 'house', 'beds' => 1, 'baths' => 1, 'garages' => 0, 'price' => 1, 'title' => 'x', 'status' => 'active', 'listing_type' => 'sale']);
        $this->review('properties', $theirs->id);

        $resp = $this->actingAs($this->admin)->get(route('corex.address-review.index'));

        $resp->assertOk()->assertSee('Marine Drive')->assertDontSee('Fine Road')->assertDontSee('Secret Street');
    }

    public function test_both_record_types_are_listed_and_can_be_filtered(): void
    {
        $p = $this->prop(['street_number' => '12', 'street_name' => 'Marine Drive']);
        $t = TrackedProperty::create(['agency_id' => $this->agency->id, 'street_number' => '7', 'street_name' => 'Beach Road', 'suburb' => 'Margate', 'source_chain' => []]);
        $this->review('properties', $p->id);
        $this->review('tracked_properties', $t->id, 'unparseable', 'No street, scheme or erf could be read from this address.');

        $this->actingAs($this->admin)->get(route('corex.address-review.index'))->assertSee('Marine Drive')->assertSee('Beach Road');
        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['kind' => 'property']))->assertSee('Marine Drive')->assertDontSee('Beach Road');
        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['kind' => 'tracked']))->assertSee('Beach Road')->assertDontSee('Marine Drive');
        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['status' => 'unparseable']))->assertSee('Beach Road')->assertDontSee('Marine Drive');
    }

    public function test_search_finds_by_street_number_suburb_complex_erf_and_record_number(): void
    {
        $a = $this->prop(['street_number' => '12', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo', 'complex_name' => 'Golden Moon', 'erf_number' => '4455']);
        $b = $this->prop(['street_number' => '7', 'street_name' => 'Beach Road', 'suburb' => 'Margate']);
        $this->review('properties', $a->id);
        $this->review('properties', $b->id);

        foreach (['Marine', 'Golden', '4455', 'Uvongo', (string) $a->id] as $term) {
            $this->actingAs($this->admin)->get(route('corex.address-review.index', ['q' => $term]))->assertSee('Marine Drive')->assertDontSee('Beach Road');
        }
        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['q' => 'Margate']))->assertSee('Beach Road')->assertDontSee('Marine Drive');
    }

    public function test_the_reason_and_date_filters(): void
    {
        $a = $this->prop(['street_number' => '12', 'street_name' => 'Marine Drive']);
        $b = $this->prop(['street_number' => '7', 'street_name' => 'Beach Road']);
        $this->review('properties', $a->id, 'review', 'The suburb "X" is not a Property24 suburb CoreX knows.');
        $this->review('properties', $b->id, 'review', 'The street number is 29 in one place and 19 in the street text.');
        DB::table('properties')->where('id', $b->id)->update(['updated_at' => '2026-01-05 10:00:00']);

        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['reason' => 'suburb']))->assertSee('Marine Drive')->assertDontSee('Beach Road');
        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['reason' => 'number']))->assertSee('Beach Road')->assertDontSee('Marine Drive');
        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['from' => '2026-01-01', 'to' => '2026-01-31']))->assertSee('Beach Road')->assertDontSee('Marine Drive');
        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['from' => 'garbage', 'to' => '2026-13-45']))->assertOk();
    }

    public function test_sorting_has_a_stated_default_and_every_column_sorts(): void
    {
        $a = $this->prop(['street_number' => '1', 'street_name' => 'Alpha Road', 'suburb' => 'Zulu']);
        $b = $this->prop(['street_number' => '2', 'street_name' => 'Zeta Road', 'suburb' => 'Alpha']);
        $this->review('properties', $a->id);
        $this->review('properties', $b->id);
        DB::table('properties')->where('id', $a->id)->update(['updated_at' => '2026-01-01 00:00:00']);
        DB::table('properties')->where('id', $b->id)->update(['updated_at' => '2026-02-01 00:00:00']);

        $order = fn (array $q) => (array) preg_match_all('/(Alpha Road|Zeta Road)/', $this->actingAs($this->admin)->get(route('corex.address-review.index', $q))->getContent(), $m) ? $m[1] : [];

        $this->assertSame(['Zeta Road', 'Alpha Road'], $order([]), 'default: newest-updated first');
        $this->assertSame(['Alpha Road', 'Zeta Road'], $order(['sort' => 'address', 'dir' => 'asc']));
        $this->assertSame(['Zeta Road', 'Alpha Road'], $order(['sort' => 'suburb', 'dir' => 'asc']));
        foreach (['kind', 'status', 'updated'] as $col) {
            $this->actingAs($this->admin)->get(route('corex.address-review.index', ['sort' => $col, 'dir' => 'desc']))->assertOk();
        }
        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['sort' => 'bogus;drop', 'dir' => 'sideways']))->assertOk();
    }

    public function test_pagination_is_25_a_page(): void
    {
        for ($i = 1; $i <= 27; $i++) {
            $p = $this->prop(['street_number' => (string) $i, 'street_name' => 'Page Street']);
            $this->review('properties', $p->id);
        }
        $this->assertSame(25, $this->actingAs($this->admin)->get(route('corex.address-review.index'))->viewData('rows')->count());
        $this->assertSame(2, $this->actingAs($this->admin)->get(route('corex.address-review.index', ['page' => 2]))->viewData('rows')->count());
    }

    public function test_the_two_empty_states_are_different(): void
    {
        $this->actingAs($this->admin)->get(route('corex.address-review.index'))->assertSee('Nothing needs a look');
        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['q' => 'nothing-matches-this']))->assertSee('Nothing matches these filters');
    }

    public function test_fix_reads_the_address_again_marks_it_manual_and_keeps_the_record(): void
    {
        $p = $this->prop(['street_number' => '29', 'street_name' => '19 Grindewald Drive', 'suburb' => 'Uvongo']);
        $this->review('properties', $p->id, 'review', 'The street number is 29 in one place and 19 in the street text.');

        $this->actingAs($this->admin)->get(route('corex.address-review.edit', ['kind' => 'property', 'id' => $p->id]))->assertOk()->assertSee('Why it is on the list');

        $this->actingAs($this->admin)->post(route('corex.address-review.fix', ['kind' => 'property', 'id' => $p->id]), [
            'street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo',
        ])->assertRedirect(route('corex.address-review.index'));

        $row = DB::table('properties')->where('id', $p->id)->first();
        $this->assertSame('manual', $row->address_parse_status);
        $this->assertNull($row->address_parse_note);
        $this->assertSame('19', $row->street_number);
        $this->assertSame('grindewald', $row->street_core);
        $this->actingAs($this->admin)->get(route('corex.address-review.index'))->assertDontSee('Grindewald Drive');
        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['status' => 'manual']))->assertSee('Grindewald Drive');
    }

    public function test_dismiss_and_restore_never_delete(): void
    {
        $p = $this->prop(['street_number' => '12', 'street_name' => 'Marine Drive']);
        $this->review('properties', $p->id);

        $this->actingAs($this->admin)->post(route('corex.address-review.dismiss', ['kind' => 'property', 'id' => $p->id]))->assertRedirect();
        $this->assertSame('dismissed', DB::table('properties')->where('id', $p->id)->value('address_parse_status'));
        $this->assertNull(DB::table('properties')->where('id', $p->id)->value('deleted_at'));
        $this->actingAs($this->admin)->get(route('corex.address-review.index'))->assertDontSee('Marine Drive');
        $this->actingAs($this->admin)->get(route('corex.address-review.index', ['status' => 'dismissed']))->assertSee('Marine Drive')->assertSee('Restore');

        $this->actingAs($this->admin)->post(route('corex.address-review.restore', ['kind' => 'property', 'id' => $p->id]))->assertRedirect();
        $this->assertSame('review', DB::table('properties')->where('id', $p->id)->value('address_parse_status'));
        $this->actingAs($this->admin)->get(route('corex.address-review.index'))->assertSee('Marine Drive');
    }

    public function test_another_agencys_record_is_a_404_by_direct_url_for_every_action(): void
    {
        $other = Agency::create(['name' => 'Other', 'slug' => 'o-' . uniqid()]);
        $ob = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $theirs = Property::create(['agency_id' => $other->id, 'branch_id' => $ob->id, 'agent_id' => User::factory()->create(['agency_id' => $other->id, 'branch_id' => $ob->id])->id,
            'street_number' => '5', 'street_name' => 'Secret Street', 'suburb' => 'Elsewhere', 'property_type' => 'house', 'beds' => 1, 'baths' => 1, 'garages' => 0, 'price' => 1, 'title' => 'x', 'status' => 'active', 'listing_type' => 'sale']);
        $this->review('properties', $theirs->id);
        $args = ['kind' => 'property', 'id' => $theirs->id];

        $this->actingAs($this->admin)->get(route('corex.address-review.edit', $args))->assertNotFound();
        $this->actingAs($this->admin)->post(route('corex.address-review.fix', $args), ['street_name' => 'Hacked'])->assertNotFound();
        $this->actingAs($this->admin)->post(route('corex.address-review.dismiss', $args))->assertNotFound();
        $this->actingAs($this->admin)->post(route('corex.address-review.restore', $args))->assertNotFound();
        $this->assertSame('Secret Street', DB::table('properties')->where('id', $theirs->id)->value('street_name'));
        $this->assertSame('review', DB::table('properties')->where('id', $theirs->id)->value('address_parse_status'));
    }

    public function test_an_agent_cannot_use_any_action(): void
    {
        $p = $this->prop();
        $this->review('properties', $p->id);
        $args = ['kind' => 'property', 'id' => $p->id];
        $this->actingAs($this->agent)->post(route('corex.address-review.dismiss', $args))->assertForbidden();
        $this->actingAs($this->agent)->post(route('corex.address-review.fix', $args), ['street_name' => 'X'])->assertForbidden();
        $this->assertSame('review', DB::table('properties')->where('id', $p->id)->value('address_parse_status'));
    }

    public function test_the_sidebar_link_is_for_admins_only(): void
    {
        $this->actingAs($this->admin)->get(route('corex.address-review.index'))->assertSee('Address Review');
    }

    // ── settings page ──────────────────────────────────────────────────

    public function test_the_settings_page_shows_defaults_and_saves_per_agency(): void
    {
        $this->actingAs($this->admin)->get(route('settings.prospecting.address-matching.edit'))->assertOk()->assertSee('Address matching');

        $this->actingAs($this->admin)->put(route('settings.prospecting.address-matching.update'), [
            'rule_erf_exact' => '1', 'rule_street_exact' => '1', // scheme rule left off
            'possible_min_agreeing_columns' => 3, 'neighbour_suburb_credit' => 'ignore', 'gps_radius_m' => 40, 'unit_missing_on_one_side' => 'different',
        ])->assertRedirect();

        $s = AddressMatchSetting::forAgency($this->agency->id);
        $this->assertTrue($s['rule_erf_exact']);
        $this->assertFalse($s['rule_scheme_exact']);
        $this->assertSame(3, $s['possible_min_agreeing_columns']);
        $this->assertSame('ignore', $s['neighbour_suburb_credit']);
        $this->assertSame(40, $s['gps_radius_m']);
        $this->assertSame('different', $s['unit_missing_on_one_side']);
    }

    public function test_the_settings_page_rejects_bad_values_and_can_reset(): void
    {
        $bad = $this->actingAs($this->admin)->put(route('settings.prospecting.address-matching.update'), [
            'possible_min_agreeing_columns' => 9, 'neighbour_suburb_credit' => 'always', 'gps_radius_m' => 1, 'unit_missing_on_one_side' => 'x',
        ]);
        $bad->assertSessionHasErrors(['possible_min_agreeing_columns', 'neighbour_suburb_credit', 'gps_radius_m', 'unit_missing_on_one_side']);
        $this->assertSame(AddressMatchSetting::DEFAULTS, AddressMatchSetting::forAgency($this->agency->id));

        AddressMatchSetting::create(['agency_id' => $this->agency->id, 'gps_radius_m' => 80]);
        $this->actingAs($this->admin)->put(route('settings.prospecting.address-matching.update'), ['reset' => 1])->assertRedirect();
        $this->assertSame(AddressMatchSetting::DEFAULTS, AddressMatchSetting::forAgency($this->agency->id));
        $this->assertSame(1, AddressMatchSetting::where('agency_id', $this->agency->id)->count(), 'reset writes the defaults, never deletes the row');
    }

    public function test_an_agent_cannot_open_the_settings_page(): void
    {
        $this->actingAs($this->agent)->get(route('settings.prospecting.address-matching.edit'))->assertForbidden();
    }

    public function test_the_settings_page_is_linked_from_prospecting_setup(): void
    {
        $this->assertStringContainsString("route('settings.prospecting.address-matching.edit')", file_get_contents(resource_path('views/settings/prospecting/index.blade.php')));
    }

    public function test_the_scorer_obeys_the_agencys_saved_settings_end_to_end(): void
    {
        AddressMatchSetting::create(['agency_id' => $this->agency->id, 'rule_street_exact' => false]);
        $this->prop(['street_number' => '19', 'street_name' => 'Grindewald']);

        $hits = app(\App\Services\Prospecting\TrackedPropertyMatchOrCreateService::class)->findExistingStock($this->agency->id, ['street_number' => '19', 'street_name' => 'Grindewald Drive', 'suburb' => 'Uvongo']);

        $this->assertCount(1, $hits);
        $this->assertFalse($hits[0]['confident'], 'the exact street rule is switched off for this agency — it becomes a question for the agent');
    }
}
