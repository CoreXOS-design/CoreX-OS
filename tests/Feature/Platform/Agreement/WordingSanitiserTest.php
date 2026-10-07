<?php

namespace Tests\Feature\Platform\Agreement;

use App\Models\PlatformEsign\WordingVersion;
use App\Services\PlatformEsign\Agreement\AgreementBlocks;
use App\Services\PlatformEsign\Agreement\AgreementConsistency;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementPricing;
use App\Services\PlatformEsign\Agreement\AgreementRenderer;
use App\Services\PlatformEsign\Agreement\AgreementTokens;
use App\Services\PlatformEsign\Agreement\AgreementVersions;
use App\Services\PlatformEsign\Agreement\WordingInvalid;
use App\Support\Platform\SafeHtml;
use Tests\TestCase;

/**
 * Wording safety (audit E1, E3, E4, E6, E8, E9) — no database. The wording is owner-authored text that reaches the PUBLIC /legal page,
 * the recipient signing page and PDFs, so every rendered block is run through an allow-list sanitiser and the editor refuses what the
 * sanitiser would have to remove. These tests feed the real renderer the payloads that got through the old regex blacklist.
 */
class WordingSanitiserTest extends TestCase
{
    /** payload => a fragment that must NEVER survive into rendered output. */
    private function payloads(): array
    {
        return [
            'slash-separated handler'     => ['<p><img/src=x/onerror=alert(1)></p>', 'onerror'],
            'quote-adjacent handler'      => ['<div style="position:fixed;inset:0"onmouseover="alert(document.cookie)">x</div>', 'onmouseover'],
            'handler on a table cell'     => ['<table><tr><td/onmouseover=alert(1)>x</td></tr></table>', 'onmouseover'],
            'entity-encoded scheme'       => ['<p><a href="&#x6A;avascript:alert(1)">click</a></p>', 'avascript'],
            'newline inside the scheme'   => ["<p><a href=\"java&#x0A;script:alert(1)\">click</a></p>", 'script:'],
            'tab inside the scheme'       => ["<p><a href=\"jav&#x09;ascript:alert(1)\">click</a></p>", 'ascript:'],
            'remote image (tracker)'      => ['<p><img src="http://169.254.169.254/latest/meta-data/"></p>', '169.254'],
            'markdown remote image'       => ['![x](https://evil.example/pixel.gif)', 'evil.example'],
            'data: html link'             => ['<p><a href="data:text/html;base64,PHNjcmlwdD4=">x</a></p>', 'data:'],
            'vbscript link'               => ['<p><a href="vbscript:msgbox(1)">x</a></p>', 'vbscript'],
            'relative / protocol link'    => ['<p><a href="//evil.example/x">x</a></p>', 'evil.example'],
            'svg with script'             => ['<svg onload="alert(1)"><script>alert(1)</script></svg>', 'alert'],
            'math / mxss'                 => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>', 'javascript'],
            'iframe'                      => ['<iframe src="https://evil.example"></iframe>', '<iframe'], // the parser itself shows this one as harmless text
            'form + input'                => ['<form action="https://evil.example"><input name="pw"></form>', 'evil.example'],
            'base tag'                    => ['<base href="https://evil.example/">', 'evil.example'],
            'style tag'                   => ['<style>body{background:url(https://evil.example/x)}</style>', '<style'], // likewise
            'meta refresh'                => ['<meta http-equiv="refresh" content="0;url=https://evil.example">', 'evil.example'],
            'css url via escape'          => ['<p style="background:\\75rl(https://evil.example/x.png)">x</p>', 'evil.example'],
            'css expression'              => ['<p style="width:expression(alert(1))">x</p>', 'expression'],
            'full-screen overlay'         => ['<p style="position:fixed;top:0;left:0;width:100%;height:100%">x</p>', 'position'],
            'processing instruction'      => ['<p>a</p><?php system("id") ?>', 'system('],
            'class hook into app styles'  => ['<div class="fixed inset-0 z-50">x</div>', 'inset-0'],
        ];
    }

    private function render(string $md, string $mode = 'text'): string
    {
        $v = new WordingVersion(['content_json' => ['part_b' => $md], 'rates_json' => AgreementPricing::DEFAULT_RATES]);

        return implode("\n", app(AgreementRenderer::class)->blocks($v, 'part_b', $mode));
    }

