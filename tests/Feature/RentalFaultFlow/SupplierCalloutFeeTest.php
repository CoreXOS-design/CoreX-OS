<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderServiceType;
use App\Models\DealV2\AgencyServiceType;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultType;
use App\Models\RentalWorkOrder;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\DealV2\SupplierCalloutFeeService;
use App\Services\Rentals\RentalContractorJobService;
use App\Services\Rentals\RentalFaultContractorService;
use App\Services\Rentals\RentalFaultPhotoAnnotationService;
use App\Services\Rentals\RentalFaultProgressService;
use App\Services\Rentals\RentalMailDispatcher;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use App\Services\Rentals\RentalWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md §20 - a supplier's call-out fee: the three-way question, its rules and history, the suffix in every
 * picker, the notice before sending, the supplier card, the owner's line (never the tenant's), the contractor's email, and the directory's
 * search / sort / filter / pagination / restore. The agency mail path is replaced by a recorder, so nothing leaves this process.
 */
final class SupplierCalloutFeeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private Contact $tenant;
    private Contact $landlord;
    private RentalFaultType $faultType;
    /** @var array<int, array{0:?string,1:object}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');
        $self = $this;
        $this->app->instance(RentalMailDispatcher::class, new class($self) extends RentalMailDispatcher {
            public function __construct(private $t) {}
            public function send(?string $recipientEmail, \App\Mail\Signatures\BaseSignatureMail $mail): void { $this->t->recordMail($recipientEmail, $mail); }
        });

        $this->agency = Agency::create(['name' => 'Callout Agency', 'slug' => 'co-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'CO Unit', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Tina', 'last_name' => 'Tenant', 'email' => 'tina+' . uniqid() . '@example.invalid', 'phone' => '0821112222',
        ]);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $this->landlord = Contact::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Lenny', 'last_name' => 'Landlord', 'email' => 'lenny+' . uniqid() . '@example.invalid',
        ]);
        $this->property->contacts()->attach($this->landlord->id, ['role' => 'landlord']);
        $this->faultType = RentalFaultType::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'name' => 'Leaking tap', 'category' => 'Plumbing', 'urgency' => 'routine',
            'first_aid_steps' => 'Close the valve.', 'is_default' => false, 'sort_order' => 1, 'is_active' => true,
        ]);
    }

    public function recordMail(?string $to, object $mail): void
    {
        $this->sent[] = [$to, $mail];
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function fault(): RentalFaultReport
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'lease_id' => $this->lease->id,
            'rental_fault_type_id' => $this->faultType->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => 'app', 'title' => 'Tap leaks', 'description' => 'The kitchen tap drips all night',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $photo = RentalFaultReportPhoto::create(['agency_id' => $this->agency->id, 'rental_fault_report_id' => $fault->id, 'storage_path' => '/storage/co/tap.jpg', 'uploaded_by_user_id' => null]);
        app(RentalFaultPhotoAnnotationService::class)->addMark($fault, $photo, ['shape' => 'circle', 'geometry' => ['cx' => 0.4, 'cy' => 0.5, 'r' => 0.1], 'colour' => '#e11d48'], $this->agent);
        $fault->saveOwnerVersion(['owner_title' => 'Kitchen tap leaking', 'owner_description' => 'A tap in the kitchen drips.', 'owner_photo_ids' => [$photo->id]], $this->agent);
        $fault->fresh()->requestApproval($this->agent);
        $fault->fresh()->recordApproval($this->agent, ['decision' => 'approved', 'approval_route' => 'agency_appoints', 'evidence_type' => 'verbal_note', 'evidence_text' => 'Owner agreed.']);

        return $fault->fresh();
    }

    private function provider(string $name, array $over = [], ?string $tradeCode = 'Plumbing', ?int $agencyId = null): AgencyServiceProvider
    {
        $agencyId ??= $this->agency->id;
        $p = AgencyServiceProvider::withoutGlobalScopes()->create(array_merge(['agency_id' => $agencyId, 'name' => $name, 'is_active' => true, 'specialty' => 'other'], $over));
        if ($tradeCode) {
            $type = AgencyServiceType::withoutGlobalScopes()->firstOrCreate(['agency_id' => $agencyId, 'code' => $tradeCode], ['label' => $tradeCode, 'sort_order' => 1, 'is_active' => true]);
            AgencyServiceProviderServiceType::withoutGlobalScopes()->create(['agency_id' => $agencyId, 'service_provider_id' => $p->id, 'service_type' => $type->code]);
        }

        return $p;
    }

    private function withFee(string $name, string $mode, ?float $amount = null, ?string $note = null, array $over = []): AgencyServiceProvider
    {
        return $this->provider($name, array_merge(['callout_fee_mode' => $mode, 'callout_fee_amount' => $amount, 'callout_fee_note' => $note], $over));
    }

    private function appoint(RentalFaultReport $fault, AgencyServiceProvider $p): RentalWorkOrder
    {
        $made = app(RentalWorkOrderService::class)->createFromFaultDecision($fault, $this->agent, ['assignment_type' => 'outside_supplier', 'agency_service_provider_id' => $p->id]);

        return $made['work_order']->fresh();
    }

    private function history(AgencyServiceProvider $p): \Illuminate\Support\Collection
    {
        return DB::table('agency_service_provider_callout_history')->where('service_provider_id', $p->id)->orderBy('id')->get();
    }

    // ── 1. the data and its rules ────────────────────────────────────────────

    public function test_a_supplier_nobody_has_asked_is_not_known_and_nothing_was_guessed(): void
    {
        $p = $this->provider('Old Supplier')->fresh();

        $this->assertSame('unknown', $p->callout_fee_mode);
        $this->assertNull($p->callout_fee_amount);
        $this->assertSame('unknown', $p->calloutFeeStatus());
        $this->assertNull($p->calloutFeeAmount());
        $this->assertNull($p->calloutFeeLine());
        $this->assertSame(0, $this->history($p)->count());
    }

    public function test_the_methods_other_work_builds_on_and_the_picker_text_for_all_three_answers(): void
    {
        $yes = $this->withFee('Yes Co', 'yes', 750, 'waived if the job goes ahead')->fresh();
        $this->assertSame('yes', $yes->calloutFeeStatus());
        $this->assertSame(750.0, $yes->calloutFeeAmount());
        $this->assertSame('waived if the job goes ahead', $yes->calloutFeeNote());
        $this->assertSame('call-out R750', $yes->calloutFeeSuffix());
        $this->assertSame('Call-out fee: R750 (waived if the job goes ahead)', $yes->calloutFeeLine());

        $no = $this->withFee('No Co', 'no')->fresh();
        $this->assertSame('no call-out fee', $no->calloutFeeSuffix());
        $this->assertNull($no->calloutFeeAmount());
        $this->assertNull($no->calloutFeeLine());

        $this->assertSame('call-out fee not known', $this->provider('Unk Co')->fresh()->calloutFeeSuffix());

        $this->assertSame('R1 200', AgencyServiceProvider::formatRand(1200.0));
        $this->assertSame('R750.50', AgencyServiceProvider::formatRand(750.5));
        $this->assertSame('call-out R1 200', $this->withFee('Big Co', 'yes', 1200)->fresh()->calloutFeeSuffix());

        // a stale amount on a supplier that says No is never reported
        $stale = $this->withFee('Stale Co', 'no', 500)->fresh();
        $this->assertNull($stale->calloutFeeAmount());
    }

    public function test_yes_needs_an_amount_above_zero_and_no_or_not_known_clear_it(): void
    {
        $svc = app(SupplierCalloutFeeService::class);
        $p = $this->provider('Rules Co');

        foreach ([null, '', 0, '0.00'] as $bad) {
            try {
                $svc->apply($p, 'yes', $bad, null, $this->agent, 'directory');
                $this->fail('Yes with no amount must be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('callout_fee_amount', $e->errors());
            }
        }
        $this->assertSame(0, $this->history($p)->count(), 'a refused save writes nothing');

        $this->assertTrue($svc->apply($p, 'yes', '1 200,50', '  after hours R1 200 ', $this->agent, 'directory'));
        $p = $p->fresh();
        $this->assertSame([1200.5, 'after hours R1 200'], [$p->calloutFeeAmount(), $p->calloutFeeNote()]);

        $this->assertTrue($svc->apply($p, 'no', 999, 'ignored', $this->agent, 'directory'));
        $p = $p->fresh();
        $this->assertSame(['no', null, null], [$p->callout_fee_mode, $p->callout_fee_amount, $p->callout_fee_note]);

        $this->assertTrue($svc->apply($p, 'yes', 300, null, $this->agent, 'directory'));
        $this->assertTrue($svc->apply($p->fresh(), 'unknown', 300, 'x', $this->agent, 'directory'));
        $p = $p->fresh();
        $this->assertSame(['unknown', null, null], [$p->callout_fee_mode, $p->callout_fee_amount, $p->callout_fee_note]);
    }

    public function test_every_change_is_kept_with_who_when_from_and_to_and_an_unchanged_save_writes_nothing(): void
    {
        $svc = app(SupplierCalloutFeeService::class);
        $p = $this->provider('History Co');

        $this->assertTrue($svc->apply($p, 'yes', 750, 'waived if the job goes ahead', $this->agent, 'directory'));
        $this->assertFalse($svc->apply($p->fresh(), 'yes', 750.00, 'waived if the job goes ahead', $this->agent, 'directory'), 'same answer = no change');
        $this->assertTrue($svc->apply($p->fresh(), 'no', null, null, $this->agent, 'directory'));

        $rows = $this->history($p);
        $this->assertCount(2, $rows);
        $this->assertSame(['unknown', null, 'yes', '750.00', 'waived if the job goes ahead', $this->agent->id, 'directory'],
            [$rows[0]->from_mode, $rows[0]->from_amount, $rows[0]->to_mode, $rows[0]->to_amount, $rows[0]->to_note, $rows[0]->user_id, $rows[0]->source]);
        $this->assertSame(['yes', 'no'], [$rows[1]->from_mode, $rows[1]->to_mode]);
        $this->assertNotNull($rows[0]->created_at);
        $this->assertSame('Not known → Charges R750 (waived if the job goes ahead)', SupplierCalloutFeeService::describe($rows[0]));
    }

    // ── 2. adding and editing ────────────────────────────────────────────────

    public function test_the_add_a_contractor_popup_saves_the_fee_and_refuses_yes_without_an_amount(): void
    {
        $base = ['name' => 'Popup Plumbing', 'specialty' => 'plumber', 'email' => 'popup@example.invalid'];

        $bad = $this->actingAs($this->agent)->postJson(route('deals-v2.suppliers.store'), $base + ['callout_fee_mode' => 'yes']);
        $bad->assertStatus(422)->assertJsonValidationErrors('callout_fee_amount');
        $this->assertStringContainsString('Enter the call-out fee', $bad->json('errors.callout_fee_amount.0'));
        $this->assertSame(0, AgencyServiceProvider::withoutGlobalScopes()->where('name', 'Popup Plumbing')->count(), 'nothing saved');

        $ok = $this->actingAs($this->agent)->postJson(route('deals-v2.suppliers.store'), $base + ['callout_fee_mode' => 'yes', 'callout_fee_amount' => '750', 'callout_fee_note' => 'waived if the job goes ahead'])->assertOk();
        $p = AgencyServiceProvider::withoutGlobalScopes()->findOrFail($ok->json('provider.id'));
        $this->assertSame(['yes', 750.0, 'waived if the job goes ahead'], [$p->calloutFeeStatus(), $p->calloutFeeAmount(), $p->calloutFeeNote()]);
        $this->assertSame('popup', $this->history($p)->first()->source);

        // no fee fields posted at all = Not known
        $plain = $this->actingAs($this->agent)->postJson(route('deals-v2.suppliers.store'), ['name' => 'Plain Sparks', 'specialty' => 'electrician'])->assertOk();
        $this->assertSame('unknown', AgencyServiceProvider::withoutGlobalScopes()->find($plain->json('provider.id'))->calloutFeeStatus());
        $this->assertSame(0, DB::table('agency_service_provider_callout_history')->where('service_provider_id', $plain->json('provider.id'))->count());
    }

    public function test_adding_a_match_for_an_existing_supplier_never_overwrites_their_fee(): void
    {
        $existing = $this->withFee('Existing Plumbing', 'yes', 500, null, ['email' => 'same@example.invalid', 'specialty' => 'plumber']);
        $this->actingAs($this->agent)->postJson(route('deals-v2.suppliers.store'), ['name' => 'Existing Plumbing', 'specialty' => 'plumber', 'email' => 'same@example.invalid', 'callout_fee_mode' => 'no'])->assertOk();

        $this->assertSame(500.0, $existing->fresh()->calloutFeeAmount());
    }

    public function test_the_directory_saves_the_fee_on_its_own_route_and_only_for_the_users_own_agency(): void
    {
        $p = $this->provider('Edit Co');
        $this->actingAs($this->agent)->post(route('deals-v2.suppliers.callout-fee', $p), ['callout_fee_mode' => 'yes', 'callout_fee_amount' => '650', 'callout_fee_note' => 'after hours R1 200'])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('call-out R650', $p->fresh()->calloutFeeSuffix());

        $this->actingAs($this->agent)->post(route('deals-v2.suppliers.callout-fee', $p), ['callout_fee_mode' => 'yes', 'callout_fee_amount' => ''])->assertSessionHasErrors('callout_fee_amount');
        $this->assertSame(650.0, $p->fresh()->calloutFeeAmount(), 'a refused save changes nothing');

        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $theirs = $this->provider('Their Co', [], null, $other->id);
        DB::table('agency_service_providers')->where('id', $theirs->id)->update(['agency_id' => $other->id]);   // BelongsToAgency stamps the acting user's agency on create
        $theirs = AgencyServiceProvider::withoutGlobalScopes()->find($theirs->id);
        $this->actingAs($this->agent)->post(route('deals-v2.suppliers.callout-fee', $theirs), ['callout_fee_mode' => 'no'])->assertNotFound();   // another agency's supplier does not exist for this user
        $this->assertSame('unknown', AgencyServiceProvider::withoutGlobalScopes()->find($theirs->id)->calloutFeeStatus());
        DB::table('agency_service_providers')->where('id', $theirs->id)->update(['is_active' => 0]);
        $this->actingAs($this->agent)->post(route('deals-v2.suppliers.activate', $theirs))->assertNotFound();
        $this->assertFalse((bool) AgencyServiceProvider::withoutGlobalScopes()->find($theirs->id)->is_active, "another agency's supplier is never restored from here");
    }

    public function test_the_add_forms_and_the_popup_ask_the_same_three_way_question(): void
    {
        $dir = $this->actingAs($this->agent)->get(route('deals-v2.suppliers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Charges a call-out fee?', $dir);
        $this->assertStringContainsString('Call-out fee (R, incl. VAT)', $dir);
        $this->assertStringContainsString('Call-out note', $dir);
        foreach (['<option value="unknown"', '<option value="yes"', '<option value="no"'] as $opt) {
            $this->assertStringContainsString($opt, $dir);
        }
        $this->assertStringContainsString('>Not known<', $dir);

        // the fault page carries the "Add a contractor" popup, built from the same form
        $fault = $this->fault();
        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('data-contractor-popup-form', $html);
        $this->assertStringContainsString('Charges a call-out fee?', $html);
        $this->assertStringContainsString('name="callout_fee_amount"', $html);
    }

    // ── 3. the supplier directory list ───────────────────────────────────────

    public function test_the_directory_has_a_callout_column_and_shows_each_answer_and_the_history(): void
    {
        $yes = $this->withFee('Charges Co', 'yes', 750, 'waived if the job goes ahead', ['email' => 'c@example.invalid']);
        $this->withFee('Free Co', 'no');
        $this->provider('Unknown Co');
        app(SupplierCalloutFeeService::class)->apply($yes, 'yes', 800, 'waived if the job goes ahead', $this->agent, 'directory');

        $html = $this->actingAs($this->agent)->get(route('deals-v2.suppliers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-sort="callout"', $html);
        $this->assertStringContainsString('R800', $html);
        $this->assertStringContainsString('waived if the job goes ahead', $html);
        $this->assertStringContainsString('data-callout-cell="no"', $html);
        $this->assertStringContainsString('data-callout-cell="unknown"', $html);
        $this->assertStringContainsString('data-callout-history', $html);
        $this->assertStringContainsString('Agnes Agent: Charges R750 (waived if the job goes ahead) → Charges R800 (waived if the job goes ahead)', preg_replace('/\s+/', ' ', strip_tags($html)));
    }

    public function test_search_filter_sort_pagination_and_agency_scope(): void
    {
        $a = $this->withFee('Alpha Sparks', 'yes', 400, null, ['company' => 'Bright Spark Ltd', 'email' => 'alpha@example.invalid', 'phone' => '0831110000']);
        $b = $this->withFee('Bravo Plumbing', 'yes', 900);
        $c = $this->withFee('Charlie Roofing', 'no');
        $d = $this->provider('Delta Gutters');
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'oa-' . uniqid()]);
        $z = $this->provider('Zulu Other Agency Supplier', [], null, $other->id);
        DB::table('agency_service_providers')->where('id', $z->id)->update(['agency_id' => $other->id]);

        $names = function (array $q) {
            $html = $this->actingAs($this->agent)->get(route('deals-v2.suppliers.index', $q))->assertOk()->getContent();
            preg_match_all('/<tr id="provider-\d+".*?<span class="font-medium"[^>]*>([^<]+)<\/span>/s', $html, $m);

            return $m[1];
        };

        $this->assertSame(['Alpha Sparks', 'Bravo Plumbing', 'Charlie Roofing', 'Delta Gutters'], $names([]), 'the other agency is not listed');
        $this->assertSame(['Alpha Sparks'], $names(['q' => 'bright spark']), 'company');
        $this->assertSame(['Alpha Sparks'], $names(['q' => '0831110000']), 'phone');
        $this->assertSame(['Alpha Sparks'], $names(['q' => 'alpha@']), 'email');
        $this->assertSame([], $names(['q' => 'Zulu']), 'another agency is never searchable');
        $this->assertSame(['Alpha Sparks', 'Bravo Plumbing'], $names(['callout' => 'yes']));
        $this->assertSame(['Charlie Roofing'], $names(['callout' => 'no']));
        $this->assertSame(['Delta Gutters'], $names(['callout' => 'unknown']));
        $this->assertSame(['Bravo Plumbing', 'Alpha Sparks', 'Charlie Roofing', 'Delta Gutters'], $names(['sort' => 'callout', 'dir' => 'asc']), 'charges (largest first), no fee, not known');
        $this->assertSame(['Delta Gutters', 'Charlie Roofing', 'Alpha Sparks', 'Bravo Plumbing'], $names(['sort' => 'callout', 'dir' => 'desc']));
        $this->assertSame(['Delta Gutters', 'Charlie Roofing', 'Bravo Plumbing', 'Alpha Sparks'], $names(['sort' => 'name', 'dir' => 'desc']));
        $this->assertSame(['Alpha Sparks'], $names(['q' => 'alpha', 'callout' => 'yes']));
        $this->assertSame([], $names(['q' => 'alpha', 'callout' => 'no']));

        // an invalid sort / filter value is absorbed, never a 500
        $this->actingAs($this->agent)->get(route('deals-v2.suppliers.index', ['sort' => 'DROP TABLE', 'callout' => ['x'], 'status' => 'zzz']))->assertOk();

        // empty state
        $html = $this->actingAs($this->agent)->get(route('deals-v2.suppliers.index', ['q' => 'nobody-matches-this']))->assertOk()->getContent();
        $this->assertStringContainsString('data-suppliers-empty', $html);
        $this->assertStringContainsString('No suppliers match this search', $html);

        // pagination: 50 a page
        for ($i = 1; $i <= 52; $i++) {
            $this->provider(sprintf('Zed Filler %02d', $i), [], null);
        }
        $page1 = $this->actingAs($this->agent)->get(route('deals-v2.suppliers.index', ['sort' => 'name']))->assertOk()->getContent();
        $this->assertStringContainsString('data-suppliers-pages', $page1);
        $this->assertSame(50, preg_match_all('/<tr id="provider-\d+"/', $page1));
        $page2 = $this->actingAs($this->agent)->get(route('deals-v2.suppliers.index', ['sort' => 'name', 'page' => 2]))->assertOk()->getContent();
        $this->assertSame(6, preg_match_all('/<tr id="provider-\d+"/', $page2), '56 suppliers = 50 + 6');
    }

    public function test_a_deactivated_supplier_can_be_restored_and_nothing_is_deleted(): void
    {
        $p = $this->withFee('Gone Co', 'yes', 700);
        $this->actingAs($this->agent)->post(route('deals-v2.suppliers.deactivate', $p))->assertRedirect();
        $this->assertFalse((bool) $p->fresh()->is_active);

        $html = $this->actingAs($this->agent)->get(route('deals-v2.suppliers.index', ['status' => 'inactive']))->getContent();
        $this->assertStringContainsString('data-restore-supplier', $html);
        $this->assertStringContainsString('R700', $html, 'the fee is kept while inactive');

        $this->actingAs($this->agent)->post(route('deals-v2.suppliers.activate', $p))->assertRedirect();
        $this->assertTrue((bool) $p->fresh()->is_active);
        $this->assertSame(700.0, $p->fresh()->calloutFeeAmount());
        $this->assertNull($p->fresh()->deleted_at);
    }

    // ── 4. pickers and the notice ────────────────────────────────────────────

    public function test_the_fault_page_picker_shows_the_suffix_and_the_notice_for_a_fee_and_for_not_known(): void
    {
        $yes = $this->withFee('Sparky Plumbing', 'yes', 750, 'waived if the job goes ahead');
        $no = $this->withFee('Free Plumbing', 'no');
        $unk = $this->provider('Mystery Plumbing');
        $fault = $this->fault();

        $rows = collect(app(RentalFaultContractorService::class)->pickerFor($fault))->keyBy('id');
        $this->assertSame(['call-out R750', 'no call-out fee', 'call-out fee not known'], [$rows[$yes->id]['callout'], $rows[$no->id]['callout'], $rows[$unk->id]['callout']]);
        $this->assertSame('Sparky Plumbing charges a call-out fee of R750. The owner will be told.', $rows[$yes->id]['callout_notice']);
        $this->assertSame("We don't know if Mystery Plumbing charges a call-out fee - check before sending.", $rows[$unk->id]['callout_notice']);
        $this->assertNull($rows[$no->id]['callout_notice'], 'nothing to say when there is no fee');

        $html = $this->actingAs($this->agent)->get(route('corex.rental-fault-reports.show', $fault))->assertOk()->getContent();
        $this->assertStringContainsString('- call-out R750', $html);
        $this->assertStringContainsString('- no call-out fee', $html);
        $this->assertStringContainsString('- call-out fee not known', $html);
        $this->assertStringContainsString('data-callout-notice', $html);
        $this->assertStringContainsString('Sparky Plumbing charges a call-out fee of R750. The owner will be told.', $html);
        $this->assertStringContainsString('check before sending.', $html);
        $this->assertStringNotContainsString('Free Plumbing charges', $html);
    }

    public function test_the_work_order_pickers_carry_the_suffix_and_the_notice_and_the_card_states_the_fee(): void
    {
        $yes = $this->withFee('Sparky Plumbing', 'yes', 750, 'waived if the job goes ahead', ['email' => 'sparky@example.invalid']);
        $other = $this->provider('Mystery Plumbing');
        $wo = $this->appoint($this->fault(), $yes);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertStringContainsString('data-supplier-callout="yes"', $html);
        $flat = preg_replace('/\s+/', ' ', strip_tags($html));
        $this->assertStringContainsString('Call-out fee: R750 (waived if the job goes ahead)', $flat);
        $this->assertStringContainsString('- call-out R750', $html, 'the appoint radios');
        $this->assertStringContainsString('- call-out fee not known', $html);
        $this->assertStringContainsString('Sparky Plumbing - call-out R750</option>', $html, 'the quote picker');
        $this->assertStringContainsString('know if Mystery Plumbing charges a call-out fee - check before sending.', $html);
        $this->assertStringContainsString('Sparky Plumbing charges a call-out fee of R750. The owner will be told.', $html);

        // the other two answers on the card
        $wo->forceFill(['agency_service_provider_id' => $other->id])->saveQuietly();
        $this->assertStringContainsString('data-supplier-callout="unknown"', $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->getContent());
        $free = $this->withFee('Free Plumbing', 'no');
        $wo->forceFill(['agency_service_provider_id' => $free->id])->saveQuietly();
        $card = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->getContent();
        $this->assertStringContainsString('data-supplier-callout="no"', $card);
        $this->assertStringContainsString('Free Plumbing - no call-out fee</option>', $card);
    }

    public function test_the_invoice_supplier_picker_carries_the_suffix(): void
    {
        $yes = $this->withFee('Sparky Plumbing', 'yes', 750, null, ['email' => 'sparky@example.invalid']);
        $wo = $this->appoint($this->fault(), $yes);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-invoices-panel.*Sparky Plumbing - call-out R750<\/option>/s', $html);
    }

    // ── 5. the owner is told; the tenant is not; the contractor's email ──────

    public function test_the_owner_sees_the_fee_on_the_appointment_line_and_the_portal_summary_but_the_tenant_never_does(): void
    {
        $yes = $this->withFee('Sparky Plumbing', 'yes', 750, 'waived if the job goes ahead', ['email' => 'sparky@example.invalid']);
        $fault = $this->fault();
        $wo = $this->appoint($fault, $yes);
        $wo->forceFill(['approval_basis' => RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT, 'approved_amount' => 1])->saveQuietly();
        app(RentalWorkOrderService::class)->setAppointment($wo->fresh(), now()->addDays(2)->setTime(9, 0), null, $this->agent);

        $progress = app(RentalFaultProgressService::class);
        $line = fn (string $aud) => collect($progress->forFault($fault->fresh(), $aud)['steps'])->firstWhere('key', 'appointment_set')['detail'] ?? '';
        $this->assertStringContainsString('Sparky Plumbing. Call-out fee: R750 (waived if the job goes ahead)', $line(RentalFaultProgressService::AUDIENCE_OWNER));
        $this->assertStringContainsString('Sparky Plumbing', $line(RentalFaultProgressService::AUDIENCE_TENANT));
        $this->assertStringNotContainsString('Call-out', $line(RentalFaultProgressService::AUDIENCE_TENANT));
        $this->assertStringNotContainsString('Call-out', $line(RentalFaultProgressService::AUDIENCE_AGENT));

        $views = app(RentalWorkOrderClientViewService::class);
        $this->assertSame('Call-out fee: R750 (waived if the job goes ahead)', $views->summary($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD)['contractor_callout']);
        $this->assertNull($views->summary($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_TENANT)['contractor_callout']);

        // a supplier that charges nothing says nothing to the owner
        $free = $this->withFee('Free Plumbing', 'no');
        $wo->forceFill(['agency_service_provider_id' => $free->id])->saveQuietly();
        $this->assertNull($views->summary($wo->fresh(), RentalWorkOrderClientViewService::AUDIENCE_LANDLORD)['contractor_callout']);
    }

    public function test_the_contractor_job_email_states_the_fee_only_when_there_is_one(): void
    {
        $yes = $this->withFee('Sparky Plumbing', 'yes', 750, 'waived if the job goes ahead', ['email' => 'sparky@example.invalid']);
        $this->appoint($this->fault(), $yes);
        $mail = collect($this->sent)->firstWhere(0, 'sparky@example.invalid')[1];
        $text = preg_replace('/\s+/', ' ', strip_tags($mail->render()));
        $this->assertStringContainsString('Call-out fee on our records: R750 (waived if the job goes ahead). Please tell us if this is not right.', $text);

        foreach ([['No Plumbing', 'no'], ['Unk Plumbing', 'unknown']] as [$name, $mode]) {
            $this->sent = [];
            $p = $this->withFee($name, $mode, null, null, ['email' => strtolower(strtok($name, ' ')) . '@example.invalid']);
            $this->appoint($this->fault(), $p);
            $sent = collect($this->sent)->firstWhere(0, $p->email);
            $this->assertNotNull($sent, "{$name} was sent the job");
            $this->assertStringNotContainsString('Call-out fee', strip_tags($sent[1]->render()), "{$name}: nothing about a fee");
        }
    }

    // ── 6. Johan's ruling: ONE shared two-button block ───────────────────────

    public function test_the_two_choices_are_one_shared_partial_with_equal_buttons_and_no_dead_buttons(): void
    {
        $notices = AgencyServiceProvider::noticeMap([$this->withFee('Sparky Plumbing', 'yes', 750), $this->provider('Mystery Plumbing'), $this->withFee('Free Plumbing', 'no')]);
        $this->assertNull(collect($notices)->firstWhere('name', 'Free Plumbing')['text'], 'no fee = no notice, but the contractor is still in the block');

        // not wired by the caller: the notice only, never a button that does nothing
        $bare = view('partials.contractor-inspect-choice', ['notices' => $notices, 'model' => 'picked'])->render();
        $this->assertStringContainsString('data-callout-notice', $bare);
        $this->assertStringNotContainsString('data-send-to-inspect', $bare);

        // wired by the caller (cc6 / cc5): exactly two buttons, same size, the agreed wording
        $wired = view('partials.contractor-inspect-choice', ['notices' => $notices, 'model' => 'picked', 'inspectAttrs' => '@click="inspect()"', 'authoriseAttrs' => '@click="authorise()"'])->render();
        $this->assertSame(1, preg_match_all('/data-send-to-inspect/', $wired));
        $this->assertSame(1, preg_match_all('/data-send-to-owner-to-authorise/', $wired));
        $this->assertStringContainsString("' to inspect'", $wired, '"Send <name> to inspect", the name from the chosen contractor');
        $this->assertStringContainsString('>Send to owner to authorise</button>', $wired);
        $this->assertSame(2, preg_match_all('/class="corex-btn-(?:primary|outline) text-xs w-full"/', $wired), 'equal width, equal text size');
        $this->assertStringContainsString('@click="inspect()"', $wired);

        // every screen uses that one partial - no second copy of the block
        foreach (['corex/rental-fault-reports/_raise-work-order-form', 'corex/rental-work-orders/show'] as $view) {
            $this->assertStringContainsString("partials.contractor-inspect-choice", file_get_contents(resource_path("views/{$view}.blade.php")));
        }
        $this->assertFileDoesNotExist(resource_path('views/partials/callout-notice.blade.php'));
    }
}
