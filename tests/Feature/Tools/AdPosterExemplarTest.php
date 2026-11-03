<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Services\AdTemplates\AdLayoutValidator;
use App\Services\AdTemplates\AdPosterExemplar;
use App\Services\AdTemplates\AiTemplateBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Making the reference-picture run more accurate (spec §22.13): the picture's colours win over the agency's brand colours, and the
 * model is shown one worked, tested poster so it knows how to size and write each kind of element.
 */
final class AdPosterExemplarTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_worked_example_is_a_valid_design_for_an_agency_with_ordinary_details(): void
    {
        $validator = (new AdLayoutValidator())->withAgencyCopy(['agency_name' => 'Coastal Realty Group', 'website' => '', 'agency_phone' => '']);
        $r = $validator->validateAndRepair(AdPosterExemplar::option(), AdPosterExemplar::SIZE);

        $this->assertTrue($r['ok'], implode(' | ', $r['errors']));
        $by = collect($r['layout']['elements']);
        $this->assertSame(0, (int) $by->firstWhere('field', 'status_badge')['bgOpacity'], 'the badge written on the stroke has its pill off');
        $this->assertTrue($by->firstWhere('shapeType', 'brush_stroke_1')['shapeGradient']);
        $this->assertSame(8, $by->firstWhere('field', 'feature_strip')['stripCount']);
    }

    public function test_the_worked_example_names_no_agency_person_or_place(): void
    {
        $json = (string) json_encode(AdPosterExemplar::option());

        foreach (['Muller', 'Home Finders', 'HFC', 'Sydenham', 'Sunet'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $json);
        }
        $this->assertSame([], array_filter(AdPosterExemplar::option()['elements'], fn ($e) => isset($e['text']) && $e['text'] !== ''), 'fields only, no fixed wording');
    }

    public function test_only_the_poster_size_gets_the_example(): void
    {
        $service = app(AiTemplateBuilderService::class);

        $this->assertSame('', $service->referenceExample('facebook'));
        $block = $service->referenceExample('pinterest');
        $this->assertStringContainsString('WORKED EXAMPLE', $block);
        $this->assertStringContainsString('brush_stroke_1', $block);
        $this->assertStringContainsString('Copy the TECHNIQUE, never the look', $block);
    }

    public function test_a_reference_picture_makes_the_picture_decide_the_colours_not_the_brand(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Agency ' . Str::random(6), 'slug' => 'ag-' . Str::random(8), 'default_color' => '#0b2a4a',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $service = app(AiTemplateBuilderService::class);

        $plain = $service->brandBlock($agencyId, false, false);
        $this->assertStringContainsString('Build the palette around these', $plain);

        $withPicture = $service->brandBlock($agencyId, false, true);
        $this->assertStringNotContainsString('Build the palette around these', $withPicture);
        $this->assertStringContainsString('the PICTURE decides the look', $withPicture);
        $this->assertStringContainsString('#0b2a4a', $withPicture, 'the brand colour is still named, as a fallback only');
    }
}