    public function test_hostile_markup_never_reaches_any_rendering_mode(): void
    {
        foreach ($this->payloads() as $name => [$md, $needle]) {
            foreach (['text', 'edit', 'pdf', 'canon'] as $mode) {
                $html = $this->render($md, $mode);
                $this->assertStringNotContainsStringIgnoringCase($needle, $html, "[$name / $mode] survived: " . $html);
                $this->assertDoesNotMatchRegularExpression('/<\s*(script|iframe|object|embed|svg|math|form|base|link|meta|style|img)\b/i', $html, "[$name / $mode] " . $html);
                $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, "[$name / $mode] " . $html);
            }
        }
    }

    public function test_the_save_time_check_names_every_payload_and_the_old_blacklist_gaps_are_closed(): void
    {
        foreach ($this->payloads() as $name => [$md]) {
            $errors = AgreementTokens::validate($md, AgreementPricing::DEFAULT_RATES, 'Part B');
            $this->assertNotSame([], $errors, "[$name] was accepted at save time");
        }
        // The six payloads of the audit that passed the old regex.
        foreach (['<p><img/src=x/onerror=alert(1)></p>', '<table><tr><td/onmouseover=alert(1)>x</td></tr></table>', '<p><a href="&#x6A;avascript:alert(1)">c</a></p>'] as $md) {
            $this->assertStringContainsString('Part B', implode(' ', AgreementTokens::validate($md, AgreementPricing::DEFAULT_RATES, 'Part B')));
        }
    }

    public function test_ordinary_formatting_is_still_accepted_and_survives_unchanged(): void
    {
        $md = "**Bold** and *italic* and [a link](https://corexos.co.za/terms) and <a@b.co>.\n\n- one\n- two\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n> quoted\n\nR1 495 per month<sup>1</sup>";
        $this->assertSame([], AgreementTokens::validate($md, AgreementPricing::DEFAULT_RATES, 'Part B'));
        $html = $this->render($md);
        foreach (['<strong>Bold</strong>', '<em>italic</em>', 'href="https://corexos.co.za/terms"', 'href="mailto:a@b.co"', '<li>one</li>', '<table>', '<blockquote>', '<sup>1</sup>'] as $part) {
            $this->assertStringContainsString($part, $html);
        }
        $this->assertStringContainsString('rel="noopener noreferrer"', $html, 'links never leak the opener');
    }

    public function test_the_shipped_version_one_needs_no_cleaning_at_all(): void
    {
        foreach ((new AgreementContent)->buildV1() as $part => $md) {
            foreach (AgreementBlocks::rawHtmlBlocks($md) as $i => $raw) {
                SafeHtml::cleanWording($raw, $removed);
                $this->assertSame([], $removed, "$part block $i lost: " . json_encode($removed));
            }
            $this->assertSame([], AgreementTokens::markupProblems($md), $part);
        }
        // and the tables / colgroups / classes the wording relies on are intact
        $partA = $this->renderFull('part_a');
        $this->assertStringContainsString('<colgroup>', $partA);
        $this->assertStringContainsString('<col style="width: 34%">', $partA);
        $this->assertStringContainsString('<col style="width: 65%">', $partA);
        $this->assertStringContainsString('<tr class="odd">', $partA);
    }

    private function renderFull(string $part): string
    {
        $v = new WordingVersion(['content_json' => (new AgreementContent)->buildV1(), 'rates_json' => AgreementPricing::DEFAULT_RATES]);

        return implode("\n", app(AgreementRenderer::class)->blocks($v, $part, 'text'));
    }

    public function test_the_renderers_own_form_controls_are_not_stripped_by_the_sanitiser(): void
    {
        // The sanitiser runs on the wording BEFORE field markers become inputs, so the form still works.
        $v = new WordingVersion(['content_json' => (new AgreementContent)->buildV1(), 'rates_json' => AgreementPricing::DEFAULT_RATES]);
        $html = implode("\n", app(AgreementRenderer::class)->blocks($v, 'part_a', 'form', ['values' => [], 'rr' => [], 'calc' => AgreementPricing::compute('', 0, 0, 0.0, AgreementPricing::DEFAULT_RATES), 'ref' => 'CX000001']));
        $this->assertStringContainsString('<input', $html);
        $this->assertStringContainsString('data-field="registered_name"', $html);
        $this->assertStringContainsString('class="sigpad', $html);
    }

    // ── SafeHtml (email signature) — E4 / company audit F1 + F2 ─────────────

    public function test_css_escapes_cannot_hide_a_url_and_layout_breaking_style_is_dropped(): void
    {
        foreach (['\\75rl(http://evil.test/t.gif)', 'u\\72l(http://evil.test/t.gif)', '\\000075rl(http://evil.test/t.gif)', 'u/**/rl(http://evil.test/t.gif)', 'expre\\73sion(alert(1))'] as $v) {
            $out = SafeHtml::clean('<p style="background-color:' . $v . ';color:#333">x</p>');
            $this->assertStringNotContainsString('evil.test', $out, $v);
            $this->assertStringNotContainsString('expression', $out, $v);
            $this->assertStringContainsString('color: #333', $out, 'the harmless declaration is kept: ' . $v);
        }
        $out = SafeHtml::clean('<div style="position:fixed;top:0;left:0;width:100%;height:100%;z-index:9999;float:left;color:red">x</div>');
        foreach (['position', 'fixed', 'z-index', 'float', 'top:', 'left:'] as $bad) {
            $this->assertStringNotContainsString($bad, $out, $bad);
        }
        $this->assertStringContainsString('color: red', $out);
        $this->assertSame('<p>x</p>', SafeHtml::clean('<p style="position:absolute">x</p>'));
    }

    public function test_remote_images_are_stripped_but_the_corex_logo_and_small_inline_images_stay(): void
    {
        $this->assertStringNotContainsString('<img', SafeHtml::clean('<img src="https://a.example/b.png">'));
        $this->assertStringNotContainsString('<img', SafeHtml::clean('<img src="//evil.test/x.png">'));
        $this->assertStringNotContainsString('<img', SafeHtml::clean('<img src="http://evil.test/platform-company/logo?l=1">'), 'the logo path on someone else\'s host is not our logo');
        $this->assertStringContainsString('<img src="/platform-company/logo?l=3"', SafeHtml::clean('<img src="/platform-company/logo?l=3" alt="x">'));
        $host = parse_url(url('/'), PHP_URL_HOST);
        $this->assertStringContainsString('/platform-company/logo?l=3', SafeHtml::clean('<img src="http://' . $host . '/platform-company/logo?l=3">'));
        $png = 'data:image/png;base64,' . base64_encode(str_repeat('a', 300));
        $this->assertStringContainsString($png, SafeHtml::clean('<img src="' . $png . '">'));
        $this->assertStringNotContainsString('<img', SafeHtml::clean('<img src="data:image/png;base64,' . str_repeat('A', 450000) . '">'), 'an oversized inline image is refused');
        $this->assertStringNotContainsString('<img', SafeHtml::clean('<img src="data:image/svg+xml;base64,PHN2Zz4=">'));
    }

    public function test_processing_instructions_comments_and_event_handlers_are_dropped(): void
    {
        $out = SafeHtml::clean('<p onclick="x()">a<?php echo 1 ?></p><!-- c --><a href="java&#x09;script:alert(1)">b</a><a href="https://corexos.co.za">ok</a>');
        $this->assertStringNotContainsString('<?', $out);
        $this->assertStringNotContainsString('<!--', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('script:', $out);
        $this->assertStringContainsString('href="https://corexos.co.za"', $out);
    }

    // ── E9 link-reference definitions ───────────────────────────────────────

    public function test_split_keeps_link_reference_definitions(): void
    {
        $md = "See [the policy][pol] for more.\n\n[pol]: https://corexos.co.za/privacy \"Privacy\"\n\nSecond paragraph.\n\n[a]: https://a.example\n[b]: https://b.example";
        $blocks = AgreementBlocks::split($md);
        $this->assertSame(['See [the policy][pol] for more.', '[pol]: https://corexos.co.za/privacy "Privacy"', 'Second paragraph.', "[a]: https://a.example\n[b]: https://b.example"], $blocks);
        $this->assertSame($md, AgreementBlocks::join($blocks), 'split then join loses nothing');
        $this->assertStringContainsString('href="https://corexos.co.za/privacy"', $this->render($md), 'and the reference still resolves when the part is rendered whole');
    }

    // ── E3 rates vs contract prose ──────────────────────────────────────────

    public function test_wording_and_rates_that_agree_pass_and_the_shipped_version_agrees_with_its_own_rates(): void
    {
        foreach ((new AgreementContent)->buildV1() as $part => $md) {
            $this->assertSame([], AgreementConsistency::check($md, AgreementPricing::DEFAULT_RATES, AgreementPricing::DEFAULT_RATES, $part), $part);
        }
    }

    public function test_changing_a_breakpoint_or_price_without_the_words_is_refused_in_plain_language(): void
    {
        $partA = (new AgreementContent)->buildV1()['part_a'];
        $rates = array_merge(AgreementPricing::DEFAULT_RATES, ['agency_t1_max' => 15, 'agency_t1' => 320]);
        $msg = implode(' | ', AgreementConsistency::check($partA, $rates, AgreementPricing::DEFAULT_RATES, 'Part A'));
        $this->assertStringContainsString('seats 1 to 10', $msg);
        $this->assertStringContainsString('first tier ending at seat 15', $msg);
        $this->assertStringContainsString('worked example', $msg);
        $this->assertStringContainsString('R295', $msg, 'the left-over old price is named');

        $quote = array_merge(AgreementPricing::DEFAULT_RATES, ['quote_above_agents' => 60]);
        $this->assertStringContainsString('more than 40 agents', implode(' ', AgreementConsistency::check($partA, $quote, AgreementPricing::DEFAULT_RATES, 'Part A')));

        $team = array_merge(AgreementPricing::DEFAULT_RATES, ['team_max_seats' => 8]);
        $this->assertStringContainsString('up to 10 seats', implode(' ', AgreementConsistency::check($partA, $team, AgreementPricing::DEFAULT_RATES, 'Part A')));
    }

    public function test_updating_the_words_to_match_the_new_rates_clears_the_problem(): void
    {
        $partA = (new AgreementContent)->buildV1()['part_a'];
        $rates = array_merge(AgreementPricing::DEFAULT_RATES, ['agency_t1_max' => 15, 'agency_t1' => 320]);
        $fixed = str_replace(
            ['seats 1 to 10', 'seats 11 to 20', 'Seats 11 to 20', '25 agents on the Agency plan are 10 × R295 + 10 × R250 + 5 × R195.'],
            ['seats 1 to 15', 'seats 16 to 20', 'Seats 16 to 20', '25 agents on the Agency plan are 15 × R320 + 5 × R250 + 5 × R195.'],
            $partA
        );
        $this->assertSame([], AgreementConsistency::check($fixed, $rates, AgreementPricing::DEFAULT_RATES, 'Part A'));
        // an unchanged price elsewhere in the contract (the R1250 admin fee) is never mistaken for a stale rate
        $this->assertSame([], AgreementConsistency::check('A returned debit order attracts a fee of R1250.', $rates, AgreementPricing::DEFAULT_RATES, 'Part B'));
    }

    // ── E6 version numbers, E8 rates ────────────────────────────────────────

    public function test_version_numbers_have_one_spelling(): void
    {
        $this->assertSame('1.0', AgreementVersions::canonicalVersion('1.0'));
        $this->assertSame('1.0', AgreementVersions::canonicalVersion('1.00'));
        $this->assertSame('1.0', AgreementVersions::canonicalVersion('01.0'));
        $this->assertSame('1.0', AgreementVersions::canonicalVersion('1.0.0'));
        $this->assertSame('1.1.2', AgreementVersions::canonicalVersion('1.1.2'));
        $this->assertNull(AgreementVersions::canonicalVersion('one'));
        $this->assertNull(AgreementVersions::canonicalVersion('1'));
        $this->assertNull(AgreementVersions::canonicalVersion('1.2.3.4'));
    }

    private function rateInput(array $override = []): array
    {
        return array_map(fn ($x) => (string) $x, array_merge(AgreementPricing::DEFAULT_RATES, $override));
    }

    public function test_rates_reject_zero_comma_ambiguity_and_nonsense_with_clear_messages(): void
    {
        $svc = app(AgreementVersions::class);
        $this->assertSame(1495, $svc->cleanRates($this->rateInput(['agency_base' => '1 495']))['agency_base'], 'thousands written with a space are fine');
        $this->assertEquals(295.5, $svc->cleanRates($this->rateInput(['agency_t1' => '295.50']))['agency_t1']);
        $try = function (array $o) use ($svc): string {
            try {
                $svc->cleanRates($this->rateInput($o));
            } catch (WordingInvalid $e) {
                return implode(' ', $e->errors);
            }

            return '';
        };
        $this->assertStringContainsString('more than zero', $try(['agency_base' => '0']));
        $this->assertStringContainsString('more than zero', $try(['team_seat' => '0.00']));
        $this->assertStringContainsString('more than zero', $try(['quote_above_agents' => '0']));
        $this->assertStringContainsString('do not use a comma', $try(['branch' => '1,49']), 'never guess whether 1,49 is R1.49 or R149');
        $this->assertStringContainsString('do not use a comma', $try(['branch' => '1,495']));
        $this->assertStringContainsString('must be an amount', $try(['branch' => 'lots']));
        $this->assertStringContainsString('must be an amount', $try(['branch' => '-5']));
        $this->assertSame('', $try([]), 'the shipped rates are valid');
    }
}
