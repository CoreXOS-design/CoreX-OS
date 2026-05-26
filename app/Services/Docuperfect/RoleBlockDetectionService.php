<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use Illuminate\Support\Collection;

/**
 * Recipient Loop Engine — B2 block detection.
 *
 * Parses rendered HTML body looking for `data-field="..."` attributes,
 * infers each field's role-base + instance-index from the field name,
 * and returns a Collection of `RoleFieldRef` value objects positioned
 * in document order.
 *
 * Why field-name parsing and not tag-id lookup against field_mappings:
 * the rendered HTML body (web_template_data.merged_html) carries
 * `data-field` (the field name) but NOT `data-tag-id`. The field_mappings
 * JSON is keyed by tag_id with human labels ("Seller 1 Phone") which
 * are converted to snake_case names ("seller_1_phone") for the rendered
 * output. So the only stable join key at HTML render time is the field
 * name pattern itself.
 *
 * Recognised patterns (all anchor a role base token recognised below):
 *   {role}_{idx}_{sub_name}      → e.g. seller_1_phone, seller_2_email
 *   {role}_{sub_name}_{idx}      → e.g. seller_address_1, seller_address_2
 *   {role}_{sub_name}            → e.g. seller_first_name (singleton, idx=1)
 *   {role}                       → e.g. agent (singleton)
 *
 * Recognised role base tokens (covers wizard's raw vocabulary plus the
 * canonical owner_party/acquiring_party):
 *   seller, buyer, lessor, lessee, landlord, tenant
 *   owner_party, acquiring_party
 *   agent, witness, spouse
 *
 * V1 scope (B2): detects role+index per field for the field-stamping
 * pipeline. Block clustering (consecutive same-role fields = one
 * instance block) is computed as metadata but block HTML-region
 * expansion ("1 block → N copies via DOM rewrite") is deferred until
 * HFC has a template that uses that authoring style. Today every
 * template uses hardcoded numbered field names.
 */
final class RoleBlockDetectionService
{
    /** Role base tokens recognised in field-name parsing. Ordered longest-first
     *  so multi-underscore tokens (owner_party, acquiring_party) win over
     *  shorter prefixes (would otherwise be greedily consumed). */
    public const ROLE_BASES = [
        'owner_party',
        'acquiring_party',
        'seller',
        'buyer',
        'lessor',
        'lessee',
        'landlord',
        'tenant',
        'agent',
        'witness',
        'spouse',
    ];

    /**
     * Detect every `data-field` reference in the supplied HTML.
     *
     * @return Collection<int, array{
     *   field_name: string,
     *   role_base: ?string,
     *   instance_index: int,
     *   sub_name: ?string,
     *   pattern: string,
     *   offset: int,
     * }>
     */
    public function detectFromHtml(string $html): Collection
    {
        $out = collect();
        if (!preg_match_all('/data-field="([^"]+)"/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $out;
        }

        foreach ($matches[1] as $i => [$fieldName, $offset]) {
            $parsed = $this->parseFieldName((string) $fieldName);
            $out->push([
                'field_name'     => (string) $fieldName,
                'role_base'      => $parsed['role_base'],
                'instance_index' => $parsed['instance_index'],
                'sub_name'       => $parsed['sub_name'],
                'pattern'        => $parsed['pattern'],
                'offset'         => (int) $offset,
            ]);
        }
        return $out;
    }

    /**
     * Parse a snake_case field name into role-base + instance-index + sub-name.
     *
     * @return array{
     *   role_base: ?string,
     *   instance_index: int,
     *   sub_name: ?string,
     *   pattern: 'role_idx_sub'|'role_sub_idx'|'role_sub'|'role'|'none',
     * }
     */
    public function parseFieldName(string $name): array
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return ['role_base' => null, 'instance_index' => 1, 'sub_name' => null, 'pattern' => 'none'];
        }

        foreach (self::ROLE_BASES as $base) {
            // Anchored at start; require a word boundary (the base name + either
            // end-of-string OR an underscore separator).
            if ($name === $base) {
                return ['role_base' => $base, 'instance_index' => 1, 'sub_name' => null, 'pattern' => 'role'];
            }
            $prefix = $base . '_';
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $rest = substr($name, strlen($prefix));

            // Pattern role_idx_sub — first token after base is a pure integer.
            if (preg_match('/^(\d+)(?:_(.+))?$/', $rest, $m)) {
                return [
                    'role_base'      => $base,
                    'instance_index' => max(1, (int) $m[1]),
                    'sub_name'       => $m[2] ?? null,
                    'pattern'        => isset($m[2]) ? 'role_idx_sub' : 'role_idx_sub',
                ];
            }

            // Pattern role_sub_idx — trailing integer after one or more sub-tokens.
            if (preg_match('/^(.+)_(\d+)$/', $rest, $m)) {
                return [
                    'role_base'      => $base,
                    'instance_index' => max(1, (int) $m[2]),
                    'sub_name'       => $m[1],
                    'pattern'        => 'role_sub_idx',
                ];
            }

            // Pattern role_sub (no index, singleton).
            return [
                'role_base'      => $base,
                'instance_index' => 1,
                'sub_name'       => $rest,
                'pattern'        => 'role_sub',
            ];
        }

        // No recognised role base.
        return ['role_base' => null, 'instance_index' => 1, 'sub_name' => null, 'pattern' => 'none'];
    }
}
