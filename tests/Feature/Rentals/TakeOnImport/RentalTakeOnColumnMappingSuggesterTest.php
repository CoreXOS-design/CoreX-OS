<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals\TakeOnImport;

use App\Services\Rentals\TakeOnImport\RentalTakeOnColumnMappingSuggester;
use Tests\TestCase;

/**
 * .ai/specs/rental-takeon-import.md §11 (Landing 2) — "auto-suggest by
 * header name". No database needed — pure string matching.
 */
final class RentalTakeOnColumnMappingSuggesterTest extends TestCase
{
    public function test_suggests_by_exact_normalised_label_match(): void
    {
        $headers = ['Street number', 'Street name', 'Suburb', 'Tenant 1 — name', 'Monthly rental amount'];
        $suggested = (new RentalTakeOnColumnMappingSuggester())->suggest($headers);

        self::assertSame(0, $suggested['street_number']);
        self::assertSame(1, $suggested['street_name']);
        self::assertSame(2, $suggested['suburb']);
        self::assertSame(3, $suggested['tenant1_name']);
        self::assertSame(4, $suggested['monthly_rental_amount']);
    }

    public function test_suggests_by_common_crm_synonyms_in_a_different_order(): void
    {
        // A differently-shaped file: renamed, reordered, with an extra
        // column our template has no field for (must simply be ignored).
        $headers = ['Rent Amount', 'Property Street', 'Owner Name', 'Tenant Name', 'Erf Number', 'Internal CRM ID'];
        $suggested = (new RentalTakeOnColumnMappingSuggester())->suggest($headers);

        self::assertSame(0, $suggested['monthly_rental_amount']);
        self::assertSame(2, $suggested['landlord1_name']);
        self::assertSame(3, $suggested['tenant1_name']);
        self::assertArrayNotHasKey('notes', $suggested, 'an unrelated column must never be force-matched to something');
    }

    public function test_resolves_a_saved_mapping_against_a_new_files_headers_by_header_text(): void
    {
        $saved = ['tenant1_name' => 'Tenant Name', 'monthly_rental_amount' => 'Rent Amount', 'landlord1_name' => 'Owner Name'];

        // The SAME CRM's next export, with its columns in a different order —
        // this is exactly why mappings are stored as header TEXT, not index.
        $newHeaders = ['Owner Name', 'Rent Amount', 'Tenant Name'];
        $resolved = (new RentalTakeOnColumnMappingSuggester())->resolveSavedMapping($saved, $newHeaders);

        self::assertSame(0, $resolved['landlord1_name']);
        self::assertSame(1, $resolved['monthly_rental_amount']);
        self::assertSame(2, $resolved['tenant1_name']);
    }

    public function test_a_saved_field_whose_header_no_longer_exists_is_simply_omitted(): void
    {
        $saved = ['tenant1_name' => 'Tenant Name', 'monthly_rental_amount' => 'Old Rent Column'];
        $newHeaders = ['Tenant Name', 'Suburb'];

        $resolved = (new RentalTakeOnColumnMappingSuggester())->resolveSavedMapping($saved, $newHeaders);

        self::assertSame(0, $resolved['tenant1_name']);
        self::assertArrayNotHasKey('monthly_rental_amount', $resolved);
    }
}
