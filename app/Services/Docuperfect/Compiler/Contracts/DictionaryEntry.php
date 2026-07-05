<?php

namespace App\Services\Docuperfect\Compiler\Contracts;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * Immutable, framework-free value object describing ONE typed CoreX data-dictionary
 * entry (§2.1 of `.ai/specs/esign-document-compiler-spec.md`). The linter reads
 * validation off this VO for L1 (binding resolves) and L5 (validation coherence —
 * a field's validation may tighten the entry's, never loosen it).
 *
 * OWNERSHIP / INTEGRATION SEAM (AT-177): this is the *consumer-owned* contract the
 * linter (WS1, cc3) depends on. WS0 (cc2) owns the canonical versioned Data Dictionary
 * DB tables + the CoreX-standard SA seed; its Eloquent-backed
 * {@see DataDictionaryResolver} implementation maps each dictionary row into one of
 * these VOs. Keeping the VO framework-free keeps the linter a pure function.
 *
 * `$validation` is a normalized associative array of constraint keys. Recognised keys
 * (all optional; absent = unconstrained on that axis):
 *   - required   : bool
 *   - type       : string  (e.g. 'string','integer','decimal','money_zar','sa_id',
 *                           'ppra_no','date','email','tel','boolean','enum')
 *   - max_length : int
 *   - min_length : int
 *   - min        : int|float   (numeric/date lower bound; dates as ISO strings compare lexically)
 *   - max        : int|float
 *   - regex      : string      (PCRE, WITHOUT delimiters or with — normalized by the rule)
 *   - enum       : string[]    (allowed values)
 *   - decimals   : int         (max decimal places)
 */
final class DictionaryEntry
{
    /**
     * @param string               $ref        Stable dictionary entry key (e.g. 'seller_id_number').
     * @param string               $category   Grouping (money|identity|property|practitioner|date|party|other).
     * @param string               $type       Canonical value type (drives coherence checks).
     * @param array<string,mixed>  $validation Normalized constraint map (see class docblock).
     * @param string|null          $label      Human label (informational).
     */
    public function __construct(
        public readonly string $ref,
        public readonly string $category,
        public readonly string $type,
        public readonly array $validation = [],
        public readonly ?string $label = null,
    ) {
    }

    /**
     * Convenience constructor from a plain array (the shape WS0's resolver will hydrate from).
     *
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ref: (string) ($data['ref'] ?? ''),
            category: (string) ($data['category'] ?? 'other'),
            type: (string) ($data['type'] ?? 'string'),
            validation: is_array($data['validation'] ?? null) ? $data['validation'] : [],
            label: isset($data['label']) ? (string) $data['label'] : null,
        );
    }

    /**
     * Read a single normalized validation constraint, or $default when unset.
     */
    public function constraint(string $key, mixed $default = null): mixed
    {
        return $this->validation[$key] ?? $default;
    }
}
