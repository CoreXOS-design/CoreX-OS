<?php

namespace App\Services\Platform;

/**
 * Turns the plain text the platform owner types (timeline blocks, contract
 * bodies) into safe HTML. Everything is escaped FIRST, then a tiny, fixed set
 * of markers is applied — so there is no path from typed text to raw HTML/JS.
 *
 *   blank line      → new paragraph
 *   "# Heading"     → <h3>
 *   "## Heading"    → <h4>
 *   "- item" lines  → <ul><li>
 *   **bold**        → <strong>
 */
class PlainDocRenderer
{
    public static function render(?string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));
        if ($text === '') {
            return '';
        }

        $out = [];
        foreach (preg_split("/\n{2,}/", $text) as $block) {
            $lines = explode("\n", trim($block));

            if (count($lines) === count(array_filter($lines, fn ($l) => str_starts_with(ltrim($l), '- ')))) {
                $items = array_map(fn ($l) => '<li>' . self::inline(substr(ltrim($l), 2)) . '</li>', $lines);
                $out[] = '<ul>' . implode('', $items) . '</ul>';
                continue;
            }

            if (preg_match('/^(#{1,2})\s+(.*)$/', $lines[0], $m)) {
                $tag = strlen($m[1]) === 1 ? 'h3' : 'h4';
                $out[] = "<{$tag}>" . self::inline($m[2]) . "</{$tag}>";
                array_shift($lines);
                if (!$lines) {
                    continue;
                }
            }

            $out[] = '<p>' . implode('<br>', array_map([self::class, 'inline'], $lines)) . '</p>';
        }

        return implode("\n", $out);
    }

    private static function inline(string $line): string
    {
        $escaped = e($line);

        return preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
    }
}
