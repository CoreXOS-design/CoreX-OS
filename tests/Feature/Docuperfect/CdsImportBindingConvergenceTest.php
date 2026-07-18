<?php

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\FieldGroup;
use App\Models\Docuperfect\NamedField;
use App\Services\Docuperfect\CdsBindingSuggester;
use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\CdsRendererService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-177 — the import/cds convergence: a fresh import of Johan's "{Party} - {Attribute}"
 * token documents must produce the SAME binding structure he hand-fixed on template #70,
 * so the vet CONFIRMS rather than REPAIRS.
 *
 *   D1 — identity token ("Seller - Full name and surname") → the party's FIELD GROUP
 *        (single "I / We Name (ID) and Name (ID)" clause), not the bare party-name field.
 *   D2 — each attribute token → its own column, with a populated editable_by.
 *   D4 — literal "______ / Signature" acknowledgement lines → shared Seller+Agent sig_only.
 */
class CdsImportBindingConvergenceTest extends TestCase
{
    use RefreshDatabase;

    private int $fgId;

    protected function setUp(): void
    {
        parent::setUp();

        $first = NamedField::create(['name' => 'Seller First Name', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'first_name', 'source_contact_type' => 'Seller']);
        $last  = NamedField::create(['name' => 'Seller Last Name', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'last_name', 'source_contact_type' => 'Seller']);
        $idnf  = NamedField::create(['name' => 'Seller Id Number', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'id_number', 'source_contact_type' => 'Seller']);

        NamedField::create(['name' => 'Seller address', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'address', 'source_contact_type' => 'Seller']);
        NamedField::create(['name' => 'Seller Phone', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'phone', 'source_contact_type' => 'Seller']);
        NamedField::create(['name' => 'Seller Email', 'field_type' => 'text', 'source_type' => 'contact', 'source_column' => 'email', 'source_contact_type' => 'Seller']);

        NamedField::create(['name' => 'Street', 'field_type' => 'text', 'source_type' => 'property', 'source_column' => 'address']);
        NamedField::create(['name' => 'District', 'field_type' => 'text', 'source_type' => 'property', 'source_column' => 'district']);
        NamedField::create(['name' => 'Price', 'field_type' => 'text', 'source_type' => 'property', 'source_column' => 'price']);
        NamedField::create(['name' => 'Expiry Date', 'field_type' => 'text', 'source_type' => 'property', 'source_column' => 'expiry_date']);
        NamedField::create(['name' => 'Price[words]', 'field_type' => 'text', 'source_type' => 'computed', 'source_column' => 'price_in_words']);

        $fg = FieldGroup::create([
            'agency_id' => 1,
            'name' => 'Seller full',
            'fields' => [
                ['named_field_id' => $first->id],
                ['named_field_id' => $last->id],
                ['named_field_id' => $idnf->id],
            ],
            'layout' => 'inline',
            'is_global' => true,
        ]);
        $this->fgId = $fg->id;
    }

    private function ph(string $blockId, string $label): array
    {
        return ['type' => 'insertable_block_placeholder', 'purpose' => 'custom_named', 'block_id' => $blockId, 'raw_token' => $label, 'custom_label' => $label];
    }

    private function para(array $content): array
    {
        return ['type' => 'paragraph', 'content' => $content];
    }

    public function test_identity_token_binds_to_field_group_with_witness_editable(): void
    {
        $cds = ['sections' => [$this->para([$this->ph('seller_full_name_and_surname', 'Seller - Full name and surname')])]];
        $b = (new CdsBindingSuggester(1))->suggest($cds)['bindings'][0];

        $this->assertSame('field_group', $b['mappingType']);
        $this->assertSame($this->fgId, $b['fieldGroupId']);
        $this->assertSame('fg:' . $this->fgId, $b['typeKey']);
        $this->assertSame('owner_party', $b['party']);
        $this->assertEqualsCanonicalizing(['owner_party', 'agent', 'witness'], $b['editable_by']);
    }

    public function test_attribute_tokens_bind_to_own_columns_with_editable_by(): void
    {
        $cds = ['sections' => [$this->para([
            $this->ph('seller_physical_address', 'Seller - Physical address'),
            $this->ph('seller_telephone', 'Seller - Telephone'),
            $this->ph('seller_email', 'Seller - Email'),
            $this->ph('property_street', 'Property - Street'),
        ])]];
        $b = (new CdsBindingSuggester(1))->suggest($cds)['bindings'];

        $this->assertSame('sf:contact_seller', $b[0]['typeKey']);
        $this->assertEqualsCanonicalizing(['owner_party', 'agent'], $b[0]['editable_by']);   // address
        $this->assertEqualsCanonicalizing(['owner_party', 'agent'], $b[1]['editable_by']);   // phone
        $this->assertEqualsCanonicalizing(['owner_party'], $b[2]['editable_by']);            // email — owner only
        $this->assertSame('sf:property', $b[3]['typeKey']);                                  // street → property.address
        $this->assertEqualsCanonicalizing(['owner_party', 'agent'], $b[3]['editable_by']);

        // attributes must NOT collapse to the party name
        $this->assertNotSame('field_group', $b[0]['mappingType']);
        $nf = NamedField::find($b[0]['namedFieldId']);
        $this->assertSame('address', $nf->source_column);
    }

