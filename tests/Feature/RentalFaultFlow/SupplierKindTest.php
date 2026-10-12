<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 21.1: the kind of a supplier - default Contractor, set on the add form, changed from the list - and the
 * supplier list's column, search, filters, sort, paging, reactivate and empty state.
 */
final class SupplierKindTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Kind Agency', 'slug' => 'ka-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }

    private function make(string $name, array $over = [], ?Agency $agency = null): AgencyServiceProvider
    {
        // the signed-in user's agency would be stamped over a supplier of another agency - vouch for the agency we give
        return AgencyServiceProvider::withoutAgencyStamping(fn () => AgencyServiceProvider::withoutGlobalScopes()->create(array_merge([
            'agency_id' => ($agency ?? $this->agency)->id, 'name' => $name, 'specialty' => 'plumber', 'is_active' => true,
        ], $over)));
    }

    public function test_every_supplier_is_a_contractor_unless_said_otherwise(): void
    {
        $s = $this->make('Old Plumber');
        $this->assertSame('contractor', $s->fresh()->kind);
        $this->assertSame('Contractor', AgencyServiceProvider::kindLabel($s->fresh()->kind));
        $this->assertSame(['contractor', 'caretaker', 'owner_team', 'insurer', 'body_corporate'], array_keys(AgencyServiceProvider::KINDS));
        $this->assertSame("Owner's own maintenance team", AgencyServiceProvider::kindLabel('owner_team'));
        $this->assertSame('Body corporate / managing agent', AgencyServiceProvider::kindLabel('body_corporate'));
    }

    public function test_adding_a_caretaker_saves_the_kind_and_needs_no_trade_and_a_same_named_contractor_is_not_merged(): void
    {
        $resp = $this->actingAs($this->admin)->postJson(route('deals-v2.suppliers.store'), ['name' => 'Pieter', 'kind' => 'caretaker', 'specialty' => 'electrician'])
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('provider.kind', 'caretaker');
        $caretaker = AgencyServiceProvider::withoutGlobalScopes()->find($resp->json('provider.id'));
        $this->assertSame('caretaker', $caretaker->kind);
        $this->assertSame('other', $caretaker->specialty, 'a non-contractor kind has no trade');

        $again = $this->actingAs($this->admin)->postJson(route('deals-v2.suppliers.store'), ['name' => 'Pieter', 'specialty' => 'other'])->assertOk();
        $this->assertNotSame($caretaker->id, $again->json('provider.id'));
        $this->assertSame('contractor', AgencyServiceProvider::withoutGlobalScopes()->find($again->json('provider.id'))->kind);
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $this->actingAs($this->admin)->postJson(route('deals-v2.suppliers.store'), ['name' => 'X', 'kind' => 'wizard', 'specialty' => 'other'])->assertStatus(422);
    }

    public function test_the_kind_can_be_changed_from_the_list_and_only_in_your_own_agency(): void
    {
        $s = $this->make('Switch Me');
        $this->actingAs($this->admin)->postJson(route('deals-v2.suppliers.kind', $s), ['kind' => 'insurer'])->assertOk()->assertJsonPath('label', 'Insurer / insurance assessor');
        $this->assertSame('insurer', $s->fresh()->kind);
        $this->actingAs($this->admin)->postJson(route('deals-v2.suppliers.kind', $s), ['kind' => 'nope'])->assertStatus(422);

        $other = Agency::create(['name' => 'Other', 'slug' => 'ot-' . uniqid()]);
        $theirs = $this->make('Theirs', [], $other);
        $status = $this->actingAs($this->admin)->postJson(route('deals-v2.suppliers.kind', $theirs), ['kind' => 'insurer'])->getStatusCode();
        $this->assertContains($status, [403, 404], "another agency's supplier is not reachable");
        $this->assertSame('contractor', AgencyServiceProvider::withoutGlobalScopes()->find($theirs->id)->kind);
        $this->assertDatabaseHas('agency_service_provider_events', ['service_provider_id' => $s->id, 'event' => 'kind_changed']);
    }

    public function test_the_list_has_a_kind_column_search_filters_sort_and_an_empty_state(): void
    {
        $this->make('Alpha Plumbing', ['company' => 'Alpha Pty']);
        $this->make('Bravo Caretaking', ['kind' => 'caretaker', 'specialty' => 'other', 'email' => 'bravo@example.invalid']);
        $this->make('Charlie Insure', ['kind' => 'insurer', 'specialty' => 'other', 'is_active' => false]);

        $url = route('deals-v2.suppliers.index');
        $all = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('>Kind</th>', $all);
        foreach (['Alpha Plumbing', 'Bravo Caretaking', 'Charlie Insure'] as $n) {
            $this->assertStringContainsString($n, $all);
        }
        $this->assertStringContainsString('data-supplier-kind="caretaker"', $all);

        $byKind = $this->actingAs($this->admin)->get($url . '?kind=caretaker')->getContent();
        $this->assertStringContainsString('Bravo Caretaking', $byKind);
        $this->assertStringNotContainsString('Alpha Plumbing', $byKind);

        $search = $this->actingAs($this->admin)->get($url . '?q=bravo@example')->getContent();
        $this->assertStringContainsString('Bravo Caretaking', $search);
        $this->assertStringNotContainsString('Charlie Insure', $search);
        $this->assertStringContainsString('Alpha Plumbing', $this->actingAs($this->admin)->get($url . '?q=Alpha+Pty')->getContent());

        $inactive = $this->actingAs($this->admin)->get($url . '?status=inactive')->getContent();
        $this->assertStringContainsString('Charlie Insure', $inactive);
        $this->assertStringNotContainsString('Alpha Plumbing', $inactive);

        $sorted = $this->actingAs($this->admin)->get($url . '?sort=newest&status=active')->getContent();
        $this->assertLessThan(strpos($sorted, 'Alpha Plumbing'), strpos($sorted, 'Bravo Caretaking'));

        $none = $this->actingAs($this->admin)->get($url . '?q=zzzzzz')->getContent();
        $this->assertStringContainsString('data-supplier-empty', $none);
        $this->assertStringContainsString('No suppliers match that search', $none);
    }

    public function test_the_list_pages_and_reactivate_brings_a_deactivated_supplier_back(): void
    {
        for ($i = 1; $i <= 27; $i++) {
            $this->make(sprintf('Supplier %02d', $i));
        }
        $page1 = $this->actingAs($this->admin)->get(route('deals-v2.suppliers.index') . '?sort=name')->getContent();
        $this->assertStringContainsString('data-supplier-pagination', $page1);
        $this->assertStringContainsString('Supplier 25', $page1);
        $this->assertStringNotContainsString('Supplier 26', $page1);

        $gone = $this->make('Gone Plumber', ['is_active' => false]);
        $this->actingAs($this->admin)->post(route('deals-v2.suppliers.reactivate', $gone))->assertRedirect();
        $this->assertTrue((bool) $gone->fresh()->is_active);
        $other = Agency::create(['name' => 'Other', 'slug' => 'ot-' . uniqid()]);
        $theirs = $this->make('Theirs', ['is_active' => false], $other);
        $this->assertContains($this->actingAs($this->admin)->post(route('deals-v2.suppliers.reactivate', $theirs))->getStatusCode(), [403, 404]);
        $this->assertFalse((bool) AgencyServiceProvider::withoutGlobalScopes()->find($theirs->id)->is_active);
    }
}
