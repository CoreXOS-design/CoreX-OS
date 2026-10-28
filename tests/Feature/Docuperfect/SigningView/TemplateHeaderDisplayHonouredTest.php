<?php

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Template;
use Tests\TestCase;

/**
 * A template's "header_display" setting (all_pages / first_page / none) decides whether CoreX's own agency letterhead block
 * prints above the document - on EVERY surface that renders the compiled page, not only the builder preview. Found on Mega's
 * lease: set to "none" (the Word header is the letterhead) the wizard / agent review still printed the generic agency block
 * because those render paths never passed the setting. Spec: cds-reimport-from-word.md section 10. The pack test uses an unsaved Agency.
 */
class TemplateHeaderDisplayHonouredTest extends TestCase
{
    public function test_the_setting_travels_as_view_data_and_an_unset_template_adds_nothing(): void
    {
        $this->assertSame(['header_display' => 'none'], (new Template(['header_display' => 'none']))->headerDisplayViewData());
        $first = (new Template(['header_display' => 'first_page']))->headerDisplayViewData();
        $this->assertSame('first_page', $first['header_display']);
        $this->assertNotSame('', $first['header_render_key'], 'first_page is counted per render');
        $this->assertNotSame($first['header_render_key'], (new Template(['header_display' => 'first_page']))->headerDisplayViewData()['header_render_key'], 'every render gets its own key');
        $this->assertSame(['header_display' => 'all_pages'], (new Template(['header_display' => 'all_pages']))->headerDisplayViewData());
        $this->assertSame([], (new Template(['header_display' => null]))->headerDisplayViewData(), 'no setting: the page keeps its own default');
        $this->assertSame([], (new Template(['header_display' => 'bogus']))->headerDisplayViewData());
    }

    public function test_the_letterhead_component_prints_nothing_for_none(): void
    {
        $this->assertSame('', trim(view('docuperfect.web-templates.components.company-header', ['header_display' => 'none'])->render()));
    }

    public function test_a_pack_of_templates_in_one_request_each_keep_their_letterhead_under_first_page(): void
    {
        $agency = (new \App\Models\Agency())->forceFill(['trading_name' => 'Second Agency Realty', 'name' => 'Second Agency']);
        $render = fn (array $data) => view('docuperfect.web-templates.components.company-header', $data + ['previewAgency' => $agency])->render();

        $docA = (new Template(['header_display' => 'first_page']))->headerDisplayViewData();
        $docB = (new Template(['header_display' => 'first_page']))->headerDisplayViewData();

        $this->assertStringContainsString('Second Agency Realty', $render($docA), 'document 1 prints its letterhead');
        $this->assertSame('', trim($render($docA)), 'a second include in the SAME document render prints nothing (first page only)');
        $this->assertStringContainsString('Second Agency Realty', $render($docB), 'document 2 of the pack, same request, still prints its own letterhead');
    }

    public function test_every_render_path_passes_the_setting_to_the_compiled_page(): void
    {
        // The five places a compiled web template is rendered with view data (wizard / preview choke point, agent review x2,
        // signing page, freshness re-render): each hands the template's setting over.
        $sites = [
            'app/Services/Docuperfect/WebTemplateBladeEnsurer.php' => 1,
            'app/Services/Docuperfect/MergedHtmlFreshnessGuard.php' => 1,
            'app/Http/Controllers/Docuperfect/ESignWizardController.php' => 3,
            'app/Http/Controllers/Docuperfect/SignatureController.php' => 2,
            'app/Http/Controllers/Docuperfect/SigningController.php' => 1,
        ];
        foreach ($sites as $file => $min) {
            $this->assertGreaterThanOrEqual($min, substr_count(file_get_contents(base_path($file)), 'headerDisplayViewData()'), $file . ' must pass header_display to the compiled page');
        }
    }
}
