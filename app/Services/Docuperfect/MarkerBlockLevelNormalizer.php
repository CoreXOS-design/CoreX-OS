<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

/**
 * Other-conditions insert (2026-08-20, Johan) — the structural half of the
 * fix. InsertableBlockRenderer::renderInDocument() resolves a
 * `~~~~MARKER~~~~` token with a blind `str_replace($marker, $rendered, $html)`
 * — no awareness of surrounding HTML. If the marker sits inside inline flow
 * (e.g. mid-paragraph text), substituting in a `<div>`/`<ol>`/`<button>`
 * block produces invalid nesting (`<p><div>...</div></p>`), which browsers
 * silently "correct" by closing the `<p>` early — the exact mechanism behind
 * "they do not render the same" (.ai investigation, 2026-08-20).
 *
 * This runs at SAVE time (both the CDS import path and the template-editor
 * Content-tab path — the same normalizer, called from both, so neither can
 * drift from the other) and guarantees every marker ends up as the sole
 * content of its own block-level element, a genuine sibling of wherever it
 * was typed — never nested inside a `<p>`, `<span>`, `<li>`, `<td>`, or
 * anything else. Idempotent: a marker that's already the sole content of
 * its own block-level parent is left untouched.
 */
final class MarkerBlockLevelNormalizer
{
    /** Elements DOMDocument treats as block-level for splitting purposes. */
    private const BLOCK_TAGS = ['p', 'div', 'li', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote'];

    public function normalize(string $html): string
    {
        if ($html === '' || !str_contains($html, '~~~~')) {
            return $html;
        }

        $wrapperOpen = '<!DOCTYPE html><html><body><div id="__root__">';
        $wrapperClose = '</div></body></html>';

        libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->loadHTML(
            $wrapperOpen . $html . $wrapperClose,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $doc->getElementById('__root__');
        if ($root === null) {
            // Malformed input DOMDocument couldn't anchor — never lose the
            // agent's edit over a normalization failure; save it as typed.
            return $html;
        }

        // Collect matching text nodes FIRST (mutating the tree while
        // iterating a live NodeList is unsafe — XPath query() returns a
        // static snapshot, which is what we want here).
        $xpath = new \DOMXPath($doc);
        $textNodes = $xpath->query('.//text()[contains(., "~~~~")]', $root);

        foreach ($textNodes as $textNode) {
            $this->splitAndWrapMarkersInTextNode($doc, $textNode);
        }

        $innerHtml = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $innerHtml .= $doc->saveHTML($child);
        }

        return $innerHtml;
    }

    private function splitAndWrapMarkersInTextNode(\DOMDocument $doc, \DOMText $textNode): void
    {
        if (!preg_match_all('/~{4,}([^~]{1,200}?)~{4,}/s', $textNode->nodeValue, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }

        $parent = $textNode->parentNode;
        if ($parent === null) {
            return;
        }

        // Already the sole content of its own block-level element — nothing
        // to split; re-wrapping an already-correct marker would be a no-op
        // anyway, but skipping keeps re-saves byte-stable. Checked against
        // the MARKER TEXT itself, not just "is this the only text node" —
        // "Some text before ~~~~X~~~~ and after." is also its parent's only
        // text-node child, but is very much NOT sole-marker content.
        if (
            in_array(strtolower($parent->nodeName), self::BLOCK_TAGS, true)
            && $parent->childNodes->length === 1
            && preg_match('/^~{4,}[^~]{1,200}?~{4,}$/s', trim($textNode->nodeValue)) === 1
        ) {
            return;
        }

        // Process matches in this text node right-to-left so earlier
        // offsets stay valid as we split.
        $matchList = $matches[0];
        for ($i = count($matchList) - 1; $i >= 0; $i--) {
            [$fullMatch, $offset] = $matchList[$i];
            $this->splitOneMarker($doc, $textNode, $offset, strlen($fullMatch), $fullMatch);
            // splitOneMarker replaces $textNode's content with the "before"
            // remainder — re-fetch nothing further needed since we're
            // walking right-to-left over the ORIGINAL string's offsets,
            // which the "before" truncation never invalidates (it only
            // shortens the tail, which we've already processed).
        }
    }

    private function splitOneMarker(\DOMDocument $doc, \DOMText $textNode, int $offset, int $length, string $markerText): void
    {
        $full = $textNode->nodeValue;
        $before = substr($full, 0, $offset);
        $after = substr($full, $offset + $length);
        $parent = $textNode->parentNode;
        $grandparent = $parent->parentNode;

        if ($grandparent === null) {
            // Parent has no parent to split against (shouldn't happen inside
            // our __root__ wrapper, but never corrupt the document over it).
            return;
        }

        // A bare <p> inserted as a sibling of a split <li> would sit
        // directly inside the parent <ul>/<ol> — itself invalid (a list's
        // only valid direct children are <li>) and exactly the kind of
        // mis-nesting this whole fix exists to prevent. Match the marker
        // element's tag to what the split actually produces a valid sibling
        // of: another <li> when splitting a list item, <p> everywhere else
        // (div/td/th/blockquote/root-level text all validly accept a <p>).
        $markerTag = strtolower($parent->nodeName) === 'li' ? 'li' : 'p';
        $markerEl = $doc->createElement($markerTag);
        $markerEl->setAttribute('data-insertable-block-marker', '1');
        $markerEl->appendChild($doc->createTextNode($markerText));

        $isDirectRootChild = $parent->nodeType === XML_ELEMENT_NODE && $parent->getAttribute('id') === '__root__';

        if ($isDirectRootChild) {
            // Marker text sits directly under the document root (no
            // wrapping element at all) — safe to replace the text node with
            // before-text, the marker paragraph, and after-text as direct
            // siblings; nothing needs splitting. Insert into $parent (the
            // root itself, since the text node's parent IS the root here),
            // NOT $grandparent (the root's own parent, one level too high).
            if ($before !== '') {
                $parent->insertBefore($doc->createTextNode($before), $textNode);
            }
            $parent->insertBefore($markerEl, $textNode);
            if ($after !== '') {
                $parent->insertBefore($doc->createTextNode($after), $textNode);
            }
            $parent->removeChild($textNode);

            return;
        }

        // Marker sits inside an element (typically <p>, but the same
        // operation is correct for <li>/<td>/<span>/anything else): split
        // that PARENT element into "before" and "after" clones, with the
        // marker paragraph inserted between them as a genuine sibling —
        // never nested inside the original parent.
        $textNode->nodeValue = $before;

        $afterClone = $parent->cloneNode(false);
        $movingNode = $textNode->nextSibling;
        while ($movingNode !== null) {
            $next = $movingNode->nextSibling;
            $afterClone->appendChild($movingNode);
            $movingNode = $next;
        }
        if ($after !== '') {
            // Prepend the after-text (created after the loop above so it
            // lands first inside afterClone, ahead of any moved siblings).
            $afterClone->insertBefore($doc->createTextNode($after), $afterClone->firstChild);
        }

        $refNode = $parent->nextSibling;
        $grandparent->insertBefore($markerEl, $refNode);
        if ($afterClone->childNodes->length > 0) {
            $grandparent->insertBefore($afterClone, $refNode);
        }

        if ($before === '') {
            // Nothing was left before the marker in the original parent —
            // remove the now-empty shell rather than leave a stray <p></p>.
            $parent->removeChild($textNode);
            if (!$parent->hasChildNodes()) {
                $grandparent->removeChild($parent);
            }
        }
    }
}
