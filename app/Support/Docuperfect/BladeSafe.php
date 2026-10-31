<?php

namespace App\Support\Docuperfect;

/**
 * Makes untrusted document text inert before it is written into a generated Blade view.
 *
 * TemplateController::generateCdsBladeView() writes the document body (imported from Word, or edited in the
 * builder) into resources/views/docuperfect/web-templates/cds/template-<id>.blade.php, which Blade then
 * compiles to PHP. HTML escaping (e()) does not stop Blade: `{{ }}`, `{!! !!}`, `@php`, `@include` and a raw
 * `<?php` tag in a Word paragraph would all execute. Call neutralise() ONCE on the untrusted HTML, BEFORE the
 * generator inserts its own trusted `{{ $var }}` merge-field expressions and `@include` directives, so those
 * still work while everything the document author typed prints literally.
 */
final class BladeSafe
{
    /**
     * Blade syntax in markup/text becomes HTML entities (they print the same character, nothing compiles).
     * <style>/<script> bodies do not decode entities, so there the `@` is escaped Blade-style (`@@`) instead.
     */
    public static function neutralise(string $html): string
    {
        $parts = preg_split('#(<(?:style|script)\b.*?</(?:style|script)\s*>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            $parts = [$html];
        }

        $out = '';
        foreach ($parts as $i => $part) {
            $part = str_replace(['{{', '{!!', '<?'], ['&#123;&#123;', '&#123;!!', '&lt;?'], $part);
            $part = $i % 2 === 1
                ? preg_replace('/(?<![\w@])@(?=\w)/', '@@', $part)
                : str_replace('@', '&#64;', $part);
            $out .= $part;
        }

        return $out;
    }

    /** A single value (a title, an attribute) going into the generated view. */
    public static function neutraliseText(string $text): string
    {
        return str_replace(['{{', '{!!', '<?', '@'], ['&#123;&#123;', '&#123;!!', '&lt;?', '&#64;'], $text);
    }
}
