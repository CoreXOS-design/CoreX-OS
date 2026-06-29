<?php

namespace Tests\Unit\PrivateProperty;

use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use PHPUnit\Framework\TestCase;

/**
 * PP60 — PP rejects a listing that carries a Unit Number without a
 * Complex/Scheme name ("PP60 - The address details are insufficient, please
 * add a Scheme/Complex name and resubmit."). CoreX must catch this pre-submit
 * with a clear message instead of letting the cryptic SOAP fault reach the
 * agent. Trigger case: Property 3206 (Townhouse, unit 5, no complex_name).
 */
class PpComplexNameRequiredTest extends TestCase
{
    private function validate(array $overrides): array
    {
        $payload = array_merge([
            'PropertyId'    => '1',
            'BranchId'      => 'B',
            'Category'      => ['Category' => 'Residential'],
            'MandateType'   => 'FullMandate',
            'ListingType'   => 'Sale',
            'PropertyStatus' => 'ForSale',
            'Price'         => 1000000.0,
            'Description'   => 'A home.',
            'Headline'      => 'A home',
            'AgentId'       => '123',
            'StreetName'    => 'Lilliecrona Boulevard',
            'StreetNumber'  => '78',
            'Suburb'        => 'Manaba Beach',
            'Town'          => 'Margate',
            'Province'      => 'KwaZuluNatal',
            'UnitNumber'    => '',
            'ComplexName'   => '',
        ], $overrides);

        return (new PrivatePropertyListingMapper())->validate($payload);
    }

    private function hasPp60(array $errors): bool
    {
        foreach ($errors as $e) {
            if (str_contains($e, 'PP60')) {
                return true;
            }
        }
        return false;
    }

    public function test_unit_number_without_complex_name_is_blocked(): void
    {
        $errors = $this->validate(['UnitNumber' => '5', 'ComplexName' => '']);
        $this->assertTrue($this->hasPp60($errors), 'Expected a PP60 error when UnitNumber is set but ComplexName is empty.');
    }

    public function test_unit_number_with_complex_name_passes(): void
    {
        $errors = $this->validate(['UnitNumber' => '5', 'ComplexName' => 'Beachfront Villas']);
        $this->assertFalse($this->hasPp60($errors), 'A unit with a complex name must not trigger PP60.');
    }

    public function test_no_unit_number_does_not_require_complex_name(): void
    {
        $errors = $this->validate(['UnitNumber' => '', 'ComplexName' => '']);
        $this->assertFalse($this->hasPp60($errors), 'A freestanding property (no unit number) must not trigger PP60.');
    }
}
