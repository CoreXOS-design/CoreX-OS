<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * THE MISSING BRIDGE — project a CDS template's curated bindings INTO its markup.
 *
 * The role-block contract is read from the HTML: RoleBlockNormalizer stamps
 * `data-role-block` around ROLE-BEARING fields, and it resolves a field's role from the
 * element itself (`data-contact-type="Seller"`). The renderer does the same at signing time.
 *
 * But the CDS builder never puts the role in the markup. It writes:
 *
 *     <span data-field-name="contact.full_names" data-tag-id="tag-…">[Owner Name(s)]</span>
 *
 * — and keeps the actual binding (which contact type, which named field) in a SEPARATE
 * `mappings` JSON, keyed by that `data-tag-id`. The normalizer is handed only HTML, so the
 * role is invisible to it: `role_base` resolves to NULL for every field, nothing is
 * role-bearing, and nothing is stamped.
 *
 * That is why contract coverage has been ZERO in every database since the engine shipped, why
 * every multi-party mandate still renders through legacy clustering, and why the one-time
 * backfill is a no-op — there was never anything for it to find. The engine was never broken.
 * Its input was never produced.
 *
 * This projects the binding onto the element, which is the one thing that was missing:
 *
 *   data-field        ← mirrored from data-field-name, so every legacy selector matches too
 *   data-contact-type ← from the binding's sourceContactType ("Seller", "Lessee", …)
 *
 * It is deliberately a PROJECTION, not a rewrite: the mappings remain the single source of
 * truth for what a field means, and this only makes that truth visible to the engine that has
 * to read it out of the document.
 */
class CdsBindingProjector
{
    public function __construct(private readonly RoleBlockDetectionService $detector)
    {
    }

    /**
     * @param  array<string,array<string,mixed>>  $mappings  keyed by data-tag-id
     */
    public function project(string $html, array $mappings): string
    {
        if (trim($html) === '' || $mappings === []) {
            return $html;
        }

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="__cds_root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok) {
            return $html;   // unparseable — leave the document exactly as it was
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*[@data-tag-id]');
        if ($nodes === false) {
            return $html;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $binding = $mappings[$node->getAttribute('data-tag-id')] ?? null;
            if (! is_array($binding)) {
                continue;
            }

            // 1. Mirror the field name into `data-field` so every selector in the engine —
            //    including the ones written before the CDS builder existed — matches it.
            $name = $this->detector->fieldNameOf($node);
            if ($name !== '' && $node->getAttribute('data-field') === '') {
                $node->setAttribute('data-field', $name);
            }

            // 2. Publish the ROLE. This is the fact that only ever lived in the mappings.
            //    Without it the field is party-less and the contract cannot be stamped.
            $contactType = trim((string) ($binding['sourceContactType'] ?? ''));
            if ($contactType !== '' && $node->getAttribute('data-contact-type') === '') {
                $node->setAttribute('data-contact-type', $contactType);
            }

            // 3. Signature surfaces carry their party in `parties` — publish the first one,
            //    so a signature block is role-bearing exactly like a field is.
            $parties = $binding['parties'] ?? null;
            if (is_array($parties) && $parties !== [] && $node->getAttribute('data-contact-type') === '') {
                $node->setAttribute('data-contact-type', (string) $parties[0]);
            }
        }

        $root = $dom->getElementById('__cds_root__');
        if (! $root) {
            return $html;
        }

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }
}
