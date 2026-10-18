<?php

declare(strict_types=1);

namespace Tests\Feature\Terminology;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Johan's standing rule: CoreX says "evaluation", never "valuation" (14 Oct 2026; Road to Sold was renamed on 13 Oct).
 *
 * Scans what a person can READ - never identifiers, column names, enum keys, CSS classes, routes or comments:
 *   - every Blade view, compiled (HTML text, visible attributes, JS string literals with prose, PHP string literals with prose;
 *     covers the mail and PDF views too),
 *   - lang files (none today; scanned if one appears),
 *   - the prose strings of the PHP that builds mail, PDFs, settings / setup-wizard copy, tours, AI prompts and seeded reference text.
 *
 * Deliberate exceptions are listed below with the reason. A new one needs a reason a person can read, not a shrug.
 */
final class NoValuationWordingTest extends TestCase
{
    /** "valuation(s)" as a word - "evaluation" is fine, so are snake_case keys, CSS classes (valuation-strip) and ->valuation. */
    private const WORD = '/(?<![eE_\w$-])valuations?(?![_\w(-])/i';

    /** @var array<string,string> path (relative to the repo) => why the word has to stay there */
    private const ALLOWED = [
        'app/Services/CommercialEvaluation/CommercialEvaluationPdfService.php'
            => 'the disclaimer says the evaluation is NOT a formal valuation under the Property Valuers Profession Act (a statute and a regulated profession)',
        'app/Services/MarketReports/Parsers/CmaInfoPropertyValuationParser.php'
            => 'the supplier\'s own report is titled "Property Valuation"; the parser must match that text',
        'app/Services/MarketReports/Parsers/AbstractCmaInfoParser.php'
            => 'supplier report section headings the parser matches',
    ];

    private function root(): string
    {
        return base_path();
    }

