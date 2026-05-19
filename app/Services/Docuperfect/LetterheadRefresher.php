<?php

namespace App\Services\Docuperfect;

/**
 * Re-resolves the shared company-header (letterhead) inside a stored
 * web-template `merged_html` at signing/serve time.
 *
 * merged_html is rendered ONCE at prepareSigning and persisted
 * (ESignWizardController:1663); the signing view serves that frozen
 * snapshot verbatim (SigningController:224-225). A document whose
 * snapshot was taken before the agency data was corrected therefore
 * shows stale letterhead ("The Mandate Company / Margate") forever.
 *
 * components/company-header.blade.php resolves the agency live at render
 * time. Re-rendering it now (unauthenticated signing → it resolves the
 * current hfc-coastal agency row) and swapping the baked block guarantees
 * the signing view always shows CURRENT agency data without a destructive
 * data migration. Idempotent, fail-open (any error → original HTML).
 *
 * Mirrors the SignatureSurfaceNormalizer DOMDocument round-trip already
 * proven on this same merged_html.
 */
class LetterheadRefresher
{
    /** Distinctive inline-style signature of the company-header wrapper
     *  div (components/company-header.blade.php). */
    private const HEADER_STYLE_NEEDLE = 'border:1px solid #000; padding:4px 8px 4px 8px';

    public static function refresh(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '' || ! str_contains($html, self::HEADER_STYLE_NEEDLE)) {
            return $html;
        }

        try {
            $fresh = trim((string) view('docuperfect.web-templates.components.company-header')->render());
            if ($fresh === '') {
                return $html;
            }

            $dom = new \DOMDocument();
            @$dom->loadHTML(
                '<?xml encoding="utf-8"?>' . $html,
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
            );
            $xpath = new \DOMXPath($dom);

            // The company-header wrapper is the div whose inline style
            // carries the distinctive border+padding signature.
            $nodes = $xpath->query(
                '//div[contains(@style, "border:1px solid #000") and contains(@style, "padding:4px 8px 4px 8px")]'
            );
            if ($nodes->length === 0) {
                return $html;
            }

            // Parse the freshly rendered header into an importable node.
            $fragDom = new \DOMDocument();
            @$fragDom->loadHTML(
                '<?xml encoding="utf-8"?><div id="__cx_fresh_header__">' . $fresh . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
            );
            $freshWrap = (new \DOMXPath($fragDom))
                ->query('//*[@id="__cx_fresh_header__"]')->item(0);
            if (! $freshWrap) {
                return $html;
            }

            $replaced = false;
            // Replace EVERY company-header occurrence. A pack merges several
            // templates, each carrying its own baked letterhead — all must
            // show the CURRENT agency, not just the first. "No stale
            // letterhead anywhere in the e-sign flow." Single-doc snapshots
            // have exactly one occurrence, so behaviour is unchanged there.
            // Snapshot the node list first (iterator_to_array) because the
            // replacement mutates the live DOM as we go.
            foreach (iterator_to_array($nodes) as $target) {
                $parent = $target->parentNode;
                if (! $parent) {
                    continue;
                }
                // importNode(copy) — $freshWrap stays in $fragDom intact,
                // so each occurrence gets its own fresh header copy.
                foreach (iterator_to_array($freshWrap->childNodes) as $child) {
                    $imported = $dom->importNode($child, true);
                    $parent->insertBefore($imported, $target);
                }
                $parent->removeChild($target);
                $replaced = true;
            }

            if (! $replaced) {
                return $html;
            }

            $result = $dom->saveHTML();
            $result = preg_replace('/^<\?xml encoding="utf-8"\?>/', '', $result);

            return trim($result);
        } catch (\Throwable $e) {
            \Log::warning('LETTERHEAD_REFRESH_FAILED', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);

            return $html;
        }
    }
}