    public function test_document_tokens_price_words_and_other_conditions(): void
    {
        $cds = ['sections' => [$this->para([
            $this->ph('document_asking_price_in_words', 'Document - Asking price in words'),
            $this->ph('document_mandate_expiry_date', 'Document - Mandate expiry date'),
            $this->ph('document_other_conditions', 'Document - Other conditions'),
        ])]];
        $b = (new CdsBindingSuggester(1))->suggest($cds)['bindings'];

        $this->assertSame('sf:computed', $b[0]['typeKey']);            // in words → computed, NOT the figure
        $this->assertSame([], $b[0]['editable_by']);
        $this->assertSame('property', NamedField::find($b[1]['namedFieldId'])->source_type); // expiry
        $this->assertSame('manual', $b[2]['mappingType']);            // other conditions
        $this->assertEqualsCanonicalizing(['agent', 'owner_party'], $b[2]['editable_by']);
    }

    public function test_duplicate_column_disambiguates_to_the_matching_name(): void
    {
        // A rental-context duplicate must NOT win the sale-document token.
        NamedField::create(['name' => 'Rental Complex', 'field_type' => 'text', 'source_type' => 'property', 'source_column' => 'complex_name', 'sort_order' => 80]);
        $wanted = NamedField::create(['name' => 'Complex', 'field_type' => 'text', 'source_type' => 'property', 'source_column' => 'complex_name', 'sort_order' => 110]);

        $cds = ['sections' => [$this->para([$this->ph('property_complex_estate_name', 'Property - Complex / Estate name')])]];
        $b = (new CdsBindingSuggester(1))->suggest($cds)['bindings'][0];

        $this->assertSame($wanted->id, $b['namedFieldId'], 'must pick "Complex" over "Rental Complex" for a sale doc');
    }

    public function test_primary_role_inferred_from_contact_tokens(): void
    {
        $cds = ['sections' => [$this->para([
            $this->ph('seller_physical_address', 'Seller - Physical address'),
            $this->ph('property_street', 'Property - Street'),
        ])]];
        $this->assertSame('Seller', (new CdsBindingSuggester(1))->suggest($cds)['primary_role']);
    }

    public function test_underscore_signature_lines_become_shared_sig_only(): void
    {
        $svc = new CdsParserService();
        $m = new \ReflectionMethod($svc, 'detectUnderscoreSignatureLines');
        $m->setAccessible(true);

        $sections = [
            $this->para([$this->ph('seller_physical_address', 'Seller - Physical address')]),
            $this->para([['type' => 'text', 'value' => '______________________']]),
            $this->para([['type' => 'text', 'value' => 'Signature']]),
            $this->para([['type' => 'text', 'value' => 'More clause content follows here.']]),
        ];
        $out = $m->invoke($svc, $sections);

        $placeholders = [];
        foreach ($out as $s) {
            foreach ($s['content'] ?? [] as $it) {
                if (($it['type'] ?? '') === 'signature_placeholder') {
                    $placeholders[] = $it;
                }
            }
        }
        $this->assertCount(1, $placeholders);
        $this->assertSame('sig_only', $placeholders[0]['suggested_variant']);
        $labels = array_map(fn ($p) => $p['label'], $placeholders[0]['suggested_parties']);
        $this->assertEqualsCanonicalizing(['Seller', 'Agent'], $labels);

        // The renderer must surface the roster/variant for the builder JS.
        $html = (new CdsRendererService())->render(['sections' => $out]);
        $this->assertStringContainsString('data-sig-parties="Seller,Agent"', $html);
        $this->assertStringContainsString('data-sig-variant="sig_only"', $html);
    }

    public function test_underscore_line_not_labelled_signature_is_left_alone(): void
    {
        $svc = new CdsParserService();
        $m = new \ReflectionMethod($svc, 'detectUnderscoreSignatureLines');
        $m->setAccessible(true);

        $sections = [
            $this->para([['type' => 'text', 'value' => '______________________']]),
            $this->para([['type' => 'text', 'value' => 'Full name of witness']]),
            $this->para([['type' => 'text', 'value' => 'trailing content']]),
        ];
        $out = $m->invoke($svc, $sections);

        $found = false;
        foreach ($out as $s) {
            foreach ($s['content'] ?? [] as $it) {
                if (($it['type'] ?? '') === 'signature_placeholder') {
                    $found = true;
                }
            }
        }
        $this->assertFalse($found, 'ordinary underscored blanks must not be tokenised as signatures');
    }
}
