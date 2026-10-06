<?php

namespace App\Services\PlatformEsign\Agreement;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Splits a part's Markdown into its clauses (top-level blocks) and joins them back, for the clause-level editor
 * (spec §11.13). Splitting uses the parser's own line positions, so what the editor calls a clause is exactly what the
 * renderer and the paginator treat as one block. split → join is verified lossless against v1.0 in the tests.
 */
class AgreementBlocks
{
    /** @return string[] the Markdown source of each top-level block, in order */
    public static function split(string $md): array
    {
        $md = str_replace("\r\n", "\n", $md);
        $env = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => false]);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new GithubFlavoredMarkdownExtension());
        $doc = (new MarkdownParser($env))->parse($md);
        $lines = explode("\n", $md);
        $out = [];
        foreach ($doc->children() as $child) {
            $s = $child->getStartLine();
            $e = $child->getEndLine();
            if ($s === null || $e === null) {
                continue;
            }
            $out[] = rtrim(implode("\n", array_slice($lines, $s - 1, $e - $s + 1)));
        }

        return $out;
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
