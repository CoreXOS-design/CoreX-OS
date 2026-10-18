<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\Property;
use App\Models\RentalFaultType;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\DealV2\AgencyServiceProviderService;
use App\Services\Rentals\FirstResponderService;
use App\Services\Rentals\RentalFaultContractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 20.9: the kinds "Attorney / conveyancer" and "Bond originator", the one-off correction of existing
 * suppliers by trade (logged in each supplier's history), and the guarantee that neither kind can ever be offered as a rental contractor or as someone
 * who looks at faults first.
 */
final class SupplierKindCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Kind Fix Agency', 'slug' => 'kf-' . uniqid()]);
        $this->other = Agency::create(['name' => 'Other Agency', 'slug' => 'ot-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
    }

    /** Created the OLD way (kind "contractor" whatever the trade) - model events off, so the new kind-from-trade rule does not fire. */
    private function legacy(string $name, string $specialty, array $over = [], ?Agency $agency = null): AgencyServiceProvider
    {
        return AgencyServiceProvider::withoutEvents(fn () => AgencyServiceProvider::withoutGlobalScopes()->create(array_merge([
            'agency_id' => ($agency ?? $this->agency)->id, 'name' => $name, 'specialty' => $specialty, 'kind' => 'contractor', 'is_active' => true,
        ], $over)));
    }

    private function fresh(AgencyServiceProvider $p): AgencyServiceProvider
    {
        return AgencyServiceProvider::withoutGlobalScopes()->withTrashed()->find($p->id);
    }

    private function migration(): object
    {
        return require base_path('database/migrations/2026_10_28_100000_correct_supplier_kind_for_attorneys_and_originators.php');
    }

    public function test_the_two_new_kinds_exist_with_their_labels(): void
    {
        $this->assertSame('Attorney / conveyancer', AgencyServiceProvider::kindLabel('attorney_conveyancer'));
        $this->assertSame('Bond originator', AgencyServiceProvider::kindLabel('bond_originator'));
        $this->assertSame(['attorney_conveyancer', 'bond_originator'], AgencyServiceProvider::NON_MAINTENANCE_KINDS);
    }

    public function test_the_correction_sets_the_kind_from_the_trade_logs_it_and_reports_the_count(): void
    {
        $transfer = $this->legacy('Smith Transfers', 'transfer_attorney');
        $bond = $this->legacy('Jones Bonds Attorneys', 'bond_attorney');
        $conv = $this->legacy('Naidoo Conveyancing', 'conveyancer');
        $flagged = $this->legacy('Both Ways Inc', 'other', ['is_transfer_attorney' => true, 'is_bond_attorney' => true]);
        $orig = $this->legacy('Bond Fast', 'bond_originator');
        $theirs = $this->legacy('Their Attorney', 'transfer_attorney', [], $this->other);
        $plumber = $this->legacy('Drip Plumbing', 'plumber');
        $caretaker = $this->legacy('Pieter', 'transfer_attorney', ['kind' => 'caretaker']);   // somebody chose this on purpose: never touched
        $archived = $this->legacy('Old Attorney', 'transfer_attorney');
        $archived->delete();

        $this->migration()->up();

        foreach ([$transfer, $bond, $conv, $flagged, $archived] as $p) {
            $this->assertSame('attorney_conveyancer', $this->fresh($p)->kind, $p->name);
        }
        $this->assertSame('bond_originator', $this->fresh($orig)->kind);
        $this->assertSame('attorney_conveyancer', $this->fresh($theirs)->kind, 'every agency is corrected, each in its own');
        $this->assertSame('contractor', $this->fresh($plumber)->kind);
        $this->assertSame('caretaker', $this->fresh($caretaker)->kind);
        // nothing else about the row changed
        $this->assertSame('transfer_attorney', $this->fresh($transfer)->specialty);
        $this->assertTrue((bool) $this->fresh($flagged)->is_bond_attorney);

        // the count: 7 changed (5 attorney / conveyancer in this agency + 1 in the other, 1 bond originator)
        $this->assertSame(7, DB::table('agency_service_provider_events')->where('event', 'kind_corrected')->count());
        $this->assertSame(6, AgencyServiceProvider::withoutGlobalScopes()->withTrashed()->where('kind', 'attorney_conveyancer')->count());
        $this->assertSame(1, AgencyServiceProvider::withoutGlobalScopes()->withTrashed()->where('kind', 'bond_originator')->count());

        // logged in the supplier's own history as a system correction (no user)
        $row = DB::table('agency_service_provider_events')->where('service_provider_id', $transfer->id)->where('event', 'kind_corrected')->first();
        $this->assertNull($row->user_id);
        $this->assertSame((int) $this->agency->id, (int) $row->agency_id);
        $this->assertSame('Kind corrected from Contractor to Attorney / conveyancer by the system (the trade is Transfer Attorney).', $row->note);
        $this->assertSame('Kind corrected from Contractor to Bond originator by the system (the trade is Bond Originator).',
            DB::table('agency_service_provider_events')->where('service_provider_id', $orig->id)->value('note'));
        $this->assertSame(0, DB::table('agency_service_provider_events')->whereIn('service_provider_id', [$plumber->id, $caretaker->id])->count());

        // a second run finds nothing left to change
        $this->migration()->up();
        $this->assertSame(7, DB::table('agency_service_provider_events')->where('event', 'kind_corrected')->count());

        // and the directory now shows it, with its history under "Recent changes"
        $html = $this->actingAs($this->admin)->get(route('deals-v2.suppliers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-supplier-kind="attorney_conveyancer">Attorney / conveyancer<', $html);
        $this->assertStringContainsString('data-supplier-kind="bond_originator">Bond originator<', $html);
        $this->assertStringContainsString('Kind corrected from Contractor to Bond originator by the system', $html);
        $byKind = $this->actingAs($this->admin)->get(route('deals-v2.suppliers.index') . '?kind=bond_originator')->getContent();
        $this->assertStringContainsString('Bond Fast', $byKind);
        $this->assertStringNotContainsString('Smith Transfers', $byKind);
    }

    public function test_a_supplier_created_from_now_on_gets_its_kind_from_its_trade_everywhere(): void
    {
        $mk = fn (string $n, string $spec, ?string $kind = null) => AgencyServiceProvider::withoutGlobalScopes()->create(array_filter(
            ['agency_id' => $this->agency->id, 'name' => $n, 'specialty' => $spec, 'kind' => $kind, 'is_active' => true], fn ($v) => $v !== null));
        $this->assertSame('attorney_conveyancer', $mk('A1', 'transfer_attorney')->fresh()->kind);
        $this->assertSame('attorney_conveyancer', $mk('A2', 'conveyancer')->fresh()->kind);
        $this->assertSame('bond_originator', $mk('B1', 'bond_originator')->fresh()->kind);
        $this->assertSame('contractor', $mk('P1', 'plumber')->fresh()->kind);
        $this->assertSame('caretaker', $mk('C1', 'transfer_attorney', 'caretaker')->fresh()->kind, 'a kind chosen on purpose is kept');

        // the deal screen's pick-or-create finds the same row twice (no duplicate under a different kind)
        $svc = app(AgencyServiceProviderService::class);
        $one = $svc->findOrCreate($this->agency->id, ['name' => 'Dlamini Attorneys', 'specialty' => 'transfer_attorney', 'email' => 'd@example.invalid']);
        $two = $svc->findOrCreate($this->agency->id, ['name' => 'Dlamini Attorneys', 'specialty' => 'transfer_attorney', 'email' => 'd@example.invalid']);
        $this->assertSame($one->id, $two->id);
        $this->assertSame('attorney_conveyancer', $one->fresh()->kind);
    }

    public function test_the_add_form_saves_the_new_kinds_with_a_sensible_trade(): void
    {
        $post = fn (array $d) => $this->actingAs($this->admin)->post(route('deals-v2.suppliers.store'), $d + ['callout_fee_mode' => 'unknown'])->assertSessionHasNoErrors();

        $post(['name' => 'Kind Attorney', 'kind' => 'attorney_conveyancer', 'specialty' => 'bond_attorney']);
        $post(['name' => 'Kind Attorney NoTrade', 'kind' => 'attorney_conveyancer', 'specialty' => 'electrician']);
        $post(['name' => 'Kind Originator', 'kind' => 'bond_originator', 'specialty' => 'plumber']);
        $post(['name' => 'Plain Contractor Attorney', 'kind' => 'contractor', 'specialty' => 'transfer_attorney']);
        $get = fn (string $n) => AgencyServiceProvider::withoutGlobalScopes()->where('name', $n)->first();

        $this->assertSame(['attorney_conveyancer', 'bond_attorney'], [$get('Kind Attorney')->kind, $get('Kind Attorney')->specialty]);
        $this->assertSame(['attorney_conveyancer', 'conveyancer'], [$get('Kind Attorney NoTrade')->kind, $get('Kind Attorney NoTrade')->specialty]);
        $this->assertSame(['bond_originator', 'bond_originator'], [$get('Kind Originator')->kind, $get('Kind Originator')->specialty]);
        $this->assertSame('attorney_conveyancer', $get('Plain Contractor Attorney')->kind);
        // the attorney capability is still stamped, so deal pickers keep working
        $this->assertTrue((bool) $get('Plain Contractor Attorney')->is_transfer_attorney);
    }

    public function test_neither_kind_can_ever_be_offered_as_a_rental_contractor_or_first_responder(): void
    {
        $plumber = $this->legacy('Drip Plumbing', 'plumber');
        $caretaker = $this->legacy('Pieter the caretaker', 'other', ['kind' => 'caretaker']);
        // by KIND ALONE: an ordinary "other" trade, no attorney flags - the kind is what must keep them out
        $att = $this->legacy('Kind-only Attorney', 'other', ['kind' => 'attorney_conveyancer']);
        $org = $this->legacy('Kind-only Originator', 'other', ['kind' => 'bond_originator']);
        // and the by-trade ones after the correction
        $tAtt = $this->legacy('Smith Transfers', 'transfer_attorney');
        $tOrg = $this->legacy('Bond Fast', 'bond_originator');
        $this->migration()->up();
        $banned = [$att->id, $org->id, $tAtt->id, $tOrg->id];
        $okIds = [$plumber->id, $caretaker->id];

        // the one rule every rental picker and every rental check goes through
        $ids = AgencyServiceProvider::withoutGlobalScopes()->where('agency_id', $this->agency->id)->maintenanceContractors()->pluck('id')->all();
        $this->assertEqualsCanonicalizing($okIds, $ids);
        foreach ($banned as $id) {
            $rule = Validator::make(['id' => $id], ['id' => [AgencyServiceProvider::maintenanceExistsRule($this->agency->id)]]);
            $this->assertTrue($rule->fails(), "supplier {$id} passed the rental contractor rule");
        }
        $this->assertTrue(Validator::make(['id' => $plumber->id], ['id' => [AgencyServiceProvider::maintenanceExistsRule($this->agency->id)]])->passes());

        // fault page / work order contractor lists
        $contractors = app(RentalFaultContractorService::class);
        $this->assertEqualsCanonicalizing($okIds, $contractors->allContractors($this->agency->id)->pluck('id')->all());
        $classified = json_encode($contractors->classifyFor($this->agency->id, 'Leaking tap', 'Plumbing'));
        foreach (['Kind-only Attorney', 'Kind-only Originator', 'Smith Transfers', 'Bond Fast'] as $name) {
            $this->assertStringNotContainsString($name, $classified);
        }

        // "Who looks at faults first": the picker and the save
        $fr = app(FirstResponderService::class);
        $offered = collect($fr->editorData($this->agency->id)['suppliers'])->pluck('id')->all();
        $this->assertEqualsCanonicalizing($okIds, $offered);
        $property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->admin->branch_id, 'title' => 'KF Unit', 'status' => 'active', 'listing_type' => 'rental']);
        foreach ($banned as $id) {
            try {
                $fr->save($property, [['supplier_id' => $id, 'fault_scope' => 'all', 'fault_type_ids' => [], 'tell_mode' => 'inspect']], $this->admin);
                $this->fail("supplier {$id} was accepted as a first responder");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('first_responders', $e->errors());
            }
        }
        $this->assertTrue($fr->save($property, [['supplier_id' => $caretaker->id, 'fault_scope' => 'all', 'fault_type_ids' => [], 'tell_mode' => 'inspect']], $this->admin));

        // changing a contractor's kind on the directory to one of the two removes them from every rental picker at once
        $this->actingAs($this->admin)->postJson(route('deals-v2.suppliers.kind', $plumber), ['kind' => 'attorney_conveyancer'])->assertOk();
        $this->assertNotContains($plumber->id, $contractors->allContractors($this->agency->id)->pluck('id')->all());
        $this->assertNotContains($plumber->id, collect($fr->editorData($this->agency->id)['suppliers'])->pluck('id')->all());
    }
}
