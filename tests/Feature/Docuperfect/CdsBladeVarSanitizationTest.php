<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Http\Controllers\Docuperfect\TemplateController;
use App\Services\WebTemplateDataService;
use Illuminate\Support\Facades\Blade;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-359b — the CDS blade generator turned a composite named-field source_column
 * (property "address+suburb") into a blade variable {{ $property_address+suburb }}. PHP reads the
 * bare "suburb" as an undefined constant, so the WHOLE template render threw and the e-sign preview
 * dropped to the "formatted view could not be generated — re-save the template" fallback banner.
 * Re-save never cleared it because regeneration reproduced the same invalid variable every time.
 *
 * deriveBladeName() (duplicated in TemplateController + WebTemplateDataService) now coerces its
 * result to a valid PHP identifier at the single exit, so BOTH the blade emitter and the view-data
 * resolver derive the SAME safe name — the render stops throwing AND the composite value populates.
 *
 * No DB: exercises the pure derivation + the Blade compiler only.
 */
final class CdsBladeVarSanitizationTest extends TestCase
{
    private function derive(object $instance, string $sourceType, string $sourceColumn, string $contactType = ''): ?string
    {
        $m = new ReflectionMethod($instance, 'deriveBladeName');
        $m->setAccessible(true);

        return $m->invoke($instance, $sourceType, $sourceColumn, $contactType);
    }

    /** The composite property field yields a valid identifier — no '+' leaks into the name. */
    public function test_composite_property_column_sanitised_to_valid_identifier(): void
    {
        foreach ([app(TemplateController::class), app(WebTemplateDataService::class)] as $svc) {
            $var = $this->derive($svc, 'property', 'address+suburb');
            $this->assertSame('property_address_suburb', $var, $svc::class);
            $this->assertMatchesRegularExpression('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $var, $svc::class);
        }
    }

    /** Both duplicated copies must derive the SAME name so blade var == view-data key. */
    public function test_both_copies_agree_on_the_sanitised_name(): void
    {
        $a = $this->derive(app(TemplateController::class), 'property', 'address+suburb');
        $b = $this->derive(app(WebTemplateDataService::class), 'property', 'address+suburb');
        $this->assertSame($a, $b);
    }

    /** A field span built from the sanitised var name compiles and renders the value — no throw. */
    public function test_blade_echo_from_sanitised_var_renders_without_throwing(): void
    {
        $var  = $this->derive(app(TemplateController::class), 'property', 'address+suburb');
        $html = Blade::render('{{ $' . $var . ' ?? \'\' }}', [$var => '12 Main Road, Uvongo']);
        $this->assertSame('12 Main Road, Uvongo', $html);
    }

    /**
     * Guard-the-guard: the PRE-FIX raw name (with the '+') genuinely throws when compiled, proving
     * the test above exercises the real failure class rather than a no-op.
     */
    public function test_unsanitised_composite_var_would_throw(): void
    {
        $this->expectException(\Throwable::class);
        Blade::render('{{ $property_address+suburb ?? \'\' }}', []);
    }

    /** Ordinary (already-valid) names are unchanged by the sanitiser. */
    public function test_plain_names_are_unchanged(): void
    {
        $tc = app(TemplateController::class);
        $this->assertSame('property_street',   $this->derive($tc, 'property', 'address'));
        $this->assertSame('lessor_name',       $this->derive($tc, 'contact', 'first_name+last_name', 'Lessor'));
        $this->assertSame('property_district', $this->derive($tc, 'property', 'district'));
    }
}