    /** @return list<string> */
    private function files(array $dirs, string $ext): array
    {
        $out = [];
        foreach ($dirs as $dir) {
            $path = $this->root() . '/' . $dir;
            if (is_file($path)) {
                $out[] = $path;
                continue;
            }
            if (! is_dir($path)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), $ext)) {
                    $out[] = $f->getPathname();
                }
            }
        }
        sort($out);

        return $out;
    }

    private function rel(string $abs): string
    {
        return ltrim(substr($abs, strlen($this->root())), '/');
    }

    /** @return list<array{0:string,1:int}> each match with its byte offset */
    private function hits(string $text): array
    {
        preg_match_all(self::WORD, $text, $m, PREG_OFFSET_CAPTURE);

        return $m[0];
    }

    /** String literals containing a space (prose) in a chunk of JS / attribute code. */
    private function proseLiterals(string $code): array
    {
        preg_match_all('/\'((?:[^\'\\\\\n]|\\\\.)*)\'|"((?:[^"\\\\\n]|\\\\.)*)"|`((?:[^`\\\\]|\\\\.)*)`/s', $code, $m);
        $all = array_merge($m[1], $m[2], $m[3]);

        return array_values(array_filter($all, fn ($s) => $s !== '' && str_contains($s, ' ')));
    }

    /** What a person reads in a piece of HTML (script / style bodies and identifiers are not text). */
    private function htmlReadables(string $html): array
    {
        $read = [];
        $html = preg_replace('/<!--.*?-->/s', '', $html);
        $html = preg_replace('/<style\b.*?<\/style>/is', '', $html);

        if (preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $m)) {
            foreach ($m[1] as $js) {
                $js = preg_replace('#//[^\n]*#', '', $js);
                $js = preg_replace('#/\*.*?\*/#s', '', $js);
                array_push($read, ...$this->proseLiterals($js));
            }
        }
        $html = preg_replace('/<script\b.*?<\/script>/is', '', $html);

        // visible attributes
        if (preg_match_all('/\s(?:title|placeholder|alt|aria-label|data-confirm[\w-]*|data-tooltip|label|content)\s*=\s*"([^"]*)"|\s(?:title|placeholder|alt|aria-label|data-confirm[\w-]*|data-tooltip|label|content)\s*=\s*\'([^\']*)\'/i', $html, $m)) {
            array_push($read, ...array_filter(array_merge($m[1], $m[2])));
        }
        // Alpine expressions that build text
        if (preg_match_all('/\s(?:x-text|x-html|:title|:placeholder|:aria-label|x-bind:title)\s*=\s*"([^"]*)"/i', $html, $m)) {
            foreach ($m[1] as $expr) {
                array_push($read, ...$this->proseLiterals(html_entity_decode($expr)));
            }
        }

        $read[] = html_entity_decode(strip_tags(preg_replace('/<[^>]+>/', ' ', $html)));

        return $read;
    }

    /**
     * Readable prose of a PHP source (compiled Blade or a plain class / config file). Comments never appear in the tokens that matter.
     * The HTML pieces of a compiled view are joined first: Blade splits a <script> block around every {{ }} it holds.
     */
    private function phpReadables(string $source): array
    {
        $read = [];
        $html = '';
        foreach (token_get_all($source) as $t) {
            if (! is_array($t)) {
                continue;
            }
            [$id, $text] = $t;
            if ($id === T_INLINE_HTML) {
                $html .= $text;
            } elseif ($id === T_OPEN_TAG || $id === T_OPEN_TAG_WITH_ECHO || $id === T_CLOSE_TAG) {
                $html .= ' ';
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                $body = $id === T_CONSTANT_ENCAPSED_STRING ? substr($text, 1, -1) : $text;
                if (str_contains($body, ' ') || str_contains($body, "\n")) {
                    // a heredoc / nowdoc / long string can carry a whole HTML document (the PDFs do)
                    if (str_contains($body, '<')) {
                        array_push($read, ...$this->htmlReadables($body));
                    } else {
                        $read[] = $body;
                    }
                }
            }
        }
        if ($html !== '') {
            array_push($read, ...$this->htmlReadables($html));
        }

        return $read;
    }

    /** @param list<string> $texts */
    private function offending(array $texts): array
    {
        $bad = [];
        foreach ($texts as $text) {
            foreach ($this->hits($text) as [, $at]) {
                $bad[] = '"' . trim(preg_replace('/\s+/', ' ', substr($text, max(0, $at - 40), 100))) . '"';
            }
        }

        return array_values(array_unique($bad));
    }

    // ── the scanner itself ────────────────────────────────────────────────────────────────────

    public function test_the_scanner_catches_prose_and_leaves_identifiers_alone(): void
    {
        $this->assertNotSame([], $this->hits('Book a valuation'));
        $this->assertNotSame([], $this->hits('Valuations'));
        $this->assertNotSame([], $this->hits('CMA VALUATION'));
        $this->assertSame([], $this->hits('Book an evaluation, see your Evaluations'));
        $this->assertCount(1, $this->hits('x evaluation y valuation z'), 'only the bare word, at its own position');
        $this->assertSame([], $this->hits('valuation_booked cma_valuation $valuation valuation-strip municipal_valuation'));

        // views: text, visible attributes, JS prose - but not class names, ids, x-model or JS identifiers
        $view = '<div class="valuation-strip" id="cma-valuation-guardrail"><input x-model="valuation" name="valuation_booked">'
            . '<span title="Not recommended for valuation">Fine</span><script>this.valuation = true; toast("Valuation updated");</script></div>';
        $bad = $this->offending($this->htmlReadables($view));
        $this->assertCount(2, $bad, implode(' | ', $bad));

        $php = "<?php \$x = ['label' => 'Valuation band', 'key' => 'cma_valuation']; // a valuation comment\n/* valuation */";
        $this->assertCount(1, $this->offending($this->phpReadables($php)));
    }

    // ── what ships ────────────────────────────────────────────────────────────────────────────

    public function test_no_compiled_view_says_valuation(): void
    {
        $bad = [];
        foreach ($this->files(['resources/views'], '.blade.php') as $file) {
            $rel = $this->rel($file);
            $compiled = Blade::compileString((string) file_get_contents($file));
            foreach ($this->offending($this->phpReadables($compiled)) as $hit) {
                $bad[] = "{$rel}: {$hit}";
            }
        }

        $this->assertSame([], $bad, "CoreX says \"evaluation\", never \"valuation\":\n" . implode("\n", $bad));
    }

    public function test_no_lang_file_says_valuation(): void
    {
        $bad = [];
        foreach ($this->files(['lang', 'resources/lang'], '') as $file) {
            foreach ($this->hits((string) file_get_contents($file)) as [$hit]) {
                $bad[] = $this->rel($file) . ': ' . $hit;
            }
        }

        $this->assertSame([], $bad, implode("\n", $bad));
    }

    public function test_no_mail_pdf_settings_wizard_tour_or_prompt_text_says_valuation(): void
    {
        $dirs = [
            'app/Mail', 'app/Notifications', 'app/Services/AI', 'app/Services/Presentations', 'app/Services/Presentation',
            'app/Services/CommercialEvaluation', 'app/Services/MarketReports', 'app/Services/RoadToSold', 'app/Support/Tours',
            'app/Http/Controllers', 'app/Jobs', 'app/Listeners', 'app/Console/Commands',
            'config/agency-onboarding-copy.php', 'config/corex-features.php', 'config/corex-navigation-atlas.php',
            'config/corex-permissions.php', 'config/services.php', 'config/commercial_evaluation.php',
            'database/seeders',
        ];
        $bad = [];
        foreach ($this->files($dirs, '.php') as $file) {
            $rel = $this->rel($file);
            if (isset(self::ALLOWED[$rel])) {
                continue;
            }
            foreach ($this->offending($this->phpReadables((string) file_get_contents($file))) as $hit) {
                $bad[] = "{$rel}: {$hit}";
            }
        }

        $this->assertSame([], $bad, "CoreX says \"evaluation\", never \"valuation\" (a deliberate exception goes in ALLOWED with its reason):\n" . implode("\n", $bad));
    }

    public function test_every_allowed_exception_still_needs_to_be_one(): void
    {
        foreach (self::ALLOWED as $rel => $why) {
            $this->assertFileExists($this->root() . '/' . $rel, "{$rel} no longer exists - drop it from ALLOWED");
            $this->assertNotSame([], $this->offending($this->phpReadables((string) file_get_contents($this->root() . '/' . $rel))),
                "{$rel} no longer says the word - drop it from ALLOWED ({$why})");
        }
    }
}
