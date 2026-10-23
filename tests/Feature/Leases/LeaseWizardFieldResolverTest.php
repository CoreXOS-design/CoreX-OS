<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Docuperfect\Template;
use App\Services\Rentals\LeaseAgreementValuesReader;
use App\Services\Rentals\LeaseSeedPresentation;
use App\Services\Rentals\LeaseWizardFieldResolver;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §15.4 step 4 - an agency's map names a field the way its OWN document prints it; the e-sign wizard
 * names the same box its own way. The two must meet, or the value is seeded nowhere (Mega lease, QA1, 23 Oct 2026: the
 * deposit printed raw "5500.00" because the document's `deposit_amount` is the wizard's `property_deposit_amount`).
 */
final class LeaseWizardFieldResolverTest extends TestCase
{
    private function fields(): array
    {
        $names = ['monthly_rental', 'property_deposit_amount', 'property_address+suburb', 'lease_start', 'property_erf_number'];
        $fields = [];
        foreach ($names as $i => $name) {
            $fields[] = ['id' => 'f' . $i, 'field_name' => $name, 'tag_type' => 'input', 'type' => $name === 'lease_start' ? 'date' : 'placeholder'];
        }
        $fields[] = ['id' => 'sig', 'field_name' => 'property_signature', 'tag_type' => 'signature', 'type' => 'signature'];

        return $fields;
    }

    public function test_the_documents_name_finds_the_wizards_property_prefixed_box(): void
    {
        $resolver = new LeaseWizardFieldResolver;

        $this->assertSame('f1', $resolver->find($this->fields(), 'deposit_amount')['id']);
        $this->assertSame('f1', $resolver->find($this->fields(), 'property_deposit_amount')['id'], 'the exact name still works');
        $this->assertSame('f4', $resolver->find($this->fields(), 'erf_number')['id']);
    }

    public function test_a_name_with_odd_characters_is_matched_loosely(): void
    {
        $this->assertSame('f2', (new LeaseWizardFieldResolver)->find($this->fields(), 'property_address_suburb')['id']);
    }

    public function test_the_wizard_naming_a_box_without_the_prefix_is_found_from_the_prefixed_map_name(): void
    {
        $fields = [['id' => 'a', 'field_name' => 'deposit_amount', 'tag_type' => 'input', 'type' => 'text']];

        $this->assertSame('a', (new LeaseWizardFieldResolver)->find($fields, 'property_deposit_amount')['id']);
    }

    public function test_an_exact_name_always_beats_an_alias(): void
    {
        $fields = [
            ['id' => 'alias', 'field_name' => 'property_deposit_amount', 'tag_type' => 'input', 'type' => 'text'],
            ['id' => 'exact', 'field_name' => 'deposit_amount', 'tag_type' => 'input', 'type' => 'text'],
        ];

        $this->assertSame('exact', (new LeaseWizardFieldResolver)->find($fields, 'deposit_amount')['id']);
        $this->assertSame('alias', (new LeaseWizardFieldResolver)->find($fields, 'property_deposit_amount')['id']);
    }

    public function test_a_name_that_is_nowhere_on_the_template_and_a_signature_box_are_not_found(): void
    {
        $resolver = new LeaseWizardFieldResolver;

        $this->assertNull($resolver->find($this->fields(), 'no_such_field'));
        $this->assertNull($resolver->find($this->fields(), 'property_signature'), 'a signature box is never a seed target');
        $this->assertNull($resolver->find($this->fields(), ''));
    }

    public function test_unresolved_lists_only_dangling_entries_and_skips_contact_keys(): void
    {
        $template = new Template(['name' => 'Lease', 'render_type' => 'pdf', 'fields_json' => $this->fields()]);
        $map = app(LeaseAgreementValuesReader::class)->normaliseMap([
            'rent' => ['field' => 'monthly_rental'], 'deposit' => ['field' => 'deposit_amount'], 'adults' => ['field' => 'occupants'],
            'tenant_name' => ['field' => 'lessee_name'], 'tenant_name_2' => ['field' => 'lessee_2_name'], 'agency_extra' => ['field' => 'gone'],
        ]);

        $this->assertSame(['adults' => 'occupants', 'agency_extra' => 'gone'], (new LeaseWizardFieldResolver)->unresolved($template, $map));
    }

    public function test_a_template_with_no_readable_fields_says_nothing(): void
    {
        $template = new Template(['name' => 'Scanned lease', 'render_type' => 'pdf']);

        $this->assertSame([], (new LeaseWizardFieldResolver)->unresolved($template, ['deposit' => ['field' => 'deposit_amount', 'required' => false, 'label' => null]]));
    }

    public function test_a_date_box_is_recognised_through_the_alias_too(): void
    {
        $presentation = app(LeaseSeedPresentation::class)->for(null, ['start_date' => ['field' => 'lease_start']], $this->fields());

        $this->assertSame(['start_date' => 'iso'], $presentation);
    }
}
