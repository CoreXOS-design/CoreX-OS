<?php

namespace Tests\Unit\PrivateProperty;

use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use PHPUnit\Framework\TestCase;

/**
 * AT-432 Phase 5 — .ai/specs/auctions.md §14.1a. `sale_method='auction'`
 * must win over whatever `mandate_type` the property carries, mapping to
 * PP's dedicated `AuctionOnly` MandateType (wsdl :141) rather than
 * whatever sole/open/dual would otherwise resolve to.
 */
class PpAuctionMandateTypeTest extends TestCase
{
    /** @dataProvider cases */
    public function test_resolve_mandate_type(?string $saleMethod, ?string $mandateType, string $expected): void
    {
        $p = (new Property())->forceFill([
            'sale_method' => $saleMethod,
            'mandate_type' => $mandateType,
        ]);

        $this->assertSame($expected, PrivatePropertyListingMapper::resolveMandateType($p));
    }

    public static function cases(): array
    {
        return [
            'auction wins over a sole mandate' => ['auction', 'sole', 'AuctionOnly'],
            'auction wins over a dual mandate' => ['auction', 'dual', 'AuctionOnly'],
            'auction wins with no mandate_type at all' => ['auction', null, 'AuctionOnly'],
            'not an auction falls back to the ordinary mandate map' => [null, 'sole', 'FullMandate'],
            'private-treaty dual mandate unaffected' => [null, 'dual', 'OpenMandate'],
        ];
    }
}
