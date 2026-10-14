<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactAuditLog;
use App\Models\Docuperfect\Flow;
use App\Models\Docuperfect\Template;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\PropertyAuditLog;
use App\Models\RentalApplication;
use App\Models\User;
use App\Services\Docuperfect\AgreementRecordWriteBack;
use App\Services\Rentals\LeaseSigningLauncher;
use App\Services\WebTemplateDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Johan, 14 Oct 2026: what an agent types into an agreement's Fill & review that CoreX did not already know goes back onto the landlord /
 * tenant / property it would have pre-filled from, so every later document and renewal fills itself. Written when the record's field is
 * EMPTY, or when the agent ticks "Also update the landlord's record?" over a filled one; blank never overwrites; an audit line on the record.
 */
final class AgreementRecordWriteBackTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Contact $landlord;
    private Contact $landlord2;
    private Contact $tenant;
    private array $fields;
    private array $stepData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cr-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->actingAs($this->agent);
        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => '2 Bed Flat', 'status' => 'to_let', 'listing_type' => 'rental',
            'street_number' => '21', 'street_name' => 'Marine Parade', 'complex_name' => 'Sea Court', 'unit_number' => '7',
            'suburb' => 'Sea Point', 'city' => 'Cape Town',
        ]);
        $this->landlord = $this->contact('Pieter', 'Botha', 'pieter@example.test', '0821110001');
        $this->landlord2 = $this->contact('Anna', 'Botha', 'anna@example.test', '0821110002');
        $this->tenant = $this->contact('Thandi', 'Ngcobo', 'thandi@example.test', '0821110003');

        $f = fn (string $id, string $name) => ['id' => $id, 'type' => 'text', 'tag_type' => 'input', 'field_name' => $name, 'label' => $name];
        $this->fields = [
            $f('f1', 'lessor_address'), $f('f2', 'lessee_address'), $f('f3', 'erf_no'), $f('f4', 'complex_name'), $f('f5', 'unit_no'),
            $f('f6', 'lessor_email'), $f('f7', 'lessor_cell'), $f('f8', 'lessor_id_number'), $f('f9', 'rent'),
            $f('f10', 'lessor2_address'),
        ];
        $this->stepData = [
            'property' => ['property_id' => $this->property->id, '_property_source' => 'properties'],
            'recipients' => ['recipients' => [
                ['role' => 'agent', 'name' => 'Agent'],
                ['role' => 'tenant', 'name' => 'Thandi', '_contact_id' => $this->tenant->id],
                ['role' => 'landlord', 'name' => 'Pieter', '_contact_id' => $this->landlord->id],
                ['role' => 'landlord', 'name' => 'Anna', '_contact_id' => $this->landlord2->id],
            ]],
        ];
    }

    private function contact(string $first, string $last, string $email, ?string $phone): Contact
    {
        $c = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone]);
        DB::table('contacts')->where('id', $c->id)->update(['agency_id' => $this->agency->id, 'email' => $email, 'phone' => $phone]);

        return $c->fresh();
    }

    private function svc(): AgreementRecordWriteBack
    {
        return app(AgreementRecordWriteBack::class);
    }

    private function apply(array $values, array $decisions = [], ?User $as = null): array
    {
        return $this->svc()->apply($this->fields, $this->stepData, $values, $decisions, $as ?? $this->agent, 77);
    }

    private function contactRow(int $id): object
    {
        return DB::table('contacts')->where('id', $id)->first();
    }

    // ── the rule ─────────────────────────────────────────────────────────────────────

    public function test_an_address_typed_over_an_empty_record_is_saved_without_a_tick_and_audited(): void
    {
        $lines = $this->apply(['f1' => '14 Vine Lane, Paarl']);

        self::assertSame('14 Vine Lane, Paarl', $this->contactRow($this->landlord->id)->address);
        self::assertSame(['Pieter Botha: address'], $lines);
        $audit = ContactAuditLog::query()->where('contact_id', $this->landlord->id)->where('event_type', 'agreement_writeback')->first();
        self::assertNotNull($audit, 'an audit line on the contact');
        self::assertStringContainsString('Address saved from the agreement', $audit->human_summary);
        self::assertStringContainsString('e-sign flow #77', $audit->human_summary);
        self::assertSame($this->agent->id, (int) $audit->user_id);
    }

    public function test_a_filled_record_is_only_changed_when_the_agent_ticks_and_the_old_value_is_in_the_audit(): void
    {
        DB::table('contacts')->where('id', $this->landlord->id)->update(['address' => '1 Old Road']);

        self::assertSame([], $this->apply(['f1' => '14 Vine Lane, Paarl']), 'no tick -> the record is left alone');
        self::assertSame('1 Old Road', $this->contactRow($this->landlord->id)->address);

        self::assertSame([], $this->apply(['f1' => '14 Vine Lane, Paarl'], ['f1' => false]));
        self::assertSame('1 Old Road', $this->contactRow($this->landlord->id)->address);

        $lines = $this->apply(['f1' => '14 Vine Lane, Paarl'], ['f1' => true]);
        self::assertSame(['Pieter Botha: address'], $lines);
        self::assertSame('14 Vine Lane, Paarl', $this->contactRow($this->landlord->id)->address);
        $audit = ContactAuditLog::query()->where('contact_id', $this->landlord->id)->where('event_type', 'agreement_writeback')->latest('id')->first();
        self::assertStringContainsString('agent confirmed the change', $audit->human_summary);
        self::assertStringContainsString('1 Old Road', json_encode($audit->old_values));
    }

    public function test_a_tick_made_about_an_earlier_value_does_not_carry_over_to_a_new_one(): void
    {
        DB::table('contacts')->where('id', $this->landlord->id)->update(['address' => '1 Old Road']);

        // the agent ticked "update" while the box said "14 Vine Lane", then typed something else and did not tick again
        self::assertSame([], $this->apply(['f1' => '99 Different Street'], ['f1' => ['on' => true, 'value' => '14 Vine Lane']]));
        self::assertSame('1 Old Road', $this->contactRow($this->landlord->id)->address);

        // the tick that IS about the typed value is honoured, in both directions
        self::assertSame(['Pieter Botha: address'], $this->apply(['f1' => '99 Different Street'], ['f1' => ['on' => true, 'value' => '99 Different Street']]));
        DB::table('contacts')->where('id', $this->landlord->id)->update(['address' => null]);
        self::assertSame([], $this->apply(['f1' => '5 New Way'], ['f1' => ['on' => false, 'value' => '5 New Way']]), 'declined over an empty record');
        self::assertNull($this->contactRow($this->landlord->id)->address);
    }

    public function test_an_empty_record_can_be_declined_with_the_tick_off(): void
    {
        self::assertSame([], $this->apply(['f1' => '14 Vine Lane, Paarl'], ['f1' => false]));
        self::assertNull($this->contactRow($this->landlord->id)->address);
    }

    public function test_blank_never_overwrites_and_an_unchanged_value_is_not_a_write(): void
    {
        DB::table('contacts')->where('id', $this->landlord->id)->update(['address' => '1 Old Road']);

        self::assertSame([], $this->apply(['f1' => '   '], ['f1' => true]));
        self::assertSame([], $this->apply(['f1' => '1 Old Road'], ['f1' => true]), 'same as the record: nothing new');
        self::assertSame('1 Old Road', $this->contactRow($this->landlord->id)->address);
        self::assertSame(0, ContactAuditLog::query()->where('event_type', 'agreement_writeback')->count());
    }

    public function test_an_address_the_pre_fill_took_from_the_tenants_application_is_not_written_back_unless_changed(): void
    {
        RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->tenant->id, 'created_by_user_id' => $this->agent->id,
            'status' => 'approved', 'current_generation' => 1, 'token' => 'tok-' . uniqid(), 'token_expires_at' => now()->addDays(14),
            'current_residential_address' => '8 Kloof Road, Bantry Bay',
        ]);

        self::assertSame([], $this->apply(['f2' => '8 Kloof Road, Bantry Bay']), 'known from the application already: not typed by the agent');
        self::assertNull($this->contactRow($this->tenant->id)->address);

        self::assertSame(['Thandi Ngcobo: address'], $this->apply(['f2' => '9 Other Road, Gardens']), 'a different address IS the agent supplying something new');
        self::assertSame('9 Other Road, Gardens', $this->contactRow($this->tenant->id)->address);
    }

    // ── email / phone / ID: fill only ────────────────────────────────────────────────

    public function test_email_phone_and_id_are_only_ever_filled_never_replaced(): void
    {
        DB::table('contacts')->where('id', $this->landlord->id)->update(['email' => null, 'phone' => null, 'id_number' => null]);
        DB::table('contact_emails')->where('contact_id', $this->landlord->id)->delete();
        DB::table('contact_phones')->where('contact_id', $this->landlord->id)->delete();

        $lines = $this->apply(['f6' => ' New.Landlord@Example.test ', 'f7' => '082 333 4444', 'f8' => '7505055009089']);

        $row = $this->contactRow($this->landlord->id);
        self::assertSame('new.landlord@example.test', $row->email);
        self::assertSame('0823334444', $row->phone);
        self::assertSame('7505055009089', $row->id_number);
        self::assertSame('agreement_writeback', $row->id_number_source);
        self::assertCount(3, $lines);

        // now they hold values: a document may not replace them, ticked or not
        self::assertSame([], $this->apply(['f6' => 'other@example.test', 'f7' => '0829999999'], ['f6' => true, 'f7' => true]));
        self::assertSame('new.landlord@example.test', $this->contactRow($this->landlord->id)->email);
    }

    public function test_an_invalid_or_clashing_value_is_skipped(): void
    {
        DB::table('contacts')->where('id', $this->landlord->id)->update(['email' => null, 'phone' => null, 'id_number' => null]);
        DB::table('contact_emails')->where('contact_id', $this->landlord->id)->delete();
        DB::table('contact_phones')->where('contact_id', $this->landlord->id)->delete();

        $lines = $this->apply(['f6' => 'not-an-email', 'f7' => '12', 'f8' => '1234']);
        self::assertSame([], $lines, 'bad email, too-short phone, invalid ID');

        // a number that already belongs to another contact in the agency is never copied onto a second one
        self::assertSame([], $this->apply(['f7' => '0821110003', 'f6' => 'thandi@example.test']));
        $row = $this->contactRow($this->landlord->id);
        self::assertNull($row->phone);
        self::assertNull($row->email);
    }

    // ── the property ─────────────────────────────────────────────────────────────────

    public function test_the_erf_goes_to_the_form_box_when_both_columns_are_empty_and_is_audited_on_the_property(): void
    {
        $lines = $this->apply(['f3' => 'ERF 4471']);

        self::assertSame(['Property: erf number'], $lines);
        $p = DB::table('properties')->where('id', $this->property->id)->first();
        self::assertSame('ERF 4471', $p->property_number);
        self::assertNull($p->erf_number);
        $audit = PropertyAuditLog::query()->where('property_id', $this->property->id)->where('event_type', 'agreement_writeback')->first();
        self::assertNotNull($audit);
        self::assertStringContainsString('Erf number saved from the agreement', $audit->human_summary);

        // the next agreement pre-fills it
        $snap = app(LeaseSigningLauncher::class)->propertySnapshot($this->property->fresh());
        self::assertSame('ERF 4471', $snap['erf_no']);
    }

    public function test_a_changed_erf_updates_the_column_the_pre_fill_reads_only_with_the_tick(): void
    {
        DB::table('properties')->where('id', $this->property->id)->update(['erf_number' => '1234']);

        self::assertSame([], $this->apply(['f3' => '9999']));
        self::assertSame(['Property: erf number'], $this->apply(['f3' => '9999'], ['f3' => true]));
        self::assertSame('9999', DB::table('properties')->where('id', $this->property->id)->value('erf_number'));
    }

    public function test_complex_and_unit_are_saved_with_the_tick_and_the_address_follows(): void
    {
        $lines = $this->apply(['f4' => 'Ocean Court', 'f5' => '12'], ['f4' => true, 'f5' => true]);

        self::assertCount(2, $lines);
        $p = DB::table('properties')->where('id', $this->property->id)->first();
        self::assertSame('Ocean Court', $p->complex_name);
        self::assertSame('12', $p->unit_number);
        self::assertStringContainsString('Ocean Court', (string) $p->address);
    }

    // ── who / which person ───────────────────────────────────────────────────────────

    public function test_a_second_landlords_own_field_and_a_per_recipient_instance_go_to_the_right_person(): void
    {
        $lines = $this->apply(['f10' => '3 Second Street, Hermanus', 'f1__r2' => '5 Instance Way, Stellenbosch']);

        self::assertSame('3 Second Street, Hermanus', $this->contactRow($this->landlord2->id)->address, 'lessor2_address -> the 2nd landlord');
        self::assertNull($this->contactRow($this->landlord->id)->address);
        self::assertCount(1, $lines, 'both name the same record and attribute: the first supplied value wins, once');
    }

    public function test_an_expanded_instance_id_maps_to_that_nth_landlord(): void
    {
        $this->apply(['f1__r2' => '5 Instance Way, Stellenbosch']);

        self::assertSame('5 Instance Way, Stellenbosch', $this->contactRow($this->landlord2->id)->address);
        self::assertNull($this->contactRow($this->landlord->id)->address);
    }

    public function test_an_assistant_never_writes_back_and_another_agencys_contact_is_out_of_reach(): void
    {
        $assistant = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_assistant' => true]);
        self::assertSame([], $this->apply(['f1' => '14 Vine Lane'], [], $assistant));
        self::assertNull($this->contactRow($this->landlord->id)->address);

        $other = Agency::create(['name' => 'Other', 'slug' => 'ot-' . uniqid()]);
        $stranger = Contact::create(['agency_id' => $other->id, 'first_name' => 'Stan', 'last_name' => 'Ger', 'email' => 'stan@example.test']);
        DB::table('contacts')->where('id', $stranger->id)->update(['agency_id' => $other->id]);
        $this->stepData['recipients']['recipients'][2]['_contact_id'] = $stranger->id;

        $this->actingAs($this->agent);
        self::assertSame([], $this->apply(['f1' => '14 Vine Lane']), 'the contact is not in the user\'s agency: the scoped lookup finds nothing');
        self::assertNull(DB::table('contacts')->where('id', $stranger->id)->value('address'));
    }

    public function test_fields_outside_the_class_are_never_written_back(): void
    {
        $lines = $this->apply(['f9' => '12500']);

        self::assertSame([], $lines);
    }

    // ── the screen's tick ────────────────────────────────────────────────────────────

    public function test_the_meta_gives_the_screen_the_record_and_the_prefill_and_skips_other_fields(): void
    {
        DB::table('contacts')->where('id', $this->landlord->id)->update(['address' => '1 Old Road']);

        $meta = $this->svc()->meta($this->fields, $this->stepData, $this->agent);

        self::assertSame("the landlord's record", $meta['f1']['target']);
        self::assertSame('1 Old Road', $meta['f1']['record']);
        self::assertTrue($meta['f1']['can_overwrite']);
        self::assertSame('', $meta['f3']['record']);
        self::assertSame("the property's record", $meta['f3']['target']);
        self::assertFalse($meta['f6']['can_overwrite'], 'email is fill-only');
        self::assertArrayNotHasKey('f9', $meta);
    }

    public function test_the_wizard_page_carries_the_meta_and_the_inline_tick(): void
    {
        $blade = file_get_contents(resource_path('views/docuperfect/esign/wizard.blade.php'));
        self::assertStringContainsString('serverWriteBackMeta', $blade);
        self::assertStringContainsString('data-writeback-tick', $blade);
        self::assertStringContainsString('Also update <span', $blade);
        self::assertStringContainsString('writeBack: Object.fromEntries', $blade, 'the ticks travel with the step-5 save');
    }

    // ── end to end: save Fill & review, then the renewal pre-fills ───────────────────

    public function test_saving_fill_and_review_writes_back_and_the_renewal_then_prefills_itself(): void
    {
        $template = new Template(['name' => 'Cape residential lease', 'render_type' => 'pdf', 'is_esign' => true]);
        $template->agency_id = $this->agency->id;
        $template->save();
        $lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 14500, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id,
        ]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        DB::table('contact_property')->insert(['contact_id' => $this->landlord->id, 'property_id' => $this->property->id, 'role' => 'landlord', 'created_at' => now(), 'updated_at' => now()]);

        $flow = app(LeaseSigningLauncher::class)->buildFlow($lease->fresh(), $template->id, $this->agent);
        $sd = $flow->step_data;
        $sd['fields'] = $this->fields;
        $flow->step_data = $sd;
        $flow->save();

        $res = $this->actingAs($this->agent)->postJson(route('docuperfect.esign.saveStep', ['flow' => $flow->id, 'step' => 5]), ['data' => [
            'fieldValues' => ['f1' => '14 Vine Lane, Paarl', 'f3' => 'ERF 4471', 'f9' => '12500'],
            'writeBack' => ['f1' => true, 'f3' => true],
            'partyOverrides' => [], 'clauses' => [], 'other_conditions_text' => '', 'other_condition_frames' => [],
        ]]);

        $res->assertOk()->assertJsonPath('success', true);
        self::assertEqualsCanonicalizing(['Pieter Botha: address', 'Property: erf number'], $res->json('written_back'));
        self::assertSame('14 Vine Lane, Paarl', $this->contactRow($this->landlord->id)->address);
        self::assertSame('ERF 4471', DB::table('properties')->where('id', $this->property->id)->value('property_number'));

        // the renewal builds its flow from the same records: nothing to type
        $renewal = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_DRAFT, 'rental_amount' => 15500, 'start_date' => '2027-11-01', 'end_date' => '2028-10-31',
            'source' => 'manual', 'created_by_user_id' => $this->agent->id, 'previous_lease_id' => $lease->id,
        ]);
        LeaseTenant::create(['lease_id' => $renewal->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $next = app(LeaseSigningLauncher::class)->buildFlow($renewal->fresh(), $template->id, $this->agent);
        $out = app(WebTemplateDataService::class)->resolve(0, $next->step_data, $this->agent);
        self::assertSame('14 Vine Lane, Paarl', $out['lessor_address']);
        self::assertSame('ERF 4471', $out['erf_no']);
    }

    public function test_a_write_back_failure_never_blocks_the_step(): void
    {
        $template = new Template(['name' => 'T', 'render_type' => 'pdf', 'is_esign' => true]);
        $template->agency_id = $this->agency->id;
        $template->save();
        $flow = Flow::create([
            'type' => 'esign', 'template_id' => $template->id, 'user_id' => $this->agent->id, 'current_step' => 5,
            'step_data' => ['fields' => $this->fields] + $this->stepData, 'status' => 'active',
        ]);
        $this->app->bind(AgreementRecordWriteBack::class, fn () => new class extends AgreementRecordWriteBack {
            public function apply(array $fields, array $stepData, array $fieldValues, array $decisions, ?User $user, ?int $flowId = null): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->actingAs($this->agent)->postJson(route('docuperfect.esign.saveStep', ['flow' => $flow->id, 'step' => 5]), ['data' => ['fieldValues' => ['f1' => 'x']]])
            ->assertOk()->assertJsonPath('success', true)->assertJsonPath('next_step', 6);
    }
}
