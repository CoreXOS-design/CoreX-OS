<?php

namespace App\Services\Rentals;

use App\Models\Docuperfect\Document;
use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * .ai/specs/leases.md §15.8.2 (Build L1 — foundation). Reads the values an e-sign lease agreement
 * PRINTS back through the agency's field map, so CoreX can compare the document with the lease
 * record without listening to any edit (§15.8.1: comparing end states catches every edit route,
 * present and future).
 *
 * PURE: no database access, no writes, no events. `read()` takes a Document only to lift its three
 * stores; `readFromParts()` is the same work on plain data, which is what the tests drive.
 *
 * For each mapped key, the first of these that holds a value wins:
 *   1. html         the `data-field` span in the stored canonical_html — the PRINTED truth. A
 *                   struck-and-reworded span reads as its <ins> text, never its <del> text.
 *   2. overlay      web_template_data._fill_review_overlay[field]
 *   3. field_values web_template_data.field_values[field]
 *   4. flat         web_template_data[field]
 *   5. fields_json  the entry whose `field_name` matches (a LIST on a template; a map is accepted too)
 *
 * Result per key: ['printed' => ?string, 'raw' => ?string, 'source' => ?string, 'struck' => bool,
 * 'parsed' => mixed]. `parsed` is typed by the registry (config/lease-agreement-fields.php): money
 * float to the cent, date 'Y-m-d', integer, percent float, month 1–12, text string. A printed value
 * that cannot be parsed keeps `printed` and has `parsed = null` — it is never guessed.
 *
 * The field map's shape (stored in rental_lease_templates.field_map):
 *   { "<registry key>": { "field": "<the template's own field name>", "required": bool, "label": ?string } }
 * and the shorthand { "<registry key>": "<field name>" } is accepted. Keys a map declares that the
 * registry does not know are read as plain text (an agency-specific extra).
 */
class LeaseAgreementValuesReader
{
    private const MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6,
        'july' => 7, 'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    /** Date formats the agreement is allowed to print in. Never a free parse: "01/02/2026" must not become 2 January. */
    private const DATE_FORMATS = ['Y-m-d', 'j F Y', 'j M Y', 'd/m/Y', 'Y/m/d', 'd-m-Y', 'j F, Y'];

    /**
     * @return array<string, array{printed: ?string, raw: ?string, source: ?string, struck: bool, parsed: mixed}>
     */
    public function read(Document $document, array $fieldMap): array
    {
        $data = is_array($document->web_template_data) ? $document->web_template_data : [];
        $html = isset($data['canonical_html']) && is_string($data['canonical_html']) ? $data['canonical_html'] : null;
        $fieldsJson = is_array($document->fields_json) ? $document->fields_json : [];

        return $this->readFromParts($html, $data, $fieldsJson, $fieldMap);
    }

    /**
     * @param  array<string,mixed>  $webTemplateData  the document's web_template_data (overlay, field_values, flat keys)
     * @param  array<int|string,mixed>  $fieldsJson  the document's fields_json (a list of field objects, or a map)
     * @return array<string, array{printed: ?string, raw: ?string, source: ?string, struck: bool, parsed: mixed}>
     */
    public function readFromParts(?string $canonicalHtml, array $webTemplateData, array $fieldsJson, array $fieldMap): array
    {
        $spans = $this->indexSpans($canonicalHtml);
        $out = [];

        foreach ($this->normaliseMap($fieldMap) as $key => $entry) {
            $field = $entry['field'];
            $found = $this->lookup($field, $spans, $webTemplateData, $fieldsJson);

            $printed = $found === null ? null : $this->collapse($found['raw']);
            $type = $this->typeFor($key);

            $out[$key] = [
                'printed' => $printed,
                'raw' => $found['raw'] ?? null,
                'source' => $found['source'] ?? null,
                'struck' => $found['struck'] ?? false,
                'parsed' => $printed === null || $printed === '' ? null : $this->parse($printed, $type),
            ];
        }

        return $out;
    }

