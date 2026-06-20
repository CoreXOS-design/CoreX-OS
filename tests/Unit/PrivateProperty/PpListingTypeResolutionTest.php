<?php

namespace Tests\Unit\PrivateProperty;

use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use PHPUnit\Framework\TestCase;

/**
 * PP-7 — Sale-vs-Rental must resolve identically everywhere (submit + every
 * follow-up PP call), preferring listing_type then mandate_type.
 *
 * Audit: .ai/audits/syndication-bug-sweep-2026-06-20.md (PP-7)
 */
class PpListingTypeResolutionTest extends TestCase
{
    /** @dataProvider cases */
    public function test_resolve_listing_type(?string $listingType, ?string $mandateType, string $expected): void
    {
        $p = (new Property())->forceFill([
            'listing_type' => $listingType,
            'mandate_type' => $mandateType,
        ]);

        $this->assertSame($expected, PrivatePropertyListingMapper::resolveListingType($p));
    }

    public static function cases(): array
    {
        return [
            'sole rental (listing_type wins)' => ['rental', 'sole', 'Rental'],
            'sale listing over rental mandate' => ['sale', 'rental', 'Sale'],
            'null listing_type falls back to mandate' => [null, 'rental', 'Rental'],
            'null listing_type, sole mandate' => [null, 'sole', 'Sale'],
            'both null defaults to Sale' => [null, null, 'Sale'],
        ];
    }
}
