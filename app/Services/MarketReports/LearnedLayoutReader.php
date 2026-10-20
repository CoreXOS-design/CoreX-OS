<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

/**
 * Deterministic reader for a learned layout: applies a saved field mapping to a PDF's
 * text (`pdftotext -layout`) and returns the canonical read (the same shape the AI
 * returns, see CanonicalReadMapper). No AI, no cost. Spec: mic-complete-spec §8.9.
 *
 * Mapping shape (built by LayoutLearner):
 *   tables[] : {type:'comps', kind:'comp'|'listing', cells:int, columns:[{i,field,format?}]}
 *              {type:'stats', cells:int, year_col:int, metrics:[{i,metric}]}
 *   scalars[]: {field:'valuation.lower'|'suburb'|…, label:string, type:'money'|'text'}
 *
 * A table row is a line that splits (on runs of 2+ spaces) into exactly `cells` cells
 * AND whose price/date/year cells parse. Anything else — headers, footers, wrapped
 * rows — is skipped, never guessed.
 */
final class LearnedLayoutReader
{
    public const MONEY_FIELDS = ['sale_price', 'list_price', 'municipal_valuation'];
    public const INT_FIELDS   = ['extent_m2', 'r_per_m2', 'distance_to_subject_m', 'days_on_market'];
    public const TEXT_FIELDS  = ['address', 'scheme_name', 'section_number', 'property_type', 'suburb'];

    private const DATE_FORMATS = ['Y-m-d', 'Y/m/d', 'd/m/Y', 'd-m-Y', 'd.m.Y', 'j M Y', 'd M Y', 'j F Y', 'd F Y', 'M j, Y', 'F j, Y', 'j-M-Y', 'd-M-Y'];

    /**
     * @param  array<string, mixed> $mapping
     * @return array<string, mixed> canonical read (unsanitised)
     */
    public function read(array $mapping, string $text): array
    {
        $lines = self::lines($text);
        $out = ['comps' => [], 'suburb_stats' => [], 'valuation' => [], 'subject' => []];

        foreach ((array) ($mapping['tables'] ?? []) as $table) {
            if (($table['type'] ?? null) === 'comps') {
                foreach ($this->readComps($lines, $table) as $row) {
                    $out['comps'][] = $row;
                }
            } elseif (($table['type'] ?? null) === 'stats') {
                foreach ($this->readStats($lines, $table) as $row) {
                    $out['suburb_stats'][] = $row;
                }
            }
        }

        foreach ((array) ($mapping['scalars'] ?? []) as $scalar) {
            $value = $this->readScalar($lines, (string) ($scalar['label'] ?? ''), (string) ($scalar['type'] ?? 'text'));
            if ($value === null) {
                continue;
            }
            $field = (string) ($scalar['field'] ?? '');
            if (str_starts_with($field, 'valuation.')) {
                $out['valuation'][substr($field, 10)] = $value;
            } elseif (str_starts_with($field, 'subject.')) {
                $out['subject'][substr($field, 8)] = $value;
            } elseif (in_array($field, ['suburb', 'town'], true)) {
                $out[$field] = $value;
            }
        }

        return $out;
    }

    /** @return array<int, string> */
    public static function lines(string $text): array
    {
        return preg_split('/\r?\n|\f/', $text) ?: [];
    }

    /** @return array<int, string> */
    public static function cells(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [];
        }

