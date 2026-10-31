<?php

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\TemplateController;
use App\Support\Docuperfect\BladeSafe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Text imported from Word (body, table cells, running header / footer) is written into a generated Blade view.
 * It must print literally and never compile: {{ }}, {!! !!}, @php and <?php in a document are just words.
 * The generator's own merge fields ({{ $property_address }} etc.) must keep resolving.
 */
class ImportedTextIsNotBladeCodeTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE_ID = 987655;

    private const HOSTILE = "{{ 1234*2 }} {!! '<b>x</b>' !!} @php echo 'PWN'.'ED'; @endphp <?php echo 'PWN'.'ED2'; ?> @include('x') {{-- c --}}";

    /** @return array{0:string,1:string} [blade source, rendered html] */
    private function generateAndRender(array $cds, ?string $tagged): array
    {
        $controller = app(TemplateController::class);
        $view = $controller->generateCdsBladeView($cds, [], self::TEMPLATE_ID, 'Hostile {{ 8*8 }} @php echo 1; @endphp', null, $tagged, []);
        $path = resource_path('views/' . str_replace('.', '/', $view) . '.blade.php');
        $source = (string) file_get_contents($path);
        try {
            $html = view($view, ['property_address' => '12 Real Street'])->render();
        } finally {
            @unlink($path);
        }

        return [$source, $html];
    }

    private function assertInert(string $html): void
    {
        $text = html_entity_decode($html);
        $this->assertStringNotContainsString('2468', $html, '{{ 1234*2 }} was evaluated');
        $this->assertStringNotContainsString('PWNED', $html, 'imported @php / <?php code ran');
        $this->assertStringNotContainsString('<b>x</b>', $html, '{!! !!} was evaluated unescaped');
        $this->assertStringNotContainsString('<title>Hostile 64', $html, 'the template name in <title> was evaluated');
        $this->assertStringContainsString('{{ 1234*2 }}', $text);
        $this->assertStringContainsString("{!! '<b>x</b>' !!}", $text);
        $this->assertStringContainsString("@php echo 'PWN'.'ED'; @endphp", $text);
    }

    public function test_builder_html_body_and_table_cell_print_literally_while_merge_fields_resolve(): void
    {
        $tagged = '<div class="corex-clause"><span class="corex-clause-text">' . e(self::HOSTILE) . '</span></div>'
            . '<table><tr><td>' . e(self::HOSTILE) . '</td></tr></table>'
            . '<div>Premises: <span class="corex-merge" data-merge="property">[property address]</span></div>';

        [$source, $html] = $this->generateAndRender(['title' => 'T', 'sections' => []], $tagged);

        $this->assertInert($html);
        $this->assertSame(2, substr_count(html_entity_decode($html), '{{ 1234*2 }}'), 'body and table cell');
        $this->assertStringContainsString('Premises: 12 Real Street', $html, 'the merge chip became the document\'s own value');
        $this->assertStringNotContainsString('<?php', $source);
    }

    public function test_cds_body_table_and_page_header_footer_print_literally_while_frame_merge_fields_resolve(): void
    {
        $cds = [
            'title' => 'T',
            'sections' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'value' => self::HOSTILE]]],
                ['type' => 'table', 'rows' => [[self::HOSTILE]]],
            ],
            'page_header' => ['lines' => [['text' => self::HOSTILE . ' {PROPERTY}']]],
            'page_footer' => ['lines' => [['text' => self::HOSTILE, 'right' => 'Page {PAGE}']]],
        ];

        [$source, $html] = $this->generateAndRender($cds, null);

        $this->assertInert($html);
        $this->assertGreaterThanOrEqual(4, substr_count(html_entity_decode($html), '{{ 1234*2 }}'), 'body, table cell, header, footer');
        $this->assertStringContainsString('12 Real Street', $html, 'the {PROPERTY} frame token still becomes the document\'s own value');
        $this->assertStringNotContainsString('<?php', $source);
    }

    public function test_neutralise_keeps_style_blocks_and_email_addresses_intact(): void
    {
        $out = BladeSafe::neutralise('<style>@page{size:A4}@include("x")</style><p>mail a@b.com @php</p>');
        $this->assertStringContainsString('@@page{size:A4}', $out);
        $this->assertStringContainsString('@@include("x")', $out);
        $this->assertStringContainsString('a&#64;b.com &#64;php', $out);
    }
}
