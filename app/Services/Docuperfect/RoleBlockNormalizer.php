<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Recipient-block contract normalizer.
 *
 * Stamps `data-role-block="{role_token}"` + `data-role-block-segment="…"`
 * on every block-level ancestor that contains role-bearing
 * `data-field` elements. Runs at IMPORT time (CDS cdsGenerate) and via
 * the one-time backfill command `php artisan docuperfect:normalize-templates`.
 *
 * The contract:
 *
 *   • `data-role-block="{role}"` — a container marked for the role's
 *     recipient loop. The renderer queries `//*[@data-role-block]`,
 *     groups by role, clones each container per recipient.
 *
 *   • `data-role-block-segment="{name}"` — optional hint that this
 *     container is one segment of a larger group. The renderer uses
 *     it to decide whether to prepend the "Seller - {Name}"
 *     sub-heading (first segment in a group gets it; subsequent
 *     segments share the heading visually). Segment names are
 *     heuristic-derived from field sub-names:
 *       identity   — first_name / last_name / id_number / full_name
 *       address    — address / address_line_1 / physical_address
 *       contact    — phone / email / mobile / cell_phone
 *       signature  — *_signature (future)
 *       data       — fallback when sub-name doesn't match the above
 *
 * Why import-time normalisation:
 *
 * Before the contract, the renderer guessed block boundaries via
 * clustering + LCA walks + largest-cluster-wins heuristics. Different
 * document layouts produced different failures; per-document patching
 * was the only remedy.
 *
 * After the contract, every imported template carries the structural
 * boundaries declaratively. The renderer reads them; no guessing.
 * Patches stop being per-document — the engine handles every
 * imported document by construction.
 *
 * Idempotent — running the normalizer twice produces the same output.
 * Elements that already carry the right `data-role-block` attribute
 * are left untouched.
 */
final class RoleBlockNormalizer
{
    private const BLOCK_TAGS = ['div', 'p', 'li', 'tr', 'td', 'section', 'article', 'aside', 'header', 'footer'];

    // `first_name+last_name` is the composite full-name column the CDS generator really
    // emits for a party's name field — it must read as identity, not fall through to `data`.
    //
    // `full_names` (PLURAL) is what the CDS BUILDER actually writes for a party's name field
    // (`contact.full_names` — a mandate is signed by "I / We", so the column is plural). The
    // singular `full_name` was here; the plural was not, so the one field the whole role block
    // is named for was classified as a generic `data` segment. Same mistake as the attribute
    // name, one level down: the list was written to the assumed shape, not the emitted one.
    private const IDENTITY_SUBS = ['first_name', 'last_name', 'first_name+last_name', 'name', 'full_name', 'full_names', 'id_number', 'id', 'name_surname_id', 'surname'];
    private const ADDRESS_SUBS  = ['address', 'address_1', 'address_line_1', 'address_residential', 'address_postal', 'physical_address'];
    private const CONTACT_SUBS  = ['phone', 'cell_phone', 'cell', 'mobile', 'email'];

    public function __construct(
        private readonly RoleBlockDetectionService $detector,
    ) {}

    /**
     * Normalise an HTML fragment by stamping the role-block contract
     * onto every block-level ancestor of role-bearing fields. Returns
     * the rewritten HTML — input shape is preserved otherwise.
     */
    public function normalize(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }
        $dom = $this->loadFragment($html);
        if ($dom === null) {
            return $html;
        }

        $xpath = new DOMXPath($dom);
        // Both shapes: `data-field` (role-anchored, legacy) and `data-field-name` (what the
        // CDS builder actually writes). Selecting only the former matched zero nodes on every
        // CDS template ever imported — which is why nothing was ever stamped.
        $fieldNodes = $xpath->query('//*[@data-field] | //*[@data-field-name]');
        if ($fieldNodes === false || $fieldNodes->length === 0) {
            return $html;
        }