        return preg_split('/\s{2,}/u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    public static function parseMoney(string $s): ?int
    {
        $s = trim(str_replace("\u{00A0}", ' ', $s));
        if (!preg_match('/^R?\s*(\d[\d\s,]*)(?:\.(\d{1,2}))?$/iu', $s, $m)) {
            return null;
        }
        $digits = preg_replace('/[\s,]+/', '', $m[1]);

        return $digits === '' ? null : (int) $digits;
    }

    public static function parseSize(string $s): ?int
    {
        $s = trim(str_replace("\u{00A0}", ' ', $s));
        if (!preg_match('/^R?\s*(\d[\d\s,]*)(?:[.,]\d+)?\s*(?:m²|m2|sqm|m)?$/iu', $s, $m)) {
            return null;
        }
        $digits = preg_replace('/[\s,]+/', '', $m[1]);

        return $digits === '' ? null : (int) $digits;
    }

    public static function parseFloat(string $s): ?float
    {
        $s = trim(str_replace(["\u{00A0}", '%'], [' ', ''], $s));
        if (!preg_match('/^-?\d+(?:[.,]\d+)?$/', $s)) {
            return null;
        }

        return (float) str_replace(',', '.', $s);
    }

    public static function parseDate(string $s, ?string $format = null): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        foreach ($format !== null ? [$format] : self::DATE_FORMATS as $f) {
            $d = \DateTime::createFromFormat('!' . $f, $s);
            $errors = \DateTime::getLastErrors();
            if ($d !== false && (!$errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    /** Which date format reads this cell as exactly $expected (Y-m-d)? */
    public static function dateFormatFor(string $cell, string $expected): ?string
    {
        foreach (self::DATE_FORMATS as $f) {
            if (self::parseDate($cell, $f) === $expected) {
                return $f;
            }
        }

        return null;
    }

    public static function parseCell(string $field, string $cell, ?string $format = null): mixed
    {
        if (in_array($field, self::MONEY_FIELDS, true)) {
            return self::parseMoney($cell);
        }
        if (in_array($field, self::INT_FIELDS, true)) {
            return self::parseSize($cell);
        }
        if ($field === 'sale_date') {
            return self::parseDate($cell, $format);
        }
        $t = trim($cell);

        return $t === '' ? null : $t;
    }

    /**
     * @param  array<int, string> $lines
     * @param  array<string, mixed> $table
     * @return array<int, array<string, mixed>>
     */
    private function readComps(array $lines, array $table): array
    {
        $cells = (int) ($table['cells'] ?? 0);
        $kind  = ($table['kind'] ?? 'comp') === 'listing' ? 'listing' : 'comp';
        $priceField = $kind === 'comp' ? 'sale_price' : 'list_price';
        $rows = [];

        foreach ($lines as $line) {
            $c = self::cells($line);
            if ($cells === 0 || count($c) !== $cells) {
                continue;
            }
            $row = ['kind' => $kind];
            $ok = true;
            foreach ((array) $table['columns'] as $col) {
                $field = (string) $col['field'];
                $value = self::parseCell($field, $c[(int) $col['i']] ?? '', $col['format'] ?? null);
                if ($value === null && ($field === $priceField || $field === 'sale_date')) {
                    $ok = false; // a header, a footer or a wrapped row — not a data row
                    break;
                }
                if ($value !== null) {
                    $row[$field] = $value;
                }
            }
            if ($ok && isset($row[$priceField])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, string> $lines
     * @param  array<string, mixed> $table
     * @return array<int, array<string, mixed>>
     */
    private function readStats(array $lines, array $table): array
    {
        $cells = (int) ($table['cells'] ?? 0);
        $yearCol = (int) ($table['year_col'] ?? 0);
        $rows = [];

        foreach ($lines as $line) {
            $c = self::cells($line);
            if ($cells === 0 || count($c) !== $cells) {
                continue;
            }
            if (!preg_match('/^(?:19|20)\d{2}$/', $c[$yearCol] ?? '')) {
                continue;
            }
            foreach ((array) $table['metrics'] as $m) {
                $metric = (string) $m['metric'];
                $raw = $c[(int) $m['i']] ?? '';
                $value = in_array($metric, ['median_price', 'average_price', 'median_price_12m'], true)
                    ? self::parseMoney($raw)
                    : self::parseFloat($raw);
                if ($value !== null) {
                    $rows[] = ['metric' => $metric, 'year' => (int) $c[$yearCol], 'value' => $value];
                }
            }
        }

        return $rows;
    }

    /** @param array<int, string> $lines */
    private function readScalar(array $lines, string $label, string $type): int|string|null
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        $label = trim((string) preg_replace('/\s+/u', ' ', $label));
        $pattern = '/(?<![\p{L}\d])' . str_replace(' ', '\s+', preg_quote($label, '/')) . '/iu';

        foreach ($lines as $line) {
            if (!preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $after = substr($line, $m[0][1] + strlen($m[0][0]));
            $after = ltrim($after, " \t:-");
            if ($type === 'money') {
                if (preg_match('/^R?\s*(\d[\d ,]*)(?:\.\d{1,2})?(?=\s|$)/u', $after, $mm)) {
                    $v = self::parseMoney($mm[0]);
                    if ($v !== null) {
                        return $v;
                    }
                }

                continue;
            }
            $first = self::cells($after)[0] ?? '';
            $first = trim($first);
            if ($first !== '') {
                return mb_substr($first, 0, 160);
            }
        }

        return null;
    }
}
