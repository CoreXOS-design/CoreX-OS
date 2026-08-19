<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Http\Controllers\Docuperfect\TemplateController;
use App\Services\WebTemplateDataService;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-359b — input-space hardening for the CDS blade-variable sanitiser.
 *
 * CdsBladeVarSanitizationTest pins the ONE field that produced the live bug ("address+suburb").
 * This file widens the net: for a spread of dirty source_column shapes — every character class a
 * composite/named field can carry, plus a leading-digit column — the sanitiser must ALWAYS emit a
 * valid PHP identifier, the TWO duplicated copies (TemplateController + WebTemplateDataService) must
 * ALWAYS agree (they are the blade emitter and the view-data resolver — drift reintroduces the
 * "formatted view could not be generated" fallback), and a blade span built from the emitted name
 * must compile and render without throwing.
 *
 * Pure (no DB): exercises deriveBladeName() + the Blade compiler only.
 */
final class CdsBladeVarSanitizationInputSpaceTest extends TestCase
{
    private function derive(object $instance, string $sourceType, string $sourceColumn, string $contactType = ''): ?string
    {
        $m = new ReflectionMethod($instance, 'deriveBladeName');
        $m->setAccessible(true);

        return $m->invoke($instance, $sourceType, $sourceColumn, $contactType);
    }

    /** @return array<string, array{0:string,1:string,2:string}> [sourceType, sourceColumn, contactType] */
    public static function dirtyColumns(): array
    {
        return [
            'composite plus (the live bug)'   => ['property', 'address+suburb', ''],
            'ampersand'                        => ['property', 'lot&plan', ''],
            'forward slash'                    => ['property', 'erf/portion', ''],
            'backslash'                        => ['property', 'a\\b', ''],
            'spaces'                           => ['property', 'street name here', ''],
            'punctuation soup'                 => ['property', 'a.b-c(d)', ''],
            'unicode'                          => ['property', 'stadé', ''],
            'contact composite'               => ['contact', 'first_name+last_name', 'Lessor'],
            'leading digit (computed)'         => ['computed', '123_amount_words', ''],
            'leading digit (deal)'             => ['deal', '9lives', ''],
        ];
    }

    #[DataProvider('dirtyColumns')]
    public function test_every_dirty_column_yields_a_valid_identifier_from_both_copies(string $sourceType, string $sourceColumn, string $contactType): void
    {
        $tc  = $this->derive(app(TemplateController::class), $sourceType, $sourceColumn, $contactType);
        $wds = $this->derive(app(WebTemplateDataService::class), $sourceType, $sourceColumn, $contactType);

        $this->assertNotNull($tc);
        $this->assertMatchesRegularExpression('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $tc, "TemplateController emitted an invalid identifier for {$sourceType}/{$sourceColumn}");
        $this->assertSame($tc, $wds, "the two deriveBladeName copies disagreed for {$sourceType}/{$sourceColumn}");
    }

    #[DataProvider('dirtyColumns')]
    public function test_blade_span_from_emitted_name_renders_without_throwing(string $sourceType, string $sourceColumn, string $contactType): void
    {
        $var = $this->derive(app(TemplateController::class), $sourceType, $sourceColumn, $contactType);
        $html = Blade::render('{{ $' . $var . ' ?? \'\' }}', [$var => 'value-ok']);
        $this->assertSame('value-ok', $html);
    }

    /** A leading-digit raw name is guarded with an f_ prefix rather than emitting an illegal $123… var. */
    public function test_leading_digit_is_prefixed_not_left_illegal(): void
    {
        $var = $this->derive(app(TemplateController::class), 'computed', '123_amount_words');
        $this->assertSame('f_123_amount_words', $var);
        // and the two copies still agree on the guarded form
        $this->assertSame($var, $this->derive(app(WebTemplateDataService::class), 'computed', '123_amount_words'));
    }
}
