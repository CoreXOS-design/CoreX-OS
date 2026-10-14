<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Services\Docuperfect\RoleBlockNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * 14 Oct 2026 — two faults, one cause. In the lease's clause 5.5.3 two inline directives sat back to back
 * (`@endif{{-- --}}@if(...)`); Blade only compiles a directive that is not glued to a word character, so one
 * `@if(` and one `@endif` printed literally in the lease — and, because the unbalanced `@endif` pairing left a
 * stray `</div>`, the Fill & Review preview (RoleBlockNormalizer: the document is parsed inside a wrapper div)
 * lost every page after page 2, Breakdown included. Both are bug-class: checked for EVERY web template.
 */
final class LeaseDocumentRendersCleanTest extends TestCase
{
    use RefreshDatabase;

    private const DIRECTIVE = '/(?<!@)@(?:if|elseif|else|endif|foreach|endforeach|forelse|endforelse|empty|endempty|isset|endisset|unless|endunless|for|endfor|while|endwhile|php|endphp|include|includeIf|section|endsection|yield)\b/';

    private function render(string $view, array $data = []): string
    {
        // The shared letterhead component looks the agency up by this slug and several templates then read it
        // (privacy-policy link) — without a row they cannot render at all. A fixture row, nothing more.
        static $agency = null;
        $agency ??= Agency::query()->where('slug', 'hfc-coastal')->first()
            ?? Agency::create(['name' => 'Cape Rentals', 'slug' => 'hfc-coastal', 'phone' => '021 555 0100', 'email' => 'hello@caperentals.test']);

        return view($view, ['agency' => $agency] + $data)->render();
    }

    /** @return list<string> every web-template blade, as a view name => file */
    private function templates(): array
    {
        $dir = resource_path('views/docuperfect/web-templates');
        $files = array_merge(glob($dir . '/*.blade.php') ?: [], glob($dir . '/*/*.blade.php') ?: []);
        sort($files);
        $this->assertGreaterThan(60, count($files), 'the template folder was not found');

        return $files;
    }

    private function viewName(string $file): string
    {
        return str_replace(['/', '.blade.php'], ['.', ''], substr($file, strlen(resource_path('views/'))));
    }

    /** Leftover template syntax in text a person would read (PHP, <script> and <style> bodies removed). */
    private function leftovers(string $text): array
    {
        $text = preg_replace('/<\?php.*?\?>/s', '', $text);
        $text = preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $text);
        preg_match_all(self::DIRECTIVE, $text, $m);

        return array_values(array_unique(array_merge($m[0], substr_count($text, '{{') ? ['{{'] : [])));
    }

    public function test_the_scanner_catches_the_defect_it_exists_for(): void
    {
        $glued = '<p>a</p>@if($x)b@endif{{-- --}}@if($y)c@endif.';
        $this->assertNotSame([], $this->leftovers(Blade::compileString($glued)));
        $this->assertSame([], $this->leftovers(Blade::compileString("<p>a</p>\n@if(\$x)\nb\n@endif\n@if(\$y)\nc\n@endif\n")));
    }

    public function test_no_template_directive_or_brace_survives_compiling_any_web_template(): void
    {
        $bad = [];
        foreach ($this->templates() as $file) {
            $left = $this->leftovers(Blade::compileString((string) file_get_contents($file)));
            if ($left !== []) {
                $bad[] = basename($file) . ' => ' . implode(' ', $left);
            }
        }

        $this->assertSame([], $bad, 'Template syntax would print literally: ' . implode('; ', $bad));
    }

    public function test_no_template_directive_or_brace_survives_rendering_any_web_template(): void
    {
        $bad = [];
        foreach ($this->templates() as $file) {
            $html = $this->render($this->viewName($file));
            $left = $this->leftovers($html);
            if ($left !== []) {
                $bad[] = basename($file) . ' => ' . implode(' ', $left);
            }
        }

        $this->assertSame([], $bad, 'Rendered document shows template syntax: ' . implode('; ', $bad));
    }

    /** @return array<string, array{0: string}> */
    public static function leaseNoticeData(): array
    {
        return [
            'nothing recorded' => [[]],
            'allowed only' => [['early_cancellation_allowed' => 'Yes']],
            'allowed + notice' => [['early_cancellation_allowed' => 'Yes', 'early_cancellation_notice' => '2', 'early_cancellation_notice_unit' => 'months']],
            'allowed + penalty' => [['early_cancellation_allowed' => 'Yes', 'early_cancellation_penalty' => 'two months rent']],
            'everything' => [[
                'notice_period' => '20', 'notice_period_unit' => 'business days', 'earliest_notice_date' => '1 Jan 2027',
                'early_cancellation_allowed' => 'Yes', 'early_cancellation_notice' => '2', 'early_cancellation_notice_unit' => 'months',
                'early_cancellation_penalty' => 'two months rent',
            ]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('leaseNoticeData')]
    public function test_the_lease_prints_every_page_with_no_literal_syntax_whatever_notice_terms_it_holds(array $data): void
    {
        $html = $this->render('docuperfect.web-templates.lease-agreement-popi-v8', $data);

        $this->assertSame([], $this->leftovers($html));
        $this->assertSame(6, substr_count($html, 'class="page'));
        $this->assertSame(substr_count($html, '<div'), substr_count($html, '</div>'), 'every <div> the lease opens is closed');
        $this->assertStringContainsString('Net Amount to Owner', $html);
    }

    public function test_the_early_cancellation_sentence_reads_correctly_with_all_its_parts(): void
    {
        $html = $this->render('docuperfect.web-templates.lease-agreement-popi-v8', [
            'early_cancellation_allowed' => 'Yes', 'early_cancellation_notice' => '2', 'early_cancellation_notice_unit' => 'months',
            'early_cancellation_penalty' => 'two months rent',
        ]);

        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));
        $this->assertStringContainsString('is permitted: Yes, on 2 months written notice, subject to: two months rent.', $text);
    }

    public function test_the_fill_and_review_preview_keeps_every_page_of_every_lease_template(): void
    {
        $normalizer = app(RoleBlockNormalizer::class);
        $checked = 0;
        foreach ($this->templates() as $file) {
            if (! preg_match('/lease|letting|rental/i', basename($file)) || str_contains($file, '/imported/')) {
                continue;
            }
            $html = $this->render($this->viewName($file));
            $body = preg_match('/<body[^>]*>(.*)<\/body>/si', $html, $m) ? trim($m[1]) : $html;
            $out = $normalizer->normalize($body);
            $checked++;

            $this->assertSame(substr_count($body, 'class="page'), substr_count($out, 'class="page'), basename($file) . ': pages lost between the template and the preview');
            $this->assertSame(substr_count($body, '<div'), substr_count($body, '</div>'), basename($file) . ': unbalanced <div> (it would cut the preview short)');
            $this->assertGreaterThan(strlen($body) * 0.9, strlen($out), basename($file) . ': preview lost content');
        }
        $this->assertGreaterThan(2, $checked);
    }
}
