<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Models\Docuperfect\Template;
use App\Services\WebTemplateDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-177 B2/B1/B3 — the ROOT fix, against the CDS import's REAL field shape (host dump 2026-07-17).
 *
 * "Claude import 1" binds fields by typeKey (sf:contact_seller / sf:property) + sourceType +
 * namedFieldId + mappingType=named_field, with NO descriptive labels; the attribute lives in the
 * named field's source_column. The prior code keyed off `$namedField->source_type` (not 'contact'
 * for these imports), so the contact branch never fired and the seller NAME bled through. This proves
 * resolution now keys off the field-level signals + the named field's source_column.
 */
final class CdsFieldResolutionTest extends TestCase
{
    use RefreshDatabase;

    /** Create a named field exactly as the CDS import stores it (source_column = the attribute). */
    private function namedField(string $name, string $sourceType, ?string $sourceColumn, ?string $contactType): int
    {
        return (int) DB::table('docuperfect_named_fields')->insertGetId([
            'name' => $name, 'source_type' => $sourceType, 'source_column' => $sourceColumn,
            'source_contact_type' => $contactType, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function resolve(array $fieldMappings, array $stepData): array
    {
        $template = Template::create([
            'name' => 'Claude import 1 - EATS', 'template_type' => 'cds', 'render_type' => 'web',
            'blade_view' => 'x.eats', 'is_esign' => true, 'fields_json' => [],
            'field_mappings' => $fieldMappings,
        ]);

        return app(WebTemplateDataService::class)->resolve($template->id, $stepData, null);
    }

    /** A field mapping shaped like m2's verbatim host dump. */
    private function mapping(string $fieldName, string $typeKey, string $sourceType, ?string $contactType, int $namedFieldId): array
    {
        return [
            'field_name' => $fieldName, 'typeKey' => $typeKey, 'sourceType' => $sourceType,
            'sourceContactType' => $contactType, 'namedFieldId' => $namedFieldId,
            'mappingType' => 'named_field', 'fieldGroupId' => null,
        ];
    }

    private function stepData(): array
    {
        return [
            'property' => [
                'address' => '380 Wilfred Road', 'suburb' => '', 'town' => 'Marburg',
                'city' => 'Port Shepstone', 'street_name' => 'Wilfred Road', 'complex_name' => 'Seaview',
            ],
            'recipients' => ['recipients' => [[
                'role' => 'seller', 'name' => 'Nomsa Dlamini', 'first_name' => 'Nomsa', 'last_name' => 'Dlamini',
                'address' => '12 Beach Rd, Uvongo', 'id_number' => '8501015800088',
                'email' => 'nomsa@example.co.za', 'cell' => '083 455 2019',
            ]]],
            'details' => ['price' => 2350000],
        ];
    }

    /** THE FIX: seller address/tel/email/id resolve to the ATTRIBUTE, not the seller's name. */
    public function test_seller_contact_fields_resolve_to_their_attribute_not_the_name(): void
    {
        $addr  = $this->namedField('Seller Address', 'contact', 'address', 'Seller');
        $tel   = $this->namedField('Seller Tel', 'contact', 'phone', 'Seller');
        $email = $this->namedField('Seller Email', 'contact', 'email', 'Seller');
        $id    = $this->namedField('Seller ID', 'contact', 'id_number', 'Seller');

        $data = $this->resolve([
            $this->mapping('seller_address', 'sf:contact_seller', 'contact', 'Seller', $addr),
            $this->mapping('seller_tel',     'sf:contact_seller', 'contact', 'Seller', $tel),
            $this->mapping('seller_email',   'sf:contact_seller', 'contact', 'Seller', $email),
            $this->mapping('seller_id',      'sf:contact_seller', 'contact', 'Seller', $id),
        ], $this->stepData());

        $this->assertSame('12 Beach Rd, Uvongo', $data['seller_address'] ?? null);
        $this->assertSame('083 455 2019',        $data['seller_tel'] ?? null);
        $this->assertSame('nomsa@example.co.za', $data['seller_email'] ?? null);
        $this->assertSame('8501015800088',       $data['seller_id'] ?? null);

        // The regression: none of them is the seller's NAME.
        foreach (['seller_address', 'seller_tel', 'seller_email', 'seller_id'] as $k) {
            $this->assertNotSame('Nomsa Dlamini', $data[$k] ?? null, "$k must not be the seller name");
        }
    }

    /** B1 — the sf:property TOWN component resolves (380 Wilfred's area is in `town`, suburb blank). */
    public function test_property_town_component_resolves_from_town(): void
    {
        $town   = $this->namedField('Town', 'property', 'town', null);
        $street = $this->namedField('Street', 'property', 'street_name', null);

        $data = $this->resolve([
            $this->mapping('property_town',   'sf:property', 'property', null, $town),
            $this->mapping('property_street', 'sf:property', 'property', null, $street),
        ], $this->stepData());

        $this->assertSame('Marburg', $data['property_town'] ?? null, 'township/town resolves even with suburb blank');
        $this->assertSame('Wilfred Road', $data['property_street'] ?? null);
    }

    /** B3 — an sf:property price-in-words component runs the rand converter (no label needed). */
    public function test_price_in_words_component_resolves_to_words(): void
    {
        $words = $this->namedField('Asking price in words', 'property', 'price_in_words', null);

        $data = $this->resolve([
            $this->mapping('asking_price_in_words', 'sf:property', 'property', null, $words),
        ], $this->stepData());

        $this->assertSame('Two million three hundred and fifty thousand Rand', $data['asking_price_in_words'] ?? null);
    }

    /** A named field with EMPTY source_column falls back to a dotted key, then the label. */
    public function test_attribute_falls_back_to_dotted_key_then_label(): void
    {
        // No source_column; the field label carries the attribute (hand-tagged case).
        $nf = $this->namedField('Seller - Physical address', 'contact', null, 'Seller');

        $map = $this->mapping('seller_addr2', 'sf:contact_seller', 'contact', 'Seller', $nf);
        $map['label'] = 'Seller - Physical address';

        $data = $this->resolve([$map], $this->stepData());

        $this->assertSame('12 Beach Rd, Uvongo', $data['seller_addr2'] ?? null);
    }
}
