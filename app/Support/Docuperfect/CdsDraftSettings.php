<?php

namespace App\Support\Docuperfect;

use App\Models\Docuperfect\Template;

/**
 * The builder settings a CDS draft is seeded with from an existing template.
 *
 * One place, used by every way of opening a builder session on a saved template (plain edit and
 * Re-import from Word), so the two can never drift. Every key the builder POSTs on save
 * (cds-builder.blade.php _doSaveDraft settings{}) must be seeded here, or the reopened builder
 * shows its defaults instead of what was saved.
 */
final class CdsDraftSettings
{
    /** @return array<string,mixed> */
    public static function fromTemplate(Template $template): array
    {
        $editor = is_array($template->editor_state) ? $template->editor_state : [];

        return [
            'is_esign' => $template->is_esign,
            'party_mode' => $template->party_mode,
            'allowed_delivery_modes' => $template->allowed_delivery_modes,
            'security_tier' => $template->security_tier,
            'signing_parties' => $template->signing_parties,
            'category' => $template->category,
            'document_type_id' => $template->document_type_id,
            'document_look' => DocumentLook::fromState($template->editor_state),
        ]
            // Carried only when the template has one: a template that never had the setting lets the next save choose its default
            // ("from document" when the document brings its own signature places).
            + (array_key_exists('signature_block', $editor) ? ['signature_block' => \App\Support\Docuperfect\SignatureBlock::fromState($editor)] : []);
    }
}
