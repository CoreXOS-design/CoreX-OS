<?php

namespace Tests\Unit;

use App\Services\Docuperfect\DocumentTemplateGenerator;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The older Mammoth importer writes imported text into resources/views/docuperfect/web-templates/imported/*.blade.php.
 * Template names and field labels must not be able to run as Blade/PHP (see BladeSafe).
 */
class DocumentTemplateGeneratorBladeSafeTest extends TestCase
{
    private function invoke(string $method, array $args): string
    {
        $m = new ReflectionMethod(DocumentTemplateGenerator::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(app(DocumentTemplateGenerator::class), $args);
    }

    public function test_template_name_in_the_blade_shell_is_inert(): void
    {
        $blade = $this->invoke('wrapInBladeTemplate', ['<p>x</p>', "{{ 7*7 }} {!! 'a' !!} @php echo 'pwned'; @endphp"]);

        $title = substr($blade, strpos($blade, '<title>'), strpos($blade, '</title>') - strpos($blade, '<title>'));
        $this->assertStringNotContainsString('{{', $title);
        $this->assertStringNotContainsString('{!!', $title);
        $this->assertStringNotContainsString('@php', $title);
    }

    public function test_esc_neutralises_blade_syntax_and_html(): void
    {
        $out = $this->invoke('esc', ['<b>{{ 1 }}</b> @include("x") <?php x ?>']);

        $this->assertStringNotContainsString('{{', $out);
        $this->assertStringNotContainsString('@include', $out);
        $this->assertStringNotContainsString('<?', $out);
        $this->assertStringNotContainsString('<b>', $out);
    }
}
