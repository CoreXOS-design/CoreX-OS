<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Property;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AT-456 finding — Property::isOnMarket() compared status case-sensitively, so a "Sold" written by the
 * P24 importer passed as on-market. One place decides it now, case- and whitespace-insensitively.
 */
final class PropertyIsOnMarketCaseTest extends TestCase
{
    #[DataProvider('offMarketSpellings')]
    public function test_off_market_status_is_off_market_whatever_its_case_or_spacing(string $status): void
    {
        $this->assertFalse((new Property(['status' => $status]))->isOnMarket(), "'{$status}' must be off-market");
    }

    public static function offMarketSpellings(): array
    {
        return [
            'lowercase'        => ['sold'],
            'capitalised'      => ['Sold'],
            'shouting'         => ['SOLD'],
            'padded'           => [' sold '],
            'padded + mixed'   => ["\t Sold\n"],
            'label with space' => ['Let Out'],
            'sold by 3rd party label' => ['Sold By 3rd Party'],
            'rented'           => ['Rented'],
        ];
    }

    #[DataProvider('onMarketSpellings')]
    public function test_on_market_status_stays_on_market(?string $status): void
    {
        $this->assertTrue((new Property(['status' => $status]))->isOnMarket());
    }

    public static function onMarketSpellings(): array
    {
        return [['for_sale'], ['For Sale'], ['active'], ['under_offer'], ['Reduced Price'], [null], ['']];
    }
}
