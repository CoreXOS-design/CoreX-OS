<?php

namespace App\Support\Platform;

/**
 * Allow-list HTML sanitiser for the platform email signature (and SVG logo check).
 * No HTMLPurifier in this codebase — DOM walk: unknown tags are unwrapped (children kept), dangerous
 * containers are dropped with their content, attributes are allow-listed per tag, URLs are scheme-checked.
 * Spec: .ai/specs/platform-company-profile.md §6.
 */
class SafeHtml
{
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select',
        'option', 'link', 'meta', 'base', 'svg', 'math', 'noscript', 'template', 'frame', 'frameset', 'applet', 'title', 'head',
    ];

    private const ALLOWED_TAGS = [
        'a', 'b', 'strong', 'i', 'em', 'u', 'br', 'p', 'div', 'span', 'img', 'small', 'hr',
        'table', 'thead', 'tbody', 'tr', 'td', 'th', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'sup', 'sub',
    ];

    private const GLOBAL_ATTRS = ['style', 'title'];

    private const TAG_ATTRS = [
        'a'     => ['href', 'target'],
        'img'   => ['src', 'alt', 'width', 'height'],
        'table' => ['width', 'cellpadding', 'cellspacing', 'border', 'align'],
        'td'    => ['colspan', 'rowspan', 'align', 'valign', 'width'],
        'th'    => ['colspan', 'rowspan', 'align', 'valign', 'width'],
        'p'     => ['align'],
        'div'   => ['align'],
    ];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="__sh_root__">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $dom->getElementById('__sh_root__');
        if (! $root) {
            return e(strip_tags($html));
        }

        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    private static function walk(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment) {
                $node->removeChild($child);

                continue;
            }
            if (! $child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);

                continue;
            }

            self::walk($child);

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unwrap: keep the (already cleaned) children, drop the element.
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            self::cleanAttributes($child, $tag);
        }
    }

    private static function cleanAttributes(\DOMElement $el, string $tag): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRS, self::TAG_ATTRS[$tag] ?? []);

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            if (! in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->name);

                continue;
            }
            $value = trim($attr->value);

            if ($name === 'style') {
                $value = self::cleanStyle($value);
                $value === '' ? $el->removeAttribute('style') : $el->setAttribute('style', $value);
            } elseif ($name === 'href') {
                self::isSafeUrl($value, ['http', 'https', 'mailto', 'tel']) ? $el->setAttribute('href', $value) : $el->removeAttribute('href');
            } elseif ($name === 'src') {
                $ok = self::isSafeUrl($value, ['http', 'https'])
                    || preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=\s]+$#i', $value) === 1;
                $ok ? $el->setAttribute('src', $value) : $el->removeAttribute('src');
            } elseif ($name === 'target') {
                // Only _blank survives, and always with rel=noopener.
                if ($value === '_blank') {
                    $el->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $el->removeAttribute('target');
                }
            }
        }
    }

    private static function cleanStyle(string $style): string
    {
        // Strip comments/escapes first so "exp/**/ression(" and "\65xpression" cannot hide a payload.
        $probe = strtolower(preg_replace(['#/\*.*?\*/#s', '#\\\\[0-9a-f]{1,6}\s?#i', '#\s+#'], ['', '', ''], $style));
        foreach (['expression(', 'javascript:', 'vbscript:', 'behavior:', '@import', 'url(', '-moz-binding'] as $bad) {
            if (str_contains($probe, $bad)) {
                return '';
            }
        }

        return $style;
    }

    private static function isSafeUrl(string $url, array $schemes): bool
    {
        $probe = preg_replace('/[\x00-\x20]+/', '', $url);
        if ($probe === '') {
            return false;
        }
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $probe, $m)) {
            return in_array(strtolower($m[1]), $schemes, true);
        }

        // Relative / protocol-relative / fragment: harmless for a signature, but "//host" is fine too.
        return true;
    }

    /**
     * SVG upload check: must be a well-formed <svg> document with no script/handlers/foreign content/external refs.
     * Returns null when safe, otherwise a plain-language reason.
     */
    public static function svgProblem(string $svg): ?string
    {
        $svg = ltrim($svg, "\xEF\xBB\xBF \t\r\n");
        if ($svg === '' || strlen($svg) > 2 * 1024 * 1024) {
            return 'The SVG file is empty or too large.';
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // No LIBXML_NOENT / NONET semantics needed: we never expand entities; a DOCTYPE is refused outright.
        $ok = $dom->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok || ! $dom->documentElement || strtolower($dom->documentElement->localName) !== 'svg') {
            return 'That file is not a valid SVG image.';
        }
        if ($dom->doctype) {
            return 'SVG files containing a DOCTYPE or entity declarations are not accepted.';
        }

        $xp = new \DOMXPath($dom);
        foreach ($xp->query('//*') as $el) {
            $name = strtolower($el->localName);
            if (in_array($name, ['script', 'foreignobject', 'iframe', 'object', 'embed', 'audio', 'video', 'animate', 'set', 'handler', 'listener'], true)) {
                return 'The SVG contains active content (<' . $name . '>), which is not allowed in a logo.';
            }
            foreach ($el->attributes as $attr) {
                $an = strtolower($attr->name);
                $av = strtolower(preg_replace('/\s+/', '', $attr->value));
                if (str_starts_with($an, 'on')) {
                    return 'The SVG contains script event attributes, which are not allowed in a logo.';
                }
                if (in_array($an, ['href', 'xlink:href', 'src'], true) && $av !== '' && ! str_starts_with($av, '#') && ! preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $av)) {
                    return 'The SVG links to an external resource; embed the artwork in the file instead.';
                }
                if (str_contains($av, 'javascript:') || (in_array($an, ['style'], true) && (str_contains($av, 'url(http') || str_contains($av, '@import')))) {
                    return 'The SVG contains disallowed script or external references.';
                }
            }
        }
        if (stripos($svg, '<style') !== false && preg_match('/@import|url\(\s*[\'"]?\s*https?:/i', $svg)) {
            return 'The SVG style block pulls in external resources, which is not allowed in a logo.';
        }

        return null;
    }
}
