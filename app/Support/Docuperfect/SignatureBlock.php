<?php

namespace App\Support\Docuperfect;

/**
 * A CDS template's "Signature block" (Template Settings in the builder).
 *
 *   corex    - CoreX standard: the generated page ends with CoreX's own Lessor / Lessee / Agent signature block
 *              (the `signature-block` component). The default, so a template with no stored value is unchanged.
 *   document - from document: the Word file carries its own signature table with signature / date places, and the page is
 *              NOT followed by CoreX's generic block (nothing printed twice, nobody signs in two places).
 *
 * Stored per template in editor_state['signature_block'] (and the builder draft's settings), beside the document look.
 * Honoured by TemplateController::generateCdsBladeView(), so the signing page, the signed PDF and the print-and-sign PDF -
 * which all render the compiled page - agree. Default on the FIRST save of a template (created from a Word import) where the person has not chosen: "document" when the
 * saved document has signature places of its own, otherwise "corex". An existing template with no stored value stays "corex".
 */
final class SignatureBlock
{
    public const COREX = 'corex';

    public const DOCUMENT = 'document';

    /** Anything that is not a known value is the CoreX standard block - never an error. */
    public static function normalise(mixed $value): string
    {
        return $value === self::DOCUMENT ? self::DOCUMENT : self::COREX;
    }

    public static function fromState(mixed $state): string
    {
        return self::normalise(is_array($state) ? ($state['signature_block'] ?? null) : null);
    }

    /** True when the saved builder HTML already carries signature places of its own (a signature tag in the document). */
    public static function documentHasSignaturePlaces(?string $taggedHtml): bool
    {
        return $taggedHtml !== null && preg_match('/data-tag-type="signature"|doc-tag-signature/', $taggedHtml) === 1;
    }

    /**
     * The value to store: the person's choice when they made one, else the default described above.
     *
     * The signature-places default applies only to a template being CREATED from a Word import. An EXISTING template with no
     * stored value (everything saved before this setting existed, live leases included) keeps the previous behaviour - the CoreX
     * block - so a routine re-save never silently drops its Lessor / Lessee / Agent block.
     */
    public static function resolve(mixed $chosen, ?string $taggedHtml, bool $isNewTemplate = true): string
    {
        if ($chosen === self::DOCUMENT || $chosen === self::COREX) {
            return $chosen;
        }

        return $isNewTemplate && self::documentHasSignaturePlaces($taggedHtml) ? self::DOCUMENT : self::COREX;
    }
}
