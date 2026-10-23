<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Http\Controllers\Docuperfect\TemplateController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Document look — the published template wrapper. "Match source document" adds ONE class
 * (corex-look-source) to the .corex-document-wrapper of the generated blade; "CoreX
 * standard" (the default) leaves the wrapper byte-identical to before. The builder's
 * data-src-* attributes survive into the published blade so the stylesheet can act on them.
 */
final class CdsDocumentLookBladeTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE_ID = 987654;

    private function generate(?string $look): string
    {
        $tagged = '<div class="corex-h2" data-src-align="center" data-src-band="1">LEASE AGREEMENT</div>'
            . '<div class="corex-clause corex-clause-indent-1" data-src-align="justify"><span class="corex-clause-text">Body text.</span></div>';

        $controller = app(TemplateController::class);
        $args = [['title' => 'Look test', 'sections' => []], [], self::TEMPLATE_ID, 'Look test', null, $tagged, []];
        $view = $look === null
            ? $controller->generateCdsBladeView(...$args)
            : $controller->generateCdsBladeView(...$args, documentLook: $look);

        $path = resource_path('views/' . str_replace('.', '/', $view) . '.blade.php');
        $blade = (string) file_get_contents($path);
        @unlink($path);

        return $blade;
    }

    public function test_source_look_adds_the_wrapper_class_and_keeps_the_markers(): void
    {
        $blade = $this->generate('source');

        $this->assertStringContainsString('<div class="corex-document-wrapper corex-look-source">', $blade);
        $this->assertStringContainsString('data-src-align="center"', $blade);
        $this->assertStringContainsString('data-src-band="1"', $blade);
        $this->assertStringContainsString('data-src-align="justify"', $blade);
    }

    public function test_standard_look_and_the_default_leave_the_wrapper_unchanged(): void
    {
        foreach ([null, 'standard', 'garbage'] as $look) {
            $blade = $this->generate($look);
            $this->assertStringContainsString('<div class="corex-document-wrapper">', $blade);
            $this->assertStringNotContainsString('corex-look-source', $blade);
        }
    }
}
