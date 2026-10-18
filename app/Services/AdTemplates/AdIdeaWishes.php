<?php

namespace App\Services\AdTemplates;

/**
 * What the user's own idea text forbids about the look, made binding (.ai/specs/ad-manager.md, .ai/specs/company-ads.md §8).
 *
 * The idea is otherwise only a design brief for the model, and the style library and the prompt both suggest icons ("icons
 * before the numbers") - so a typed "no icons" lost to them. Now: when the idea asks for no icons, the prompt says so as a hard
 * rule AND every design is cleaned on the server, so not one icon reaches an ad whatever the model answers. Used by both AI
 * builders (property templates and company ads).
 */
class AdIdeaWishes
{
    /** The fact elements that print a bare number ("3") unless numberFormat is "label" ("3 Bedrooms"). */
    private const COUNT_FIELDS = ['beds', 'baths', 'garages', 'parking', 'garages_or_parking'];

    /**
     * Phrasings of "no icons" (English and Afrikaans). A negation word close before "icon(s)", or "icon-free" / "icons are not
     * needed" after it. "Don't forget the icons" is not a ban: the verb after "don't" must be one of use/add/put/...
     */
    private const NO_ICON_PATTERNS = [
        '/\b(?:no|without|zero|avoid|remove|drop|skip|exclude|ditch|minus|lose|leave\s+out|get\s+rid\s+of|take\s+out|strip)\s+(?:any\s+|all\s+|the\s+|of\s+the\s+|those\s+|these\s+)*(?:[a-z\-]+\s+)?icons?\b/iu',
        '/\b(?:do\s*n[o\']?t|dont|never|please\s+do\s*n[o\']?t)\s+(?:use|add|put|include|show|want|need|have|place|draw|display)\s+(?:any\s+|the\s+|in\s+)*(?:[a-z\-]+\s+)?icons?\b/iu',
        '/\bnot?\s+(?:a\s+single\s+|one\s+)icon\b/iu',
        '/\bicons?[\s\-]*free\b/iu',
        '/\bicons?\s+(?:are\s+|is\s+)?(?:not\s+(?:needed|wanted|required|allowed|necessary)|unnecessary|forbidden|banned)\b/iu',
        '/\b(?:geen|sonder|nie)\s+(?:[a-z\-]+\s+)?ikon(?:e|s)?\b/iu',
    ];

    /** True when the idea asks for an ad without icons. */
    public static function forbidsIcons(?string $idea): bool
    {
        $idea = trim((string) $idea);
        if ($idea === '') {
            return false;
        }
        foreach (self::NO_ICON_PATTERNS as $pattern) {
            if (preg_match($pattern, $idea) === 1) {
                return true;
            }
        }

        return false;
    }

    /** The hard rule for the prompt (empty when the idea forbids nothing). */
    public static function promptBlock(?string $idea): string
    {
        return self::forbidsIcons($idea)
            ? "\nTHE USER ASKED FOR NO ICONS. This is binding and beats every style and suggestion: never set \"icon\" or \"iconSize\" on any element. "
              . "Show bedrooms, bathrooms, garages and parking with numberFormat \"label\" (\"3 Bedrooms\") and keep their labels (showLabel true).\n"
            : '';
    }

    /**
     * Take every icon off ONE design (a raw answer option or a validated layout - both keep their elements under `elements`).
     * A count that leaned on its icon to say what it is gets its words back (numberFormat "label", showLabel true), so a
     * lone "3" never ends up on the ad. Returns the cleaned design and one plain-words note when anything was removed.
     *
     * @param  array<string, mixed> $design
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    public static function stripIcons(array $design): array
    {
        $elements = $design['elements'] ?? null;
        if (! is_array($elements)) {
            return [$design, []];
        }
        $removed = 0;
        foreach ($elements as $i => $el) {
            if (! is_array($el) || ! isset($el['icon']) || $el['icon'] === '' || $el['icon'] === null) {
                if (is_array($el) && array_key_exists('iconSize', $el) && $el['iconSize'] !== null) {
                    $el['iconSize']  = null;
                    $elements[$i]    = $el;
                }
                continue;
            }
            $el['icon']     = null;
            $el['iconSize'] = null;
            if (in_array(strtolower((string) ($el['field'] ?? '')), self::COUNT_FIELDS, true)) {
                $el['numberFormat'] = 'label';
            }
            if (array_key_exists('showLabel', $el)) {
                $el['showLabel'] = true;
            }
            $elements[$i] = $el;
            $removed++;
        }
        $design['elements'] = $elements;

        return [$design, $removed > 0 ? ["Removed {$removed} icon" . ($removed === 1 ? '' : 's') . ' - the idea asked for no icons.'] : []];
    }
}