    /**
     * Accepts both map shapes and returns key => ['field' => string, 'required' => bool, 'label' => ?string].
     * An entry with no usable field name is dropped (an unmapped key is simply not carried — §15.12.3).
     *
     * @return array<string, array{field: string, required: bool, label: ?string}>
     */
    public function normaliseMap(array $fieldMap): array
    {
        $out = [];
        foreach ($fieldMap as $key => $entry) {
            if (is_string($entry)) {
                $entry = ['field' => $entry];
            }
            if (! is_array($entry)) {
                continue;
            }
            $field = trim((string) ($entry['field'] ?? ''));
            if ($field === '' || ! is_string($key) || $key === '') {
                continue;
            }
            $out[$key] = [
                'field' => $field,
                'required' => (bool) ($entry['required'] ?? false),
                'label' => isset($entry['label']) && trim((string) $entry['label']) !== '' ? trim((string) $entry['label']) : null,
            ];
        }

        return $out;
    }

    // ── Parsing ─────────────────────────────────────────────────────────────────────────

    public function parse(string $printed, string $type): mixed
    {
        return match ($type) {
            'money' => self::parseMoney($printed),
            'date' => self::parseDate($printed),
            'integer' => self::parseInteger($printed),
            'percent' => self::parsePercent($printed),
            'month' => self::parseMonth($printed),
            default => self::collapseText($printed),
        };
    }

    /**
     * Money to the cent. The "R" is typically CSS, so it may be absent; thousands may be a space, an
     * nbsp or a comma; the decimal mark may be "." or ",". Rule: when both marks appear the LAST one is
     * the decimal mark; a lone "," followed by exactly one or two digits is a decimal mark, otherwise
     * (three digits after it) it is a thousands mark. Anything else non-numeric → null.
     */
    public static function parseMoney(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $s = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value) ?? '';
        $s = preg_replace('/^(?:R|ZAR)/i', '', $s) ?? '';
        if ($s === '' || ! preg_match('/^-?[\d.,]+$/', $s)) {
            return null;
        }

        $lastDot = strrpos($s, '.');
        $lastComma = strrpos($s, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $decimalMark = $lastDot > $lastComma ? '.' : ',';
        } elseif ($lastComma !== false) {
            $decimalMark = strlen($s) - $lastComma - 1 <= 2 ? ',' : null;
        } elseif ($lastDot !== false) {
            // "6.940" and "1.234.567" are thousands, "6940.50" is a decimal: money never has three decimals.
            $decimalMark = (substr_count($s, '.') > 1 || strlen($s) - $lastDot - 1 === 3) ? null : '.';
        } else {
            $decimalMark = null;
        }

        if ($decimalMark === null) {
            $normalised = str_replace([',', '.'], '', $s);
        } else {
            $thousands = $decimalMark === '.' ? ',' : '.';
            $normalised = str_replace($decimalMark, '.', str_replace($thousands, '', $s));
        }

