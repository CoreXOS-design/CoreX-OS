<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds;

use App\Support\Docuperfect\Cds\Enums\FieldSource;

/**
 * CDS v2 — a single fill-point (spec §2 Field). NO fill-point exists without one.
 *
 * `binding` is MANDATORY: it names a Data Dictionary entry key. An unbound field
 * cannot compile (linter L1); a binding to a missing entry cannot compile (linter L2).
 * The binding IS the field-mapping — collapsing today's six competing field-truth
 * sources into one canonical fact.
 *
 * `validationOverride` may TIGHTEN the dictionary entry's validation, never loosen it
 * (linter L5). When null, the field inherits the entry's validation verbatim.
 */
final class Field
{
    /**
     * @param array<string,mixed>|null $validationOverride
     */
    public function __construct(
        public readonly string $fieldId,
        public readonly string $label,
        public readonly string $binding,
        public readonly FieldSource $source = FieldSource::AgentInput,
        public readonly bool $required = true,
        public readonly ?array $validationOverride = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['field_id'],
            (string) ($data['label'] ?? ''),
            (string) ($data['binding'] ?? ''),
            FieldSource::from((string) ($data['source'] ?? FieldSource::AgentInput->value)),
            (bool) ($data['required'] ?? true),
            $data['validation_override'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'field_id' => $this->fieldId,
            'label' => $this->label,
            'binding' => $this->binding,
            'source' => $this->source->value,
            'required' => $this->required,
            'validation_override' => $this->validationOverride,
        ];
    }

    /** L1: a field with no binding is unpublishable. */
    public function isBound(): bool
    {
        return trim($this->binding) !== '';
    }
}