        // First pass — collect (block-ancestor, role, segment-name) tuples
        // for each field. We stamp in a SECOND pass so multiple fields
        // sharing a block ancestor write to it once with the union of
        // their segment-name signals (identity beats address beats
        // contact for shared blocks).
        $stamps = []; // spl_object_hash(block) => ['node' => DOMElement, 'role' => string, 'subs' => string[]]
        foreach ($fieldNodes as $field) {
            if (!$field instanceof DOMElement) {
                continue;
            }
            // Resolve through the canonical bridge so the normalizer and the renderer
            // read field roles the SAME way — including the CDS/pillar shape the importer
            // actually emits (`contact.first_name` + `data-contact-type="Seller"`), which
            // parseFieldName() alone cannot see. See RoleBlockDetectionService::resolveFieldElement().
            $parsed = $this->detector->resolveFieldElement($field);

            $role = $parsed['role_base'] !== null ? strtolower($parsed['role_base']) : null;
            $sub  = $parsed['sub_name'] !== null ? strtolower($parsed['sub_name']) : null;

            if ($role === null) {
                continue;
            }

            $blockAncestor = $this->findBlockAncestor($field);
            if ($blockAncestor === null) {
                continue;
            }

            // If the ancestor already has data-role-block for a
            // DIFFERENT role, this is a conflict — leave it alone.
            $existingRole = $blockAncestor->getAttribute('data-role-block');
            if ($existingRole !== '' && strtolower($existingRole) !== $role) {
                continue;
            }

            $hash = spl_object_hash($blockAncestor);
            if (!isset($stamps[$hash])) {
                $stamps[$hash] = [
                    'node' => $blockAncestor,
                    'role' => $role,
                    'subs' => [],
                ];
            }
            if ($sub !== null) {
                $stamps[$hash]['subs'][] = $sub;
            }
        }

        // Second pass — apply stamps. Idempotent: if the block already
        // carries the right role + segment, we no-op.
        foreach ($stamps as $entry) {
            $node    = $entry['node'];
            $role    = $entry['role'];
            $segment = $this->deriveSegmentName($entry['subs']);

            if ($node->getAttribute('data-role-block') !== $role) {
                $node->setAttribute('data-role-block', $role);
            }
            if ($segment !== null && $node->getAttribute('data-role-block-segment') !== $segment) {
                $node->setAttribute('data-role-block-segment', $segment);
            }
        }

        return $this->serializeFragment($dom);
    }

    /**
     * Walk a node's parent chain until we hit a block-level element.
     * Stops at the synthetic wrapper root or <body>.
     */
    private function findBlockAncestor(DOMElement $node): ?DOMElement
    {
        $cur = $node->parentNode;
        while ($cur instanceof DOMElement) {
            if ($cur->nodeName === 'body' || $cur->getAttribute('id') === RoleBlockDetectionService::ROOT_ID) {
                return null;
            }
            if (in_array($cur->nodeName, self::BLOCK_TAGS, true)) {
                return $cur;
            }
            $cur = $cur->parentNode;
        }
        return null;
    }

    /**
     * Pick the most-specific segment name for a block, given the
     * sub-names of every role field inside it. Identity sub-names win
     * over address sub-names which win over contact sub-names — so a
     * block containing first_name + address gets labelled "identity"
     * (the more identifying signal).
     *
     * @param  list<string> $subs
     */
    private function deriveSegmentName(array $subs): ?string
    {
        if (empty($subs)) {
            return null;
        }
        foreach ($subs as $sub) {
            if (in_array($sub, self::IDENTITY_SUBS, true)) {
                return 'identity';
            }
        }
        foreach ($subs as $sub) {
            if (in_array($sub, self::ADDRESS_SUBS, true)) {
                return 'address';
            }
        }
        foreach ($subs as $sub) {
            if (in_array($sub, self::CONTACT_SUBS, true)) {
                return 'contact';
            }
        }
        return 'data';
    }

    /**
     * Load a body-fragment HTML string into a DOMDocument, wrapping it
     * in a deterministic root container so we can serialise back
     * cleanly. Mirrors RoleBlockDetectionService::loadFragment so the
     * two services share the same DOM-handling contract.
     */
    private function loadFragment(string $html): ?DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"?><html><body><div id="' . RoleBlockDetectionService::ROOT_ID . '">' . $html . '</div></body></html>';
        $ok = $dom->loadHTML(
            $wrapped,
            LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        return $ok ? $dom : null;
    }

    private function serializeFragment(DOMDocument $dom): string
    {
        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//*[@id="' . RoleBlockDetectionService::ROOT_ID . '"]')->item(0);
        if (!$root instanceof DOMElement) {
            return '';
        }
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        return $out;
    }
}
