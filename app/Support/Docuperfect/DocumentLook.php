<?php

namespace App\Support\Docuperfect;

/**
 * A CDS template's "Document look" (Template Settings in the builder).
 *
 *   standard — the CoreX house style. The default; a template with no stored look is standard,
 *              so nothing changes for any template that existed before this setting.
 *   source   — "Match source document": the Word source's paragraph alignment, centred
 *              titles, grey section bands and greyscale body colours.
 *
 * Stored per template in editor_state['document_look'] (and the builder draft's settings).
 * The look is applied by ONE CSS class on the document wrapper (CLASS_SOURCE, rules in
 * public/css/corex-document.css), so builder preview, signing view and signed PDF — which all
 * load that stylesheet — cannot drift apart.
 */
final class DocumentLook
{
    public const STANDARD = 'standard';

    public const SOURCE = 'source';

    /** Wrapper class that switches the "Match source document" rules on. */
    public const CLASS_SOURCE = 'corex-look-source';

    /** Anything that is not a known look is the standard look — never an error. */
    public static function normalise(mixed $value): string
    {
        return $value === self::SOURCE ? self::SOURCE : self::STANDARD;
    }

    /** The look stored on a template's / draft's state array. */
    public static function fromState(mixed $state): string
    {
        return self::normalise(is_array($state) ? ($state['document_look'] ?? null) : null);
    }

    /** The extra wrapper class for a look ('' for standard). */
    public static function wrapperClass(string $look): string
    {
        return self::normalise($look) === self::SOURCE ? ' ' . self::CLASS_SOURCE : '';
    }
}
