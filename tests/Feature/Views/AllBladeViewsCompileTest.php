<?php

namespace Tests\Feature\Views;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Permanent guard for the "glued Blade control-directive" defect class
 * (AT-182). A control directive fused to an adjacent word character — e.g.
 * `note@if(...)` or `}@elseif` — is silently NOT recognised by Blade, so its
 * partner `@endif`/`@elseif` is left dangling and the COMPILED PHP is invalid.
 * The symptom is a 500 at request time ("syntax error, unexpected token
 * 'elseif'") that no unit test catches because the raw .blade file "looks
 * fine" and nothing compiles it until a browser hits that exact branch.
 *
 * This class died once (a single view was fixed) and came back because the
 * sweep was too narrow. This test kills it structurally: it compiles EVERY
 * Blade view in resources/views through the real compiler (with all custom
 * directives registered by the booted app) and asserts the resulting PHP
 * parses. Any new glued directive anywhere in the tree fails here — before it
 * can reach production.
 *
 * It touches no database and renders nothing; it only proves each template
 * compiles to valid PHP.
 */
class AllBladeViewsCompileTest extends TestCase
{
    public function test_every_blade_view_compiles_to_valid_php(): void
    {
        $viewRoot = resource_path('views');
        $this->assertDirectoryExists($viewRoot);

        $failures = [];
        $checked  = 0;

        foreach ($this->bladeFiles($viewRoot) as $file) {
            $checked++;
            $raw = file_get_contents($file);
            if ($raw === false) {
                $failures[] = $this->relative($file) . ' — unreadable';
                continue;
            }

            try {
                $compiled = Blade::compileString($raw);
            } catch (\Throwable $e) {
                $failures[] = $this->relative($file) . ' — compile threw: ' . $e->getMessage();
                continue;
            }

            // The glued-directive class produces syntactically INVALID compiled
            // PHP (a dangling elseif/endif). token_get_all in parse mode throws
            // \ParseError on exactly that — which compileString itself does not.
            // Compiled Blade is a TEMPLATE (inline HTML plus PHP tag islands),
            // so it is passed as-is: the lexer starts in inline-HTML mode and
            // validates the PHP islands, catching a dangling directive without
            // tripping over the leading markup.
            try {
                token_get_all($compiled, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $failures[] = $this->relative($file) . ' — invalid compiled PHP: ' . $e->getMessage();
            }
        }

        $this->assertGreaterThan(0, $checked, 'No Blade views were found to check.');

        $this->assertSame(
            [],
            $failures,
            "Blade views that do not compile to valid PHP (glued-directive class — AT-182):\n"
                . implode("\n", $failures)
        );
    }

    /**
     * @return iterable<string>
     */
    private function bladeFiles(string $root): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                yield $file->getPathname();
            }
        }
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
    }
}
