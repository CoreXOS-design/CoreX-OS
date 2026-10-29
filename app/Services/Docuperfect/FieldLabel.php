<?php

namespace App\Services\Docuperfect;

/**
 * esign-lease-field-source.md - a name for an unrecognised blank, taken from the words just before it.
 *
 * The importer's last resort used the trailing letters of the text before the blank as its label. That produced "s Assist Fee"
 * (the apostrophe ended the run) and sentence fragments such as "The Rental shall be" or "Adults and not more than". A label is
 * a short noun phrase ("Date of birth", "Net Amount to Owner:"); anything that reads as the middle of a sentence is not one, and
 * the blank stays unnamed ("Input") for the person - or the AI button - to name.
 */
class FieldLabel
{
    public static function fromPrecedingText(string $before): ?string
    {
        $t = str_replace(["\u{2018}", "\u{2019}", "\u{02BC}"], "'", $before);
        if (! preg_match("/([A-Za-z' ]+?):?\s*$/u", $t, $m)) {
            return null;
        }
        $label = trim((string) preg_replace('/\s+/', ' ', $m[1]), " '");
        $hadColon = (bool) preg_match('/:\s*$/', $t);

        if (strlen($label) < 3 || strlen($label) > 40 || ! preg_match('/^[A-Za-z]/', $label)) {
            return null;
        }
        $words = explode(' ', $label);
        if (count($words) > 5) {
            return null;
        }
        // The middle of a sentence: a verb or a joining word that no label contains.
        if (preg_match('/\b(shall|will|may|must|be|is|are|was|been|has|have|not|than|which|that)\b/i', $label)) {
            return null;
        }
        // A label ending on a joining word is cut off ("... of", "... and"); a colon after it says it is complete.
        if (! $hadColon && preg_match('/\b(of|to|and|or|by|on|at|for|in|the|a|an|as|with|from)$/i', $label)) {
            return null;
        }

        return $label;
    }
}
