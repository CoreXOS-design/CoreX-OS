<?php

namespace App\Services\Docuperfect;

/**
 * esign-import-blanks.md - fill-in blanks INSIDE a Word table.
 *
 * The importer stores a table as plain cell text (`rows`) plus, where the cells carry richer detail, `cell_paras`
 * (paragraph -> runs). Markers, dotted leaders and underscore runs written in a cell were printed as text, and a
 * label | empty-cell form ("Rental amount: | ") had no field at all - so an all-tables document (a typical agency
 * lease) imported with nothing to fill in. This turns those into field runs - `['field' => [...]]` inside a paragraph's
 * runs - which the renderer draws exactly like a field in a paragraph (a `.corex-field` span) and the builder then treats
 * as any other input.
 *
 * Document order matters: the builder numbers the fields in the order they appear on the page, and the server lists them in
 * the same order, so {@see walk()} and {@see each()} go header cells first, then body rows, cell by cell, paragraph by
 * paragraph, run by run - the order CdsRendererService::renderTable draws them.
 */
final class CdsTableFields
{
    /** @marker, an underscore run, a dotted leader or an ellipsis run written as text. */
    private const SPLIT = '/(@{4,}|_{3,}|\.{5,}|\x{2026}{2,})/u';

    /** A label that belongs to a signing or initialling line: those are signature places, never fill-in fields. */
    private const SIGNING = '/\b(signature|signed|witness|initial|initials)\b/i';