        return is_numeric($normalised) ? round((float) $normalised, 2) : null;
    }

    /** 'Y-m-d' or null. Only the formats in DATE_FORMATS; an impossible date (31 February) is null. */
    public static function parseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? '');
        if ($s === '') {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat('!'.$format, $s);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && (! is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    public static function parseInteger(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $s = preg_replace('/[\s\x{00A0}]+/u', '', $value) ?? '';

        return preg_match('/^\d+$/', $s) ? (int) $s : null;
    }

    /** "7.5", "7,5", "7.5 %", "7,5%" → 7.5. */
    public static function parsePercent(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $s = preg_replace('/[\s\x{00A0}%]+/u', '', $value) ?? '';
        $s = str_replace(',', '.', $s);

        return preg_match('/^\d+(\.\d+)?$/', $s) ? round((float) $s, 2) : null;
    }

    /** "October", "oct", "10" → 10; anything else null. */
    public static function parseMonth(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $s = mb_strtolower(trim($value));
        if (preg_match('/^\d{1,2}$/', $s)) {
            $n = (int) $s;

            return $n >= 1 && $n <= 12 ? $n : null;
        }
        foreach (self::MONTHS as $name => $n) {
            if ($s === $name || ($s === substr($name, 0, 3))) {
                return $n;
            }
        }

        return null;
    }

    public static function collapseText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? '');

        return $s;
    }

    // ── Reading ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{raw: string, source: string, struck: bool}|null
     */
    private function lookup(string $field, array $spans, array $data, array $fieldsJson): ?array
    {
        // 1 — the printed span. `x__r1` is the per-recipient instance of `x` that the expansion stamps.
        foreach ([$field, $field.'__r1'] as $name) {
            // An empty span that is merely unfilled falls through to the stores below; a STRUCK span with
            // no replacement is the printed truth ("removed") and must not be refilled from a lower source.
            if (isset($spans[$name]) && ($spans[$name]['text'] !== '' || $spans[$name]['struck'])) {
                return ['raw' => $spans[$name]['text'], 'source' => 'html', 'struck' => $spans[$name]['struck']];
            }
        }

        // 2, 3, 4 — the three web_template_data stores.
        $stores = [
            'overlay' => is_array($data['_fill_review_overlay'] ?? null) ? $data['_fill_review_overlay'] : [],
            'field_values' => is_array($data['field_values'] ?? null) ? $data['field_values'] : [],
            'flat' => $data,
        ];
        foreach ($stores as $source => $store) {
            foreach ([$field, $field.'__r1'] as $name) {
                if (array_key_exists($name, $store) && is_scalar($store[$name]) && trim((string) $store[$name]) !== '') {
                    return ['raw' => (string) $store[$name], 'source' => $source, 'struck' => false];
                }
            }
        }

        // 5 — fields_json: a list of field objects (matched by field_name), or a plain map.
        foreach ($fieldsJson as $key => $entry) {
            if (is_array($entry)) {
                if (($entry['field_name'] ?? null) === $field && isset($entry['value']) && is_scalar($entry['value']) && trim((string) $entry['value']) !== '') {
                    return ['raw' => (string) $entry['value'], 'source' => 'fields_json', 'struck' => false];
                }
            } elseif ($key === $field && is_scalar($entry) && trim((string) $entry) !== '') {
                return ['raw' => (string) $entry, 'source' => 'fields_json', 'struck' => false];
            }
        }

        return null;
    }

    /**
     * Index every `data-field` element of the stored HTML by field name → printed text. The first
     * element of a name with a printed value wins (a rent printed twice reads once). A struck element
     * reads as its <ins> text, never its <del> text; struck with no replacement reads as empty.
     *
     * @return array<string, array{text: string, struck: bool}>
     */
    private function indexSpans(?string $html): array
    {
        if ($html === null || trim($html) === '' || ! str_contains($html, 'data-field')) {
            return [];
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[@data-field] | //*[@data-field-name]');
        if ($nodes === false) {
            return [];
        }

        $spans = [];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $name = $node->getAttribute('data-field') ?: $node->getAttribute('data-field-name');
            if ($name === '') {
                continue;
            }

            [$text, $struck] = $this->printedText($node, $xpath);

            // The first element that carries a value wins; a later one only fills an earlier empty.
            if (! isset($spans[$name]) || ($spans[$name]['text'] === '' && $text !== '')) {
                $spans[$name] = ['text' => $text, 'struck' => $struck];
            }
        }

        return $spans;
    }

    /** @return array{0: string, 1: bool} printed text and whether the element carries a change mark */
    private function printedText(DOMElement $node, DOMXPath $xpath): array
    {
        $ins = $xpath->query('.//ins', $node);
        $del = $xpath->query('.//del | .//*[contains(concat(" ", normalize-space(@class), " "), " change-del ")]', $node);
        $struck = ($ins !== false && $ins->length > 0) || ($del !== false && $del->length > 0);

        if (! $struck) {
            return [$this->collapse($node->textContent), false];
        }

        // Replacement text wins; struck text is dropped.
        if ($ins !== false && $ins->length > 0) {
            $parts = [];
            foreach ($ins as $i) {
                $parts[] = $i->textContent;
            }

            return [$this->collapse(implode(' ', $parts)), true];
        }

        // Struck with no replacement: everything inside the <del> is removed text.
        $clone = $node->cloneNode(true);
        $cloneXpath = new DOMXPath($clone->ownerDocument);
        foreach (iterator_to_array($cloneXpath->query('.//del | .//*[contains(concat(" ", normalize-space(@class), " "), " change-del ")]', $clone)) as $d) {
            $d->parentNode?->removeChild($d);
        }

        return [$this->collapse($clone->textContent), true];
    }

    private function collapse(?string $value): string
    {
        return (string) self::collapseText($value);
    }

    private function typeFor(string $key): string
    {
        $fields = (array) config('lease-agreement-fields.fields', []);
        if (isset($fields[$key]['type'])) {
            return (string) $fields[$key]['type'];
        }
        // A per-party member of an indexed family: tenant_name_2 → tenant_name.
        if (preg_match('/^(.*)_\d+$/', $key, $m) && ! empty($fields[$m[1]]['indexed'])) {
            return (string) ($fields[$m[1]]['type'] ?? 'text');
        }

        return 'text';
    }
}
