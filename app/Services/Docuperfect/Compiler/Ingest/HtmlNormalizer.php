<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Ingest;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * AT-177 / WS4-E — normalizes arbitrary source HTML into a clean, block-level body fragment
 * that segmentation can walk (spec §3 step 1). Every ingestor funnels through here so the
 * downstream intermediate is source-agnostic.
 *
 * Strips <head>/<script>/<style>/comments, unwraps the document to its main content container
 * (`.corex-page` → `body` → root), and returns the inner HTML of that container.
 */
final class HtmlNormalizer
{
    public function normalize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        // Drop non-content nodes.
        foreach (['script', 'style'] as $tag) {
            $nodes = iterator_to_array($dom->getElementsByTagName($tag));
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
        $this->removeComments($dom);

        $container = $this->mainContainer($dom);
        if ($container === null) {
            return trim($html);
        }

        $inner = '';
        foreach ($container->childNodes as $child) {
            $inner .= $dom->saveHTML($child);
        }

        return trim($inner);
    }

    private function mainContainer(DOMDocument $dom): ?DOMElement
    {
        // Prefer the CoreX page wrapper if present.
        $xpath = new \DOMXPath($dom);
        $page = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " corex-page ")]');
        if ($page !== false && $page->length > 0 && $page->item(0) instanceof DOMElement) {
            return $page->item(0);
        }

        $body = $dom->getElementsByTagName('body');
        if ($body->length > 0 && $body->item(0) instanceof DOMElement) {
            return $body->item(0);
        }

        return $dom->documentElement;
    }

    private function removeComments(DOMDocument $dom): void
    {
        $xpath = new \DOMXPath($dom);
        $comments = $xpath->query('//comment()');
        if ($comments === false) {
            return;
        }
        foreach (iterator_to_array($comments) as $comment) {
            /** @var DOMNode $comment */
            $comment->parentNode?->removeChild($comment);
        }
    }
}
