<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sibling of Properties\PropertyTypedAddressKeepsStreetTest. On a Property the structured-address step
 * lifted the house NUMBER out of a typed address but not the street NAME, so "12 Beach Road" collapsed
 * to "12". This pins what a TRACKED property does with the same typed text, entering through the one
 * write path every ingress uses (TrackedPropertyMatchOrCreateService::matchOrCreate) and through the
 * model directly: the address it displays must read exactly what was typed — never a number alone,
 * never a number doubled ("12 12 Beach Road").
 * Spec: .ai/specs/structured-address-matching.md §14.3.
 */
final class TrackedPropertyTypedAddressKeepsStreetTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency-' . uniqid()]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> typed facts => what the property must display */
    public static function typedShapes(): array
    {
        return [
            'number + street typed as the street line'   => [['street_name' => '12 Beach Road'], '12 Beach Road, Margate'],
            'number and street in their own columns'     => [['street_number' => '12', 'street_name' => 'Beach Road'], '12 Beach Road, Margate'],
            '12A + street typed as the street line'      => [['street_name' => '12A Beach Road'], '12A Beach Road, Margate'],
            'street only, no number'                     => [['street_name' => 'Beach Road'], 'Beach Road, Margate'],
        ];
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function typedShapesThroughTheWriter(): array
    {
        // Only the one write path normalises a street ("Unit 3, " prefix stripped) before the save; saving such text
        // straight on the model is not an ingress anything uses.
        return self::typedShapes() + [
            'unit + number + street typed together' => [['street_name' => 'Unit 3, 12 Beach Road'], '12 Beach Road, Margate'],
        ];
    }

    /** @param array<string, mixed> $typed */
    #[DataProvider('typedShapesThroughTheWriter')]
    public function test_a_typed_address_through_match_or_create_keeps_its_street(array $typed, string $expected): void
    {
        $tp = (new TrackedPropertyMatchOrCreateService())->matchOrCreate(
            $this->agency->id,
            $typed + ['suburb' => 'Margate'],
            ['type' => 'manual', 'ref' => 'typed-' . uniqid()],
        );

        $fresh = TrackedProperty::queryWithoutAgencyScope()->findOrFail($tp->id);

        // The writer stores a street lower-cased after its digits ("12a Beach Road") — case is not what this pins.
        $this->assertEqualsIgnoringCase($expected, $fresh->displayAddress());
    }

    /** @param array<string, mixed> $typed */
    #[DataProvider('typedShapes')]
    public function test_a_typed_address_saved_straight_on_the_model_keeps_its_street(array $typed, string $expected): void
    {
        $tp = new TrackedProperty($typed + ['suburb' => 'Margate']);
        $tp->agency_id = $this->agency->id;
        $tp->save();

        $this->assertSame($expected, TrackedProperty::queryWithoutAgencyScope()->findOrFail($tp->id)->displayAddress());
    }

    public function test_editing_an_unrelated_column_leaves_the_address_alone(): void
    {
        $tp = new TrackedProperty(['street_name' => '12 Beach Road', 'suburb' => 'Margate']);
        $tp->agency_id = $this->agency->id;
        $tp->save();
        $before = TrackedProperty::queryWithoutAgencyScope()->findOrFail($tp->id)->displayAddress();

        $tp->refresh();
        $tp->bedrooms = 3;
        $tp->save();

        $this->assertSame($before, TrackedProperty::queryWithoutAgencyScope()->findOrFail($tp->id)->displayAddress());
    }
}
