<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\TemplateSignatureZone;
use App\Models\RentalLeaseTemplate;
use App\Services\Rentals\LeaseAgreementTemplateGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * leases.md §15.12.4 (Build L0) — the one place that decides whether a template may be used by an agency
 * for the lease process. Own valid template passes; a template with no owning agency (the built-in,
 * shared kind) is refused for EVERY agency including a would-be owner; another agency's template is
 * refused with a message that hints at nothing; archived, non-e-sign, missing-map and missing-role are
 * each refused for their own reason.
 */
final class LeaseAgreementTemplateGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private LeaseAgreementTemplateGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        $this->other = Agency::create(['name' => 'Karoo Lettings', 'slug' => 'karoo-' . uniqid()]);
        $this->guard = app(LeaseAgreementTemplateGuard::class);
    }

    public function test_an_own_valid_template_with_a_complete_map_is_usable(): void
    {
        $template = $this->template($this->agency->id);

        $this->assertSame([], $this->guard->problemsFor($template, $this->agency->id, $this->completeMap()));
        $this->guard->assertUsable($template, $this->agency->id, $this->completeMap());
        $this->assertSame([], $this->guard->problemsFor($template, $this->agency->id), 'template alone (no map yet) is fine too');
    }

    public function test_a_template_with_no_owning_agency_is_refused_for_every_agency_including_a_would_be_owner(): void
    {
        $shared = $this->template(null, ['is_global' => true]);

        foreach ([$this->agency->id, $this->other->id] as $agencyId) {
            $this->assertSame([LeaseAgreementTemplateGuard::SHARED], $this->guard->problemsFor($shared, $agencyId, $this->completeMap()));
            $this->assertSame([LeaseAgreementTemplateGuard::SHARED], $this->guard->refusalsFor($shared, $agencyId));
            try {
                $this->guard->assertUsable($shared, $agencyId, $this->completeMap());
                $this->fail('a shared template must never be usable');
            } catch (ValidationException $e) {
                $this->assertSame([LeaseAgreementTemplateGuard::SHARED], $e->errors()['template']);
            }
        }
    }

    public function test_another_agencys_template_is_refused_with_a_message_that_reveals_nothing(): void
    {
        $theirs = $this->template($this->other->id, ['name' => 'Karoo Secret Lease']);

        $problems = $this->guard->problemsFor($theirs, $this->agency->id, $this->completeMap());

        $this->assertSame([LeaseAgreementTemplateGuard::NOT_AVAILABLE], $problems);
        $joined = implode(' ', $problems);
        $this->assertStringNotContainsString('Karoo', $joined);
        $this->assertStringNotContainsString((string) $this->other->id, $joined);
    }

    public function test_archived_and_non_esign_templates_are_refused_for_their_own_reason(): void
    {
        $archived = $this->template($this->agency->id, ['archived_at' => now()]);
        $notEsign = $this->template($this->agency->id, ['is_esign' => false]);

        $this->assertSame([LeaseAgreementTemplateGuard::ARCHIVED], $this->guard->problemsFor($archived, $this->agency->id));
        $this->assertSame([LeaseAgreementTemplateGuard::NOT_ESIGN], $this->guard->problemsFor($notEsign, $this->agency->id));
    }

    public function test_a_missing_or_incomplete_field_map_is_refused_naming_what_is_missing(): void
    {
        $template = $this->template($this->agency->id);

        $none = $this->guard->problemsFor($template, $this->agency->id, []);
        $this->assertContains('Map the monthly rent field.', $none);
        $this->assertContains('Map the start date field.', $none);
        $this->assertContains('Map the tenant name field.', $none);
        $this->assertContains('Map the landlord name field.', $none);
        $this->assertContains('Map the end date field, or tick that this lease is month-to-month.', $none);

        $partial = $this->completeMap();
        unset($partial['rent'], $partial['end_date']);
        $this->assertSame(
            ['Map the monthly rent field.', 'Map the end date field, or tick that this lease is month-to-month.'],
            $this->guard->mapProblems($partial),
        );
    }

    public function test_a_month_to_month_lease_needs_no_end_date_field_and_a_numbered_party_field_counts(): void
    {
        $map = $this->completeMap();
        unset($map['end_date'], $map['tenant_name'], $map['landlord_name']);
        $map['tenant_name_1'] = ['field' => 'lessee_1'];
        $map['landlord_name_1'] = 'lessor_1';          // the string shorthand
        $this->assertNotSame([], $this->guard->mapProblems($map));

        $map['_meta'] = ['month_to_month' => true];
        $this->assertSame([], $this->guard->mapProblems($map));
    }

    #[DataProvider('missingRoleProvider')]
    public function test_a_template_with_no_signing_place_for_a_role_is_refused(array $parties, string $expected): void
    {
        $template = $this->template($this->agency->id, ['signing_parties' => $parties]);

        $this->assertSame([$expected], $this->guard->problemsFor($template, $this->agency->id, $this->completeMap()));
    }

    public static function missingRoleProvider(): array
    {
        return [
            'no tenant' => [['owner_party', 'agent'], 'No signing place for the tenant.'],
            'no landlord' => [['acquiring_party', 'agent'], 'No signing place for the landlord.'],
            'no agent' => [['owner_party', 'acquiring_party'], 'No signing place for the agent.'],
        ];
    }

    public function test_signing_places_are_read_from_every_place_a_template_declares_them(): void
    {
        // The engine's own list, in any of the vocabularies it uses.
        $this->assertSame(['agent', 'tenant', 'landlord'], $this->guard->signingRolesFor($this->template($this->agency->id, ['signing_parties' => ['lessor', 'lessee', 'agent']])));
        $this->assertSame(['agent', 'tenant', 'landlord'], $this->guard->signingRolesFor($this->template($this->agency->id, ['signing_parties' => ['landlord', 'tenant_2', 'agent']])));

        // A PDF template's signature zones.
        $pdf = $this->template($this->agency->id, ['render_type' => 'pdf', 'signing_parties' => ['agent']]);
        TemplateSignatureZone::create(['template_id' => $pdf->id, 'page_index' => 0, 'x_position' => 0.1, 'y_position' => 0.1, 'width' => 0.2, 'height' => 0.05, 'type' => 'signature', 'assigned_parties' => ['tenant']]);
        TemplateSignatureZone::create(['template_id' => $pdf->id, 'page_index' => 0, 'x_position' => 0.5, 'y_position' => 0.1, 'width' => 0.2, 'height' => 0.05, 'type' => 'signature', 'assigned_parties' => ['landlord']]);
        $this->assertSame(['agent', 'tenant', 'landlord'], $this->guard->signingRolesFor($pdf->fresh()));

        // A CDS template's signature section.
        $cds = $this->template($this->agency->id, ['signing_parties' => [], 'cds_json' => ['sections' => [
            ['type' => 'clause', 'text' => 'x'],
            ['type' => 'signature_section', 'parties' => [['role' => 'lessor'], ['role' => 'lessee'], ['role' => 'agent']]],
        ]]]);
        $this->assertSame(['agent', 'tenant', 'landlord'], $this->guard->signingRolesFor($cds));

        // A template that says nothing about who signs has no signing places.
        $this->assertSame([], $this->guard->signingRolesFor($this->template($this->agency->id, ['signing_parties' => []])));
    }

    public function test_signing_places_and_field_names_are_read_from_a_web_templates_blade_source(): void
    {
        // The repo's residential CDS snapshot: an inline signature line for each of landlord, tenant and agent.
        $web = $this->template($this->agency->id, [
            'render_type' => 'web', 'signing_parties' => [], 'fields_json' => [],
            'blade_view' => 'docuperfect.web-templates.cds.template-121',
        ]);

        $this->assertSame(['agent', 'tenant', 'landlord'], $this->guard->signingRolesFor($web));

        $names = $this->guard->fieldNamesFor($web);
        foreach (['lessor_full', 'lessee_name_id', 'monthly_rental', 'property_lease_start_date', 'property_lease_end_date', 'lets_assists_fee', 'owner_nett'] as $expected) {
            $this->assertContains($expected, $names);
        }
        $this->assertSame($names, array_values(array_unique($names)), 'a field printed twice is listed once');

        $missing = $this->template($this->agency->id, ['blade_view' => 'docuperfect.web-templates.cds.no-such-template']);
        $this->assertSame([], $this->guard->fieldNamesFor($missing), 'an unreadable blade source is not an error');
    }

    public function test_field_names_come_from_fields_json_and_a_cds_template(): void
    {
        $template = $this->template($this->agency->id, [
            'fields_json' => [['field_name' => 'rent_amount'], ['field_name' => ' '], ['id' => 'x'], ['field_name' => 'rent_amount']],
            'cds_json' => ['sections' => [['type' => 'label_value_group', 'pairs' => [['fields' => [['field_name' => 'lessee_name']]]]]]],
        ]);

        $this->assertSame(['lessee_name', 'rent_amount'], $this->guard->fieldNamesFor($template));
    }

    public function test_linked_for_returns_the_agencys_default_ready_row_and_nothing_else(): void
    {
        $template = $this->template($this->agency->id);
        $notReady = RentalLeaseTemplate::create(['agency_id' => $this->agency->id, 'name' => 'No map', 'docuperfect_template_id' => $template->id, 'category' => 'residential', 'is_active' => true]);
        $this->assertNull($this->guard->linkedFor($this->agency->id), 'a row without a map is not linked');

        $first = $this->row($template, 'First');
        $second = $this->row($template, 'Second', ['is_default' => true]);
        $this->row($template, 'Inactive', ['is_active' => false, 'is_default' => true]);
        $this->row($template, 'Commercial', ['category' => 'commercial', 'is_default' => true]);

        $this->assertSame($second->id, $this->guard->linkedFor($this->agency->id)->id, 'the default wins');

        $second->delete();
        $this->assertSame($first->id, $this->guard->linkedFor($this->agency->id)->id, 'then the oldest ready row');
        $this->assertNotNull($notReady);
    }

    public function test_one_agencys_ready_agreement_is_never_linked_for_another_agency(): void
    {
        $theirs = $this->template($this->other->id);
        RentalLeaseTemplate::create([
            'agency_id' => $this->other->id, 'name' => 'Theirs', 'docuperfect_template_id' => $theirs->id,
            'category' => 'residential', 'is_active' => true, 'field_map' => $this->completeMap(),
        ]);

        $this->assertNull($this->guard->linkedFor($this->agency->id), 'the real starting state: nothing linked');
        $this->assertNotNull($this->guard->linkedFor($this->other->id));

        // A row of this agency that points at the other agency's template is not usable either.
        $stolen = RentalLeaseTemplate::create([
            'agency_id' => $this->agency->id, 'name' => 'Pointing across', 'docuperfect_template_id' => $theirs->id,
            'category' => 'residential', 'is_active' => true, 'field_map' => $this->completeMap(),
        ]);
        $this->assertNull($this->guard->linkedFor($this->agency->id));
        $this->assertFalse($stolen->fresh()->isUsableBy($this->agency->id));
    }

    public function test_status_and_record_check_store_the_last_result_on_the_row(): void
    {
        $template = $this->template($this->agency->id);
        $row = RentalLeaseTemplate::create(['agency_id' => $this->agency->id, 'name' => 'Row', 'docuperfect_template_id' => $template->id, 'category' => 'residential', 'is_active' => true]);

        $this->assertSame(LeaseAgreementTemplateGuard::STATE_NEEDS_MAP, $this->guard->statusFor($row, $this->agency->id)['state']);
        $problems = $this->guard->recordCheck($row, $this->agency->id);
        $this->assertNotSame([], $problems);
        $this->assertNotNull($row->fresh()->validated_at);
        $this->assertSame($problems, $row->fresh()->validation_problems);

        $row->update(['field_map' => $this->completeMap()]);
        $this->assertSame([], $this->guard->recordCheck($row->fresh(), $this->agency->id));
        $this->assertNull($row->fresh()->validation_problems);
        $this->assertSame(LeaseAgreementTemplateGuard::STATE_READY, $this->guard->statusFor($row->fresh(), $this->agency->id)['state']);

        $template->update(['archived_at' => now()]);
        $status = $this->guard->statusFor($row->fresh(), $this->agency->id);
        $this->assertSame(LeaseAgreementTemplateGuard::STATE_NOT_USABLE, $status['state']);
        $this->assertSame([LeaseAgreementTemplateGuard::ARCHIVED], $status['problems']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────────

    private function template(?int $agencyId, array $overrides = []): Template
    {
        $template = new Template(array_merge([
            'name' => 'Lease agreement ' . uniqid(), 'render_type' => 'pdf', 'is_esign' => true,
            'signing_parties' => ['owner_party', 'acquiring_party', 'agent'],
        ], $overrides));
        $template->agency_id = $agencyId;   // set outside mass-assignment so a null owner stays null
        $template->save();

        return $template->fresh();
    }

    private function row(Template $template, string $name, array $overrides = []): RentalLeaseTemplate
    {
        return RentalLeaseTemplate::create(array_merge([
            'agency_id' => $template->agency_id, 'name' => $name, 'docuperfect_template_id' => $template->id,
            'category' => 'residential', 'is_active' => true, 'field_map' => $this->completeMap(),
        ], $overrides));
    }

    private function completeMap(): array
    {
        return [
            'rent' => ['field' => 'monthly_rental'],
            'start_date' => ['field' => 'lease_start'],
            'end_date' => ['field' => 'lease_end'],
            'tenant_name' => ['field' => 'lessee_name'],
            'landlord_name' => ['field' => 'lessor_name'],
        ];
    }
}
