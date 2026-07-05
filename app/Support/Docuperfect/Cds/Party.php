<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds;

use App\Support\Docuperfect\Cds\Enums\Cardinality;

/**
 * CDS v2 — a DECLARED signing party (spec §2 Party). Roles are data, never inferred
 * from HTML (obsoletes RoleBlockDetectionService clustering / LCA machinery).
 *
 * `key` is the role base ("seller", "buyer", "agent", "witness"). At signing time an
 * instance is addressed as `{key}_{index}` (e.g. "seller_2"), preserving today's
 * signer-identity invariant. `ordering` drives signing sequence.
 */
final class Party
{
    public function __construct(
        public readonly string $key,
        public readonly string $role,
        public readonly Cardinality $cardinality = Cardinality::One,
        public readonly bool $required = true,
        public readonly int $ordering = 0,
        public readonly ?string $contactBindingRule = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['key'],
            (string) ($data['role'] ?? $data['key']),
            Cardinality::from((string) ($data['cardinality'] ?? Cardinality::One->value)),
            (bool) ($data['required'] ?? true),
            (int) ($data['ordering'] ?? 0),
            isset($data['contact_binding_rule']) ? (string) $data['contact_binding_rule'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'role' => $this->role,
            'cardinality' => $this->cardinality->value,
            'required' => $this->required,
            'ordering' => $this->ordering,
            'contact_binding_rule' => $this->contactBindingRule,
        ];
    }
}