    /**
     * Find the blanks of a table and turn them into field runs.
     *
     * @param array<string,mixed> $section a parsed table section
     * @return array<string,mixed> the section, changed only when it holds at least one blank
     */
    public static function detect(array $section): array
    {
        $grid = self::grid($section);
        if ($grid === []) {
            return $section;
        }

        $changed = false;
        // The importer treats a first row of short cells as a heading even when it is really the first "label | blank" line of a form,
        // so every row counts here.
        $labelled = count(array_filter($grid, fn ($r) => trim((string) ($r['plain'][0] ?? '')) !== ''));
        $formLike = $labelled * 2 >= count($grid);
        $headerPlain = null;
        foreach ($grid as $r) {
            if ($r['kind'] === 'head') {
                $headerPlain = $r['plain'];
            }
        }

        foreach ($grid as $ri => &$row) {
            $rowAllBlank = true;
            foreach ($row['plain'] as $text) {
                if (trim((string) $text) !== '') {
                    $rowAllBlank = false;
                }
            }

            foreach ($row['paras'] as $ci => &$paras) {
                $cellText = trim((string) ($row['plain'][$ci] ?? ''));
                $meta = $row['meta'][$ci] ?? [];

                // 1) Markers / underscore runs / dotted leaders typed into the cell's text.
                if ($cellText !== '' && ! preg_match(self::SIGNING, $cellText)) {
                    foreach ($paras as $pi => &$para) {
                        $runs = [];
                        foreach ((array) ($para['runs'] ?? []) as $run) {
                            $t = (string) ($run['t'] ?? '');
                            if (isset($run['img']) || $t === '' || ! preg_match(self::SPLIT, $t)) {
                                $runs[] = $run;
                                continue;
                            }
                            foreach (preg_split(self::SPLIT, $t, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
                                if ($part === '') {
                                    continue;
                                }
                                if (preg_match('/^(@{4,}|_{3,}|\.{5,}|\x{2026}{2,})$/u', $part)) {
                                    $runs[] = ['field' => self::blank($part)];
                                    $changed = true;
                                } else {
                                    $runs[] = array_merge($run, ['t' => $part]);
                                }
                            }
                        }
                        $para['runs'] = $runs;
                    }
                    unset($para);
                    continue;
                }

                // 2) An empty cell next to its label ("Rental amount:" | ) or under its column heading.
                if ($cellText === '' && empty($meta['vcont']) && empty($meta['rowspan']) && $formLike
                    && ! self::hasPicture($paras)) {
                    $label = $ci > 0 ? trim((string) ($row['plain'][$ci - 1] ?? '')) : '';
                    $hint = null;
                    if (self::looksLikeLabel($label)) {
                        $hint = $label;
                    } elseif ($row['kind'] === 'body' && $rowAllBlank && $headerPlain !== null && self::looksLikeLabel(trim((string) ($headerPlain[$ci] ?? ''))) && mb_strlen(trim((string) $headerPlain[$ci])) <= 30 && count(array_filter($headerPlain, fn ($h) => trim((string) $h) !== '')) >= 2) {
                        $hint = trim((string) $headerPlain[$ci]);
                    }
                    if ($hint !== null) {
                        $paras = [['runs' => [['field' => self::blank('', $hint)]]]];
                        $changed = true;
                    }
                }
            }
            unset($paras);
        }
        unset($row);

        return $changed ? self::store($section, $grid) : $section;
    }

    /**
     * Every field run of a table, in the order the renderer draws them.
     *
     * @param array<string,mixed> $section
     * @return list<array<string,mixed>> the field arrays (label, field_name, field_type, ...)
     */
    public static function walk(array $section): array
    {
        $out = [];
        foreach (self::grid($section, false) as $row) {
            foreach ($row['paras'] as $paras) {
                foreach ($paras as $para) {
                    foreach ((array) ($para['runs'] ?? []) as $run) {
                        if (isset($run['field'])) {
                            $out[] = $run['field'];
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Visit every field run of a table in document order and let the caller rewrite it.
     *
     * $fn(array $field, string $before, string $after, string $clause, string $above, ?string $previousKey): array|null
     * receives the words before / after the blank in its paragraph (the label cell's text when the blank stands alone), the
     * whole paragraph, the row above, and returns the field's new attributes (null = leave it).
     *
     * @param array<string,mixed> $section
     * @return array<string,mixed>
     */
    public static function each(array $section, callable $fn): array
    {
        if (self::walk($section) === []) {
            return $section;   // no blanks: the table is left exactly as it was stored
        }
        $grid = self::grid($section, false);
        $above = '';
        foreach ($grid as &$row) {
            foreach ($row['paras'] as $ci => &$paras) {
                foreach ($paras as &$para) {
                    $runs = (array) ($para['runs'] ?? []);
                    $previousKey = null;
                    foreach ($runs as $i => $run) {
                        if (! isset($run['field'])) {
                            continue;
                        }
                        $before = '';
                        for ($j = $i - 1; $j >= 0; $j--) {
                            if (isset($runs[$j]['t'])) {
                                $before = (string) $runs[$j]['t'] . $before;
                            }
                        }
                        $after = '';
                        for ($j = $i + 1; $j < count($runs); $j++) {
                            if (isset($runs[$j]['t'])) {
                                $after .= (string) $runs[$j]['t'];
                            }
                        }
                        if (trim($before) === '' && ! empty($run['field']['hint'])) {
                            $before = (string) $run['field']['hint'];
                        } elseif (mb_strlen(trim($before)) <= 3 && $ci > 0 && trim((string) ($row['plain'][$ci - 1] ?? '')) !== '') {
                            // "Monthly rental: | R @@@@" - the words before the blank are just the currency sign; the label is the cell to the left.
                            $before = trim((string) $row['plain'][$ci - 1]) . ' ' . trim($before);
                        }
                        $clause = implode('', array_map(fn ($r) => (string) ($r['t'] ?? ''), $runs));
                        $new = $fn($run['field'], $before, $after, $clause, $above, $previousKey);
                        if ($new !== null) {
                            $previousKey = isset($new['_key']) ? (string) $new['_key'] : null;
                            unset($new['_key']);
                            $runs[$i]['field'] = array_merge($run['field'], $new);
                        } else {
                            $previousKey = null;
                        }
                    }
                    $para['runs'] = $runs;
                }
                unset($para);
            }
            unset($paras);
            $above = trim(implode(' ', array_map('strval', $row['plain'])));
        }
        unset($row);

        return self::store($section, $grid);
    }

    // ───────────────────────────── internals ─────────────────────────────

    /** @return array<string,mixed> a new, unlabelled blank */
    private static function blank(string $marker, ?string $hint = null): array
    {
        $f = ['label' => 'Input', 'field_name' => '', 'field_type' => 'text', 'source' => 'manual', 'confidence' => 'low', 'length' => mb_strlen($marker)];
        if ($hint !== null) {
            $f['hint'] = $hint;
        }

        return $f;
    }

    /**
     * A cell that names what the next cell is for: a short noun phrase or something ending in a colon. A tick (X, Y, N, Yes, No), a
     * clause number, a signing label and a whole sentence ("I am aware of defects ...") are not labels, so the empty cell beside
     * them (an answer box to tick) is not a blank to fill.
     */
    private static function looksLikeLabel(string $text): bool
    {
        $text = trim($text);
        if ($text === '' || preg_match(self::SIGNING, $text) || preg_match('/^\d+[.)]?$/', $text)) {
            return false;
        }
        if (preg_match_all('/\pL/u', $text) < 3 || preg_match('/^(yes|no|n\/?a|x|y|n|tick|true|false)$/i', $text)) {
            return false;
        }

        return str_ends_with($text, ':') ? mb_strlen($text) <= 70 : mb_strlen($text) <= 40;
    }

    private static function hasPicture(array $paras): bool
    {
        foreach ($paras as $para) {
            foreach ((array) ($para['runs'] ?? []) as $run) {
                if (isset($run['img'])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The table as rows in drawing order: kind (head|body), the plain cell texts, the cell meta and the paragraphs of every
     * cell (taken from `cell_paras` / `header_paras`, or built from the plain text of a table that stored none).
     *
     * @return list<array{kind:string,plain:list<string>,meta:array<int,array<string,mixed>>,paras:array<int,list<array<string,mixed>>>}>
     */
    private static function grid(array $section, bool $synthesise = true): array
    {
        $grid = [];
        if (! empty($section['headers'])) {
            $grid[] = [
                'kind' => 'head', 'plain' => array_map('strval', (array) $section['headers']), 'meta' => (array) ($section['header_meta'] ?? []),
                'paras' => self::parasFor((array) $section['headers'], (array) ($section['header_paras'] ?? []), $synthesise),
            ];
        }
        foreach ((array) ($section['rows'] ?? []) as $i => $cells) {
            $grid[] = [
                'kind' => 'body', 'plain' => array_map('strval', (array) $cells), 'meta' => (array) ($section['cell_meta'][$i] ?? []),
                'paras' => self::parasFor((array) $cells, (array) ($section['cell_paras'][$i] ?? []), $synthesise),
            ];
        }

        return $grid;
    }

    /** @return array<int,list<array<string,mixed>>> */
    private static function parasFor(array $cells, array $stored, bool $synthesise): array
    {
        $out = [];
        foreach (array_values($cells) as $ci => $text) {
            if (! empty($stored[$ci])) {
                $out[$ci] = array_values($stored[$ci]);
            } elseif ($synthesise || $text !== '') {
                $lines = $text === '' ? [''] : preg_split('/\R/u', (string) $text);
                $out[$ci] = array_map(fn ($line) => ['runs' => $line === '' ? [] : [['t' => $line]]], $lines);
            } else {
                $out[$ci] = [];
            }
        }

        return $out;
    }

    /** Write the grid back as `header_paras` / `cell_paras` (every cell, so the renderer's paragraph path covers the whole table). */
    private static function store(array $section, array $grid): array
    {
        $rowParas = [];
        foreach ($grid as $row) {
            if ($row['kind'] === 'head') {
                $section['header_paras'] = array_values($row['paras']);
            } else {
                $rowParas[] = array_values($row['paras']);
            }
        }
        $section['cell_paras'] = $rowParas;

        return $section;
    }
}
