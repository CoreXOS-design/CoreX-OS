<?php

declare(strict_types=1);

namespace App\Support\Html;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Rental notice templates (breach notices, notices to vacate ...) are HTML written by an agency's template editor and shown UNESCAPED on the breach
 * screens and in the notice PDF. This removes everything active from that HTML - on SAVE and again on RENDER (audit 2026-10-20, "template HTML unescaped"
 * note) - and leaves the letter itself (headings, paragraphs, lists, tables, bold / italic, links, images) exactly as written.
 *
 * It is the same list of things CoreX already refuses in an imported e-sign template (App\Services\Docuperfect\TemplateTransfer\TemplateContentSafety: scripts,
 * frames / objects, javascript links, inline event handlers), applied as a clean-up instead of a refusal, plus forms and embedded vector markup. CoreX has no
 * general HTML purifier package, and none was added.
 */
final class NoticeHtmlCleaner
{
    /** Elements removed together with everything inside them. */
    private const DROP_ELEMENTS = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'link', 'meta', 'base', 'form', 'input', 'button', 'select', 'textarea', 'svg', 'math', 'noscript', 'template'];

    /** Attributes that hold a URL. */
    private const URL_ATTRIBUTES = ['href', 'src', 'xlink:href', 'action', 'formaction', 'background', 'poster', 'srcset', 'data'];

    public static function clean(?string $html): string
    {
        $html = (string) $html;
        if ($html === '' || ! str_contains($html, '<')) {
            return $html;
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><div data-cleaner-root="1">' . $html . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = null;
        foreach ($dom->getElementsByTagName('div') as $div) {
            if ($div->getAttribute('data-cleaner-root') === '1') {
                $root = $div;
                break;
            }
        }
        if (! $root) {
            return strip_tags($html);
        }

        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child->nodeType === XML_COMMENT_NODE || $child->nodeType === XML_PI_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                $node->removeChild($child);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            if (in_array(strtolower($child->tagName), self::DROP_ELEMENTS, true)) {
                $node->removeChild($child);

                continue;
            }
            self::cleanAttributes($child);
            self::walk($child);
        }
    }

    private static function cleanAttributes(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);
            $value = (string) $attr->nodeValue;
            // inline event handlers (onclick, onerror, onload ...)
            if (str_starts_with($name, 'on')) {
                $el->removeAttribute($attr->nodeName);

                continue;
            }
            if (in_array($name, self::URL_ATTRIBUTES, true) && ! self::safeUrl($value, $name === 'src' && strtolower($el->tagName) === 'img')) {
                $el->removeAttribute($attr->nodeName);

                continue;
            }
            if ($name === 'style' && preg_match('/expression\s*\(|javascript\s*:|vbscript\s*:|@import|behaviou?r\s*:|-moz-binding/i', self::plain($value))) {
                $el->removeAttribute($attr->nodeName);
            }
        }
    }

    /** A link / picture address that cannot run code: no script scheme; a picture may also be an inline image (data:image/...). */
    private static function safeUrl(string $url, bool $isImage): bool
    {
        $plain = strtolower(self::plain($url));
        if (preg_match('/^(javascript|vbscript|livescript|mocha):/', $plain)) {
            return false;
        }
        if (str_starts_with($plain, 'data:')) {
            return $isImage && (bool) preg_match('#^data:image/(png|jpe?g|gif|webp);#', $plain);
        }

        return true;
    }

    /** The text a browser would read: entities decoded, whitespace and control characters (which browsers ignore inside a scheme) removed. */
    private static function plain(string $value): string
    {
        $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/[\x00-\x20\x7f]+/u', '', $decoded);
    }
}
