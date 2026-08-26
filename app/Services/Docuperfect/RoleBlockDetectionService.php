<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Models\Docuperfect\Template;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
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
     * Resolve a field ELEMENT's role — reading BOTH naming conventions.
     *
     * There are two, and only one of them was ever understood:
     *
     *   1. Role-anchored name  — `data-field="seller_1_name"`.  parseFieldName() reads it.
     *   2. CDS / pillar name   — `data-field="contact.first_name"` with the role in a
     *      SIBLING attribute, `data-contact-type="Seller"`.
     *
     * Shape 2 is what the CDS importer ACTUALLY produces, for every document, and always
     * has (DocumentTemplateGenerator writes `{source_type}.{source_column}` and puts the
     * party in `data-contact-type`; the AI that names the fields is specified the same way).
     * Shape 1 is what the role-block engine expected. Because nothing bridged them,
     * parseFieldName() returned null for every imported field: the normalizer stamped
     * nothing, the renderer re-indexed nothing, and every template in every database fell
     * through to legacy clustering. That was the P1-0 RED verdict.
     *
     * This is the bridge, and it is deliberately the ONLY one — both the normalizer
     * (stamping) and the expansion service (per-clone identity + prefill) resolve through
     * here, so the two halves cannot drift apart again.
     *
     * @return array{role_base: ?string, instance_index: int, sub_name: ?string, pattern: string}
     */
    public function resolveFieldElement(\DOMElement $field): array
    {
        $name   = $this->fieldNameOf($field);
        $parsed = $this->parseFieldName($name);

        // Shape 1 — the role is in the name. Nothing to bridge.
        if ($parsed['role_base'] !== null) {
            return $parsed;
        }

        // Shape 2 — the role lives in data-contact-type ("Seller" / "Lessee" / …).
        $contactType = strtolower(trim($field->getAttribute('data-contact-type')));
        if ($contactType === '' || !in_array($contactType, self::ROLE_BASES, true)) {
            return $parsed; // genuinely party-less (manual./property.) — leave it alone
        }

        // The segment signal is the column half: `contact.id_number` → `id_number`.
        $subName = null;
        if (str_contains($name, '.')) {
            $column  = strtolower(substr($name, strpos($name, '.') + 1));
            $subName = $column !== '' ? $column : null;
        }

        return [
            'role_base'      => $contactType,
            // The CDS shape carries no instance index — one field per party per blank.
            // Multi-party instances come from CLONING the role block per recipient, so the
            // index is assigned at clone time, not read from the name.
            'instance_index' => 1,
            'sub_name'       => $subName,
            'pattern'        => 'cds_contact_type',
        ];
    }

    /**
     * The field's NAME, whichever attribute the producer happened to write it in.
     *
     * ── THE THIRD SHAPE (the one nobody bridged) ────────────────────────────────
     *
     * The docblock above says the CDS importer produces `data-field="contact.first_name"`.
     * It does not. The CDS BUILDER — the editor the import actually goes through — writes:
     *
     *     <span data-field-name="contact.full_names"
     *           data-field-label="Owner Name(s)"
     *           data-tag-id="tag-…">[Owner Name(s)]</span>
     *
     * `data-field-NAME`, not `data-field`. So every selector in the engine
     * (`//*[@data-field]`) matched ZERO nodes on every CDS template in every database, the
     * normalizer stamped nothing, and every document — including every multi-party mandate —
     * fell through to legacy clustering. The bridge existed; it was built to the wrong side
     * of the gap.
     *
     * Both names are read here, and ONLY here, so the normalizer (stamping) and the expansion
     * service (rendering) cannot drift apart again — which is the whole point of this class.
     */
    public function fieldNameOf(\DOMElement $field): string
    {
        $name = trim($field->getAttribute('data-field'));

        return $name !== '' ? $name : trim($field->getAttribute('data-field-name'));
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

    /**
     * Detect block boundaries in the rendered body. A "block" is the lowest
     * common ancestor of a contiguous cluster of `data-field` elements
     * sharing the same role-base, restricted to ancestors that contain
     * ONLY this role's fields.
     *
     * Returns a Collection of boundary descriptors per cluster:
     *
     *   role_token              The role-base for this cluster.
     *   cluster_ordinal         0-based ordinal of this cluster within the role.
     *   total_clusters_for_role How many disjoint clusters of this role exist
     *                           in the document (1 = single-block authoring,
     *                           >1 = the role has split content).
     *   max_instance_index      Highest instance_index parsed from this
     *                           cluster's field names (1 = single-block field
     *                           authoring, >1 = hardcoded numbered fields).
     *   instance_groups         Map of instance_index → list of field info
     *                           {field_name, sub_name, node} for fields whose
     *                           parsed idx matches that instance.
     *   block_node              The chosen LCA DOMElement that wraps the
     *                           cluster (the duplicatable unit). May be null
     *                           when the cluster doesn't have a wrapping
     *                           container (LCA degenerates to body) — in that
     *                           case the cluster cannot safely auto-loop and
     *                           the caller should fall back to stamping only.
     *   block_xpath             Stable XPath fragment selecting block_node
     *                           (so the expander can re-find it after
     *                           intermediate DOM mutations).
     *
     * Pass-through use: the expander can ignore the block_node/block_xpath
     * fields for Case B/C/D paths and only honours them for Case A.
     *
     * The optional Template parameter is reserved for future cases that need
     * field_mappings metadata (e.g. role-token alias resolution); today
     * detection works from field-name parsing alone.
     *
     * @return Collection<int, array{
     *   role_token: string,
     *   cluster_ordinal: int,
     *   total_clusters_for_role: int,
     *   max_instance_index: int,
     *   instance_groups: array<int, list<array{field_name: string, sub_name: ?string, node: ?DOMElement}>>,
     *   block_node: ?DOMElement,
     *   block_xpath: ?string,
     * }>
     */
    public function detectBlockBoundaries(?Template $template, string $renderedBody): Collection
    {
        $out = collect();
        if (trim($renderedBody) === '') {
            return $out;
        }

        $dom = $this->loadFragment($renderedBody);
        if ($dom === null) {
            return $out;
        }
        $xpath = new DOMXPath($dom);
        $fieldNodes = $xpath->query('//*[@data-field]');
        if ($fieldNodes === false || $fieldNodes->length === 0) {
            return $out;
        }

        // Walk fields in document order; build clusters by role-base.
        $clusters = [];
        $current = null;
        foreach ($fieldNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $fieldName = $node->getAttribute('data-field');
            $parsed = $this->parseFieldName($fieldName);
            $role = $parsed['role_base'];
            if ($role === null) {
                // Non-role field (e.g. purchase_price) breaks adjacency but
                // doesn't form its own cluster — finalise the current run.
                if ($current !== null) {
                    $clusters[] = $current;
                    $current = null;
                }
                continue;
            }
            $info = [
                'field_name' => $fieldName,
                'sub_name'   => $parsed['sub_name'],
                'idx'        => $parsed['instance_index'],
                'node'       => $node,
            ];
            if ($current === null || $current['role'] !== $role) {
                if ($current !== null) {
                    $clusters[] = $current;
                }
                $current = ['role' => $role, 'fields' => [$info]];
            } else {
                $current['fields'][] = $info;
            }
        }
        if ($current !== null) {
            $clusters[] = $current;
        }

        // Count clusters per role to populate total_clusters_for_role.
        $clustersByRole = [];
        foreach ($clusters as $c) {
            $clustersByRole[$c['role']] = ($clustersByRole[$c['role']] ?? 0) + 1;
        }

        $perRoleOrdinal = [];
        foreach ($clusters as $cluster) {
            $role = $cluster['role'];
            $perRoleOrdinal[$role] = ($perRoleOrdinal[$role] ?? -1) + 1;

            $instanceGroups = [];
            $maxIdx = 1;
            foreach ($cluster['fields'] as $field) {
                $idx = $field['idx'];
                $maxIdx = max($maxIdx, $idx);
                $instanceGroups[$idx] ??= [];
                $instanceGroups[$idx][] = $field;
            }

            $blockNode  = $this->findCleanLca($xpath, $cluster['fields'], $role);
            $blockXpath = $blockNode !== null ? $this->buildStableXpath($blockNode) : null;

            $out->push([
                'role_token'              => $role,
                'cluster_ordinal'         => $perRoleOrdinal[$role],
                'total_clusters_for_role' => $clustersByRole[$role],
                'field_count'             => count($cluster['fields']),
                'max_instance_index'      => $maxIdx,
                'instance_groups'         => $instanceGroups,
                'block_node'              => $blockNode,
                'block_xpath'             => $blockXpath,
            ]);
        }

        return $out;
    }

    /**
     * Load a body-fragment HTML string into a DOMDocument, wrapping it in a
     * deterministic root container so we can find it again later.
     *
     * Returns null when the fragment cannot be parsed (PCRE/libxml failure).
     */
    public function loadFragment(string $html): ?DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"?><html><body><div id="' . self::ROOT_ID . '">' . $html . '</div></body></html>';
        $ok = $dom->loadHTML(
            $wrapped,
            LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        return $ok ? $dom : null;
    }

    /**
     * Serialise the children of the wrapper root back to an HTML fragment.
     */
    public function serializeFragment(DOMDocument $dom): string
    {
        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//*[@id="' . self::ROOT_ID . '"]')->item(0);
        if (!$root instanceof DOMElement) {
            return '';
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }

    public const ROOT_ID = '__corex_loop_root__';

    /**
     * Find the lowest common ancestor of the cluster's field nodes that
     * contains ONLY this role's fields (no foreign-role data-field elements).
     * Returns null if the only such ancestor is the body/root wrapper
     * (cluster has no proper container — auto-loop unsafe for this cluster).
     *
     * @param  list<array{field_name:string,sub_name:?string,idx:int,node:DOMElement}> $fields
     */
    private function findCleanLca(DOMXPath $xpath, array $fields, string $role): ?DOMElement
    {
        if (count($fields) === 0) {
            return null;
        }
        // LCA across all field nodes.
        $lca = $fields[0]['node'];
        for ($i = 1; $i < count($fields); $i++) {
            $lca = $this->lcaOfPair($lca, $fields[$i]['node']);
            if ($lca === null) {
                return null;
            }
        }
        // If the LCA is itself the data-field element (single-field cluster),
        // walk up one level to the wrapper so the duplication unit is the
        // container — duplicating a bare span without its surrounding markup
        // produces malformed output (e.g. a header h4 nested inside a span).
        if ($lca->hasAttribute('data-field') && $lca->parentNode instanceof DOMElement) {
            $candidate = $lca->parentNode;
            if (
                $candidate->nodeName !== 'body'
                && $candidate->getAttribute('id') !== self::ROOT_ID
                && $this->subtreeContainsOnlyRole($xpath, $candidate, $role)
            ) {
                $lca = $candidate;
            }
        }
        // The bare LCA might still contain foreign-role fields if the cluster
        // is not contiguous in DOM order. Verify; if not clean, no safe block.
        if (!$this->subtreeContainsOnlyRole($xpath, $lca, $role)) {
            return null;
        }
        // Bail if the LCA is the body wrapper itself (no real container).
        if ($lca->nodeName === 'body' || $lca->getAttribute('id') === self::ROOT_ID) {
            return null;
        }
        return $lca;
    }

    private function lcaOfPair(DOMElement $a, DOMElement $b): ?DOMElement
    {
        $ancestors = [];
        $cur = $a;
        while ($cur instanceof DOMElement) {
            $ancestors[spl_object_hash($cur)] = $cur;
            $parent = $cur->parentNode;
            $cur = $parent instanceof DOMElement ? $parent : null;
        }
        $cur = $b;
        while ($cur instanceof DOMElement) {
            $key = spl_object_hash($cur);
            if (isset($ancestors[$key])) {
                return $cur;
            }
            $parent = $cur->parentNode;
            $cur = $parent instanceof DOMElement ? $parent : null;
        }
        return null;
    }

    private function subtreeContainsOnlyRole(DOMXPath $xpath, DOMElement $node, string $role): bool
    {
        $fields = $xpath->query('.//*[@data-field]', $node);
        if ($fields === false) {
            return false;
        }
        foreach ($fields as $f) {
            if (!$f instanceof DOMElement) {
                continue;
            }
            $parsed = $this->parseFieldName($f->getAttribute('data-field'));
            $r = $parsed['role_base'];
            if ($r !== null && $r !== $role) {
                return false;
            }
        }
        return true;
    }

    /**
     * Build an absolute XPath for the given node so the expander can re-find
     * it after intermediate mutations have invalidated DOMNode references.
     * Walk includes the root <html> element so the path is fully absolute
     * (queryable from DOMDocument context).
     */
    private function buildStableXpath(DOMElement $node): string
    {
        $parts = [];
        $cur = $node;
        while ($cur instanceof DOMElement) {
            $name = $cur->nodeName;
            $index = 1;
            $sib = $cur->previousSibling;
            while ($sib !== null) {
                if ($sib instanceof DOMElement && $sib->nodeName === $name) {
                    $index++;
                }
                $sib = $sib->previousSibling;
            }
            $parts[] = $name . '[' . $index . ']';
            $parent = $cur->parentNode;
            $cur = $parent instanceof DOMElement ? $parent : null;
        }
        return '/' . implode('/', array_reverse($parts));
    }
}
