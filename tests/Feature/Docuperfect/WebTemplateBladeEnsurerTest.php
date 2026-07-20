<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect;

use App\Models\Docuperfect\Template;
use App\Services\Docuperfect\WebTemplateBladeEnsurer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blank-preview regression (BUILD_STANDARD §3 — prevent-or-absorb): a web template
 * preview must NEVER blank on a missing generated blade artifact.
 *
 * The regeneration end-to-end (rewrite the blade from stored CDS) is proven functionally
 * on the deployed QA1 site (rename a blade → preview regenerates; re-save 67/68/70). These
 * cases pin the absorb guarantees that hold without full CDS fixtures.
 */
final class WebTemplateBladeEnsurerTest extends TestCase
{
    use RefreshDatabase;

    private function ensurer(): WebTemplateBladeEnsurer
    {
        return app(WebTemplateBladeEnsurer::class);
    }

    /** ensure() short-circuits (no regeneration) when the generated blade file is present. */
    public function test_ensure_returns_existing_blade_view_when_file_present(): void
    {
        // template-111.blade.php ships in the repo, so its blade file is on disk.
        $t = new Template(['name' => 'X', 'render_type' => 'web', 'blade_view' => 'docuperfect.web-templates.cds.template-111']);
        $t->id = 111;

        $this->assertSame('docuperfect.web-templates.cds.template-111', $this->ensurer()->ensure($t));
    }

    /** fallbackHtml() renders the stored tagged_html body behind a clear notice — never blank. */
    public function test_fallback_html_uses_stored_tagged_html_and_is_never_blank(): void
    {
        $t = new Template([
            'name'         => 'Fallback Template',
            'render_type'  => 'web',
            'editor_state' => ['tagged_html' => '<h1>STORED BODY CONTENT</h1>'],
        ]);

        $html = $this->ensurer()->fallbackHtml($t);

        $this->assertStringContainsString('STORED BODY CONTENT', $html, 'fallback must include the stored body');
        $this->assertStringContainsString('could not be generated', $html, 'fallback must carry a clear notice');
        $this->assertNotSame('', trim($html));
    }

    /** With no stored content, the fallback is still non-blank — a clear instruction, never empty. */
    public function test_fallback_html_is_non_blank_even_with_no_stored_content(): void
    {
        $t = new Template(['name' => 'Empty Template', 'render_type' => 'web']);

        $html = $this->ensurer()->fallbackHtml($t);

        $this->assertNotSame('', trim($html));
        $this->assertStringContainsString('rebuild its preview', $html);
    }
}
