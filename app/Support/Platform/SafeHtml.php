<?php

namespace App\Support\Platform;

/**
 * Allow-list HTML sanitiser — the ONE place untrusted-ish HTML is made safe in the platform area:
 *   · clean()        the platform email signature (spec .ai/specs/platform-company-profile.md §6)
 *   · cleanWording() every block of the Subscription Agreement wording, before it reaches the public /legal page, the recipient
 *                    signing page, the owner screens or a PDF (spec .ai/specs/agency-timeline-and-platform-esign.md §11.14)
 *   · svgProblem()   SVG logo upload check
 * No HTMLPurifier in this codebase — DOM walk: unknown tags are unwrapped (children kept), dangerous containers are dropped with
 * their content, attributes are allow-listed per tag, URLs are scheme-checked after entity decoding and control-character removal,
 * and style attributes are rebuilt from an allow-list of properties (CSS escapes are DECODED before anything is checked, so
 * `\75rl(` cannot hide a `url(`). Everything the sanitiser removes can be collected in $removed, which is how the wording editor
 * tells the owner exactly what is not allowed instead of silently changing their text.
 */
class SafeHtml
{
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select',
        'option', 'link', 'meta', 'base', 'svg', 'math', 'noscript', 'template', 'frame', 'frameset', 'applet', 'title', 'head',
        'xmp', 'plaintext', 'noembed', 'noframes', 'audio', 'video', 'canvas', 'portal', 'dialog', 'iframe', 'bgsound', 'isindex',
    ];

    /** Properties a style attribute may carry (everything else — position, float, z-index, background, content … — is dropped). */
    private const STYLE_PROPS = [
        'signature' => [
            'color', 'background-color', 'font-family', 'font-size', 'font-weight', 'font-style', 'text-decoration', 'text-align', 'vertical-align',
            'line-height', 'letter-spacing', 'white-space', 'display', 'width', 'height', 'max-width', 'min-width',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left', 'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'border', 'border-top', 'border-right', 'border-bottom', 'border-left', 'border-color', 'border-width', 'border-style', 'border-collapse',
        ],
        'wording' => ['width', 'text-align', 'vertical-align', 'font-weight', 'font-style', 'text-decoration'],
    ];

    /** CSS functions a value may use (nothing that can load a resource). */
    private const CSS_FUNCTIONS = ['rgb', 'rgba', 'hsl', 'hsla'];

    /** Largest inline image (characters of base64) a saved signature may embed. */
    private const MAX_DATA_IMAGE = 400000;

    /** Class names the wording may carry (the structure classes the shipped Markdown tables use); any other class is removed. */
    private const WORDING_CLASSES = ['odd', 'even', 'header'];

    private const PROFILES = [
        'signature' => [
            'tags' => ['a', 'b', 'strong', 'i', 'em', 'u', 'br', 'p', 'div', 'span', 'img', 'small', 'hr',
                'table', 'thead', 'tbody', 'tr', 'td', 'th', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'sup', 'sub'],
            'global' => ['style', 'title'],
            'attrs' => [
                'a'     => ['href', 'target'],
                'img'   => ['src', 'alt', 'width', 'height'],
                'table' => ['width', 'cellpadding', 'cellspacing', 'border', 'align'],
                'td'    => ['colspan', 'rowspan', 'align', 'valign', 'width'],
                'th'    => ['colspan', 'rowspan', 'align', 'valign', 'width'],
                'p'     => ['align'],
                'div'   => ['align'],
            ],
            'href' => ['http', 'https', 'mailto', 'tel'],
            'relative' => true,
            'classes' => [],
        ],
        'wording' => [
            'tags' => ['a', 'b', 'strong', 'i', 'em', 'u', 's', 'del', 'ins', 'br', 'p', 'div', 'span', 'small', 'hr', 'code', 'pre', 'blockquote',
                'table', 'thead', 'tbody', 'tfoot', 'caption', 'colgroup', 'col', 'tr', 'td', 'th', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'sup', 'sub'],
            'global' => ['title', 'class', 'style'],
            'attrs' => [
                'a'        => ['href'],
                'td'       => ['colspan', 'rowspan', 'align', 'valign'],
                'th'       => ['colspan', 'rowspan', 'align', 'valign'],
                'col'      => ['span'],
                'colgroup' => ['span'],
                'ol'       => ['start'],
            ],
            'href' => ['http', 'https', 'mailto', 'tel'],
            'relative' => false,
            'classes' => self::WORDING_CLASSES,
        ],
    ];

    /** The signature profile (kept as the default so existing callers are unchanged). */
    public static function clean(?string $html, ?array &$removed = null): string
    {
        return self::run($html, 'signature', $removed);
    }

    /**
     * One rendered block of the agreement wording. Allows ordinary text structure, tables, links (web / email / in-page) and a
     * few structure classes; no images, no remote anything, no script, no handlers, no style beyond widths and alignment.
     *
     * @param string[]|null $removed receives a short description of every element/attribute that had to be removed
     */
    public static function cleanWording(?string $html, ?array &$removed = null): string
    {
        return self::run($html, 'wording', $removed);
    }

    private static function run(?string $html, string $profile, ?array &$removed): string
    {
        $removed = [];
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
            $removed[] = 'markup that could not be read';

            return e(strip_tags($html));
        }

        self::walk($root, self::PROFILES[$profile], $profile, $removed);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }
        $removed = array_values(array_unique($removed));

        return trim($out);
    }

    private static function walk(\DOMNode $node, array $p, string $profile, array &$removed): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMText && ! $child instanceof \DOMCdataSection) {
                continue;
            }
            if (! $child instanceof \DOMElement) {
                // Comments, processing instructions, CDATA and entity references are never kept.
                $node->removeChild($child);

                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $removed[] = '<' . $tag . '>';
                $node->removeChild($child);

                continue;
            }

            self::walk($child, $p, $profile, $removed);

            if (! in_array($tag, $p['tags'], true)) {
                // Unwrap: keep the (already cleaned) children, drop the element.
                $removed[] = '<' . $tag . '>';
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);

                continue;
            }

            self::cleanAttributes($child, $tag, $p, $profile, $removed);

            if ($tag === 'img' && ! $child->hasAttribute('src')) {
                $node->removeChild($child); // an image whose address was refused has nothing left to show
            }
        }
    }

    private static function cleanAttributes(\DOMElement $el, string $tag, array $p, string $profile, array &$removed): void
    {
        $allowed = array_merge($p['global'], $p['attrs'][$tag] ?? []);

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->name);
            if (! in_array($name, $allowed, true)) {
                $removed[] = $tag . ' ' . (str_starts_with($name, 'on') ? 'event handler ' : 'attribute ') . $name;
                $el->removeAttribute($attr->name);

                continue;
            }
            $value = trim($attr->value);

            if ($name === 'style') {
                $clean = self::cleanStyle($value, self::STYLE_PROPS[$profile]);
                if ($clean !== self::normaliseStyle($value)) {
                    $removed[] = $tag . ' style "' . mb_substr($value, 0, 60) . '"';
                }
                $clean === '' ? $el->removeAttribute('style') : $el->setAttribute('style', $clean);
            } elseif ($name === 'class') {
                $kept = array_values(array_intersect(preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [], $p['classes']));
                if (implode(' ', $kept) !== implode(' ', preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [])) {
                    $removed[] = $tag . ' class "' . mb_substr($value, 0, 40) . '"';
                }
                $kept ? $el->setAttribute('class', implode(' ', $kept)) : $el->removeAttribute('class');
            } elseif ($name === 'href') {
                if (self::isSafeUrl($value, $p['href'], $p['relative'], $profile === 'wording')) {
                    $el->setAttribute('href', $value);
                } else {
                    $removed[] = 'link address "' . mb_substr($value, 0, 60) . '"';
                    $el->removeAttribute('href');
                }
            } elseif ($name === 'src') {
                if (self::isSafeImage($value)) {
                    $el->setAttribute('src', $value);
                } else {
                    $removed[] = 'image source "' . mb_substr($value, 0, 60) . '"';
                    $el->removeAttribute('src');
                }
            } elseif ($name === 'target') {
                // Only _blank survives, and always with rel=noopener.
                if ($value === '_blank') {
                    $el->setAttribute('rel', 'noopener noreferrer');
                } else {
                    $el->removeAttribute('target');
                }
            } elseif (in_array($name, ['colspan', 'rowspan', 'span', 'start', 'width', 'height', 'cellpadding', 'cellspacing', 'border'], true)) {
                if (! preg_match('/^\d{1,4}%?$/', $value)) {
                    $removed[] = $tag . ' attribute ' . $name;
                    $el->removeAttribute($attr->name);
                }
            } elseif (in_array($name, ['align', 'valign'], true)) {
                if (! preg_match('/^(left|right|center|justify|top|middle|bottom|baseline)$/i', $value)) {
                    $removed[] = $tag . ' attribute ' . $name;
                    $el->removeAttribute($attr->name);
                }
            }
        }

        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /** Decode CSS escapes (`\75` → u, `\(` → `(`) and drop comments, so nothing can hide behind an escape sequence. */
    private static function decodeCss(string $style): string
    {
        $s = preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', function ($m) {
            $cp = hexdec($m[1]);

            return ($cp > 0 && $cp <= 0x10FFFF && ($cp < 0xD800 || $cp > 0xDFFF)) ? (string) mb_chr($cp, 'UTF-8') : '';
        }, $style);
        $s = preg_replace('/\\\\(.)/su', '$1', (string) $s);
        $s = preg_replace('#/\*.*?\*/#s', '', (string) $s);
        // An unterminated comment or a stray escape/NUL means the value is not something we understand.
        if (str_contains($s, '/*') || str_contains($s, '\\') || str_contains($s, "\0")) {
            return '';
        }

        return $s;
    }

    /** The comparison form of a style that needed no cleaning: "prop: value; prop: value". */
    private static function normaliseStyle(string $style): string
    {
        $out = [];
        foreach (explode(';', $style) as $decl) {
            if (! str_contains($decl, ':')) {
                continue;
            }
            [$prop, $val] = explode(':', $decl, 2);
            $out[] = strtolower(trim($prop)) . ': ' . trim($val);
        }

        return implode('; ', $out);
    }

    private static function cleanStyle(string $style, array $props): string
    {
        $decoded = self::decodeCss($style);
        $out = [];
        foreach (explode(';', $decoded) as $decl) {
            if (! str_contains($decl, ':')) {
                continue;
            }
            [$prop, $val] = explode(':', $decl, 2);
            $prop = strtolower(trim($prop));
            $val = trim(preg_replace('/\s*!important\s*$/i', '', trim($val)));
            if (! in_array($prop, $props, true) || $val === '' || ! self::isSafeCssValue($val)) {
                continue;
            }
            $out[] = $prop . ': ' . $val;
        }

        return implode('; ', $out);
    }

    private static function isSafeCssValue(string $v): bool
    {
        if (! preg_match('/^[A-Za-z0-9#%.,\s\-+\'"()\/]+$/u', $v)) {
            return false;
        }
        $probe = strtolower(preg_replace('/\s+/', '', $v));
        foreach (['url', 'expression', 'javascript', 'vbscript', 'image', 'behavior', 'binding', 'import', 'var(', 'attr(', 'env('] as $bad) {
            if (str_contains($probe, $bad)) {
                return false;
            }
        }
        if (preg_match_all('/([a-z\-]*)\s*\(/i', $v, $fn)) {
            foreach ($fn[1] as $name) {
                if (! in_array(strtolower($name), self::CSS_FUNCTIONS, true)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param string[] $schemes
     */
    private static function isSafeUrl(string $url, array $schemes, bool $allowRelative, bool $strict = false): bool
    {
        $probe = preg_replace('/[\x00-\x20\x7f-\x9f]+/u', '', $url);
        if ($probe === null || $probe === '') {
            return false;
        }
        if ($strict) {
            // Wording: an absolute http(s) / mailto / tel address, or an in-page "#anchor". Nothing relative, nothing exotic.
            return (bool) preg_match('~^(?:(?:' . implode('|', array_map('preg_quote', $schemes)) . '):[^\s<>"\']+|#[A-Za-z0-9_\-:.]*)$~i', $probe)
                && ! preg_match('~^[a-z][a-z0-9+.\-]*:(?://)?$~i', $probe);
        }
        // Anything in front of the first ":" (with no "/", "?" or "#" before it) is a scheme and must be allowed.
        if (preg_match('~^([^/?#]*?):~', $probe, $m)) {
            return in_array(strtolower($m[1]), $schemes, true);
        }

        return $allowRelative && ! str_starts_with($probe, '//');
    }

    /**
     * An <img src>: only the CoreX logo route (our own host) or an inline raster image of bounded size.
     * Any other http(s) address would be a tracking pixel / remote fetch from every viewer, so it is refused.
     */
    private static function isSafeImage(string $src): bool
    {
        $probe = preg_replace('/\s+/', '', $src);
        if (preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[A-Za-z0-9+/=]+$#i', $probe)) {
            return strlen($probe) <= self::MAX_DATA_IMAGE;
        }
        if (! preg_match('~^(?:(https?)://([^/?#]+))?(/platform-company/logo)(?:\?l=\d{1,9})?$~i', $probe, $m)) {
            return false;
        }
        if ($m[2] === '') {
            return true; // a relative address on our own host
        }
        $hosts = array_filter([parse_url((string) config('app.url'), PHP_URL_HOST), parse_url(url('/'), PHP_URL_HOST)]);
        $host = strtolower(preg_replace('/:\d+$/', '', $m[2]));

        return in_array($host, array_map('strtolower', $hosts), true);
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
