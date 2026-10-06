<?php

namespace App\Services\PlatformEsign\Agreement;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Splits a part's Markdown into its clauses (top-level blocks) and joins them back, for the clause-level editor
 * (spec §11.14). Splitting uses the parser's own line positions, so what the editor calls a clause is exactly what the
 * renderer and the paginator treat as one block. split → join is verified lossless against v1.0 in the tests.
 */
class AgreementBlocks
{
    /**
     * The one Markdown configuration for the agreement wording. Raw HTML is allowed IN (the shipped wording uses tables, <sup>, colgroups)
     * but NEVER out unfiltered: every rendered block goes through SafeHtml::cleanWording (AgreementRenderer::markdownBlocks), and
     * AgreementTokens::validate refuses wording that would need anything removed.
     */
    public static function environment(): Environment
    {
        $env = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());

        return $env;
    }

    /** @return string[] the raw (UNSANITISED) HTML of each top-level block — only for validation, never for output */
    public static function rawHtmlBlocks(string $md): array
    {
        $env = self::environment();
        $doc = (new MarkdownParser($env))->parse(str_replace("\r\n", "\n", $md));
        $renderer = new HtmlRenderer($env);
        $out = [];
        foreach ($doc->children() as $child) {
            $out[] = trim((string) $renderer->renderNodes([$child]));
        }

        return $out;
    }

    /**
     * @return string[] the Markdown source of each top-level block, in order. Link-reference definitions ("[ref]: https://…") are not
     *                  blocks of the parser's tree; they are kept as clauses of their own so that opening a section in the editor and
     *                  saving it can never silently lose them.
     */
    public static function split(string $md): array
    {
        $md = str_replace("\r\n", "\n", $md);
        $doc = (new MarkdownParser(self::environment()))->parse($md);
        $lines = explode("\n", $md);
        $segments = [];
        $covered = [];
        foreach ($doc->children() as $child) {
            $s = $child->getStartLine();
            $e = $child->getEndLine();
            if ($s === null || $e === null) {
                continue;
            }
            for ($i = $s; $i <= $e; $i++) {
                $covered[$i] = true;
            }
            $segments[$s] = rtrim(implode("\n", array_slice($lines, $s - 1, $e - $s + 1)));
        }
        // Whatever text sits between the blocks (the parser consumed it as link-reference definitions): one clause per run of lines.
        $run = [];
        $runStart = null;
        foreach ($lines as $idx => $line) {
            $no = $idx + 1;
            if (!isset($covered[$no]) && trim($line) !== '') {
                $runStart ??= $no;
                $run[] = rtrim($line);

                continue;
            }
            if ($run) {
                $segments[$runStart] = implode("\n", $run);
                $run = [];
                $runStart = null;
            }
        }
        if ($run) {
            $segments[$runStart] = implode("\n", $run);
        }
        ksort($segments);

        return array_values($segments);
    }

    /** @param string[] $blocks */
    public static function join(array $blocks): string
    {
        return trim(implode("\n\n", array_map(fn ($b) => trim(str_replace("\r\n", "\n", (string) $b), "\n"), $blocks)));
    }

    /** Clean what the browser sends: strings only, no empty clauses, no pathological sizes. @return string[] */
    public static function sanitise(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $b = trim(str_replace("\r\n", "\n", (string) $b), "\n");
            if (trim($b) !== '') {
                $out[] = mb_substr($b, 0, 20000);
            }
        }

        return array_slice($out, 0, 600);
    }
}
