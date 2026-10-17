<?php

declare(strict_types=1);

namespace App\Services\AdTemplates;

/**
 * The AI Template Builder's built-in style library (AT-456 §B.9 item 10). The eight looks live in
 * config/ad-template-styles.php; this class reads them, checks them against the validator's own lists (a style that names a
 * font the validator would refuse, or a block that does not exist, is a bug caught by a test, not by the AI), and writes the
 * sentences the model is given.
 *
 * Nothing here is agency-specific: a style never brings colours of its own (it says HOW the agency's brand colours are used).
 */
final class AdTemplateStyles
{
    /** The value stored / posted for "Let the AI choose". */
    public const AUTO = 'auto';

    /** @return array<string, array<string, mixed>> key => style, in the order they appear in the panel. */
    public static function all(): array
    {
        return (array) config('ad-template-styles', []);
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, mixed>|null */
    public static function get(?string $key): ?array
    {
        return $key !== null ? (self::all()[$key] ?? null) : null;
    }

    /** What the panel needs: key, name, one-line summary. @return array<int, array{key: string, name: string, summary: string}> */
    public static function forPanel(): array
    {
        $out = [];
        foreach (self::all() as $key => $s) {
            $out[] = ['key' => (string) $key, 'name' => (string) $s['name'], 'summary' => (string) $s['summary']];
        }

        return $out;
    }

    /**
     * Turn whatever the model wrote after "style" ("Modern minimal", "modern_minimal", "LUXURY") into a real key, or null.
     */
    public static function resolveKey(mixed $named): ?string
    {
        if (! is_string($named) || trim($named) === '') {
            return null;
        }
        $norm = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($named)), '_');
        foreach (self::all() as $key => $s) {
            if ($norm === $key || $norm === trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $s['name'])), '_')) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Everything wrong with the library, in plain words (empty = fine). Fonts must exist in the validator's list, accents must
     * be a script face, sizes must be real ad sizes, and every "block" must be a real element type or a real property value.
     *
     * @return array<int, string>
     */
    public static function problems(): array
    {
        $out   = [];
        $fonts = AdLayoutValidator::fontNames();
        $all   = self::all();
        if (count($all) !== 8) {
            $out[] = 'The style library must have exactly 8 styles (has ' . count($all) . ').';
        }

        foreach ($all as $key => $s) {
            $t = "Style {$key}";
            if (! preg_match('/^[a-z][a-z0-9_]*$/', (string) $key) || $key === self::AUTO) {
                $out[] = "{$t}: the key must be lower_snake_case and not '" . self::AUTO . "'.";
            }
            foreach (['name', 'summary', 'brand', 'text', 'avoid'] as $k) {
                if (! is_string($s[$k] ?? null) || trim($s[$k]) === '') {
                    $out[] = "{$t}: \"{$k}\" is missing.";
                }
            }
            $pairs = $s['fonts'] ?? [];
            if (! is_array($pairs) || $pairs === []) {
                $out[] = "{$t}: needs at least one font pair.";
            }
            foreach ((array) $pairs as $pair) {
                foreach (['headline', 'body'] as $role) {
                    if (! in_array($pair[$role] ?? null, $fonts, true)) {
                        $out[] = "{$t}: the {$role} font '" . ($pair[$role] ?? '') . "' is not in the font list.";
                    }
                }
                foreach (['headline', 'body'] as $role) {
                    if (in_array($pair[$role] ?? null, AdLayoutValidator::scriptFontNames(), true)) {
                        $out[] = "{$t}: a script font cannot be a headline or body font (accent only).";
                    }
                }
            }
            if (isset($s['accent_script']) && ! in_array($s['accent_script'], AdLayoutValidator::scriptFontNames(), true)) {
                $out[] = "{$t}: accent_script must be a script font.";
            }
            $photo = $s['photo'] ?? [];
            if (! isset($photo['min'], $photo['max']) || $photo['min'] <= 0 || $photo['max'] > 1 || $photo['min'] >= $photo['max'] || trim((string) ($photo['placement'] ?? '')) === '') {
                $out[] = "{$t}: photo needs min < max (between 0 and 1) and a placement.";
            }
            if (($s['sizes'] ?? []) === [] || array_diff((array) ($s['sizes'] ?? []), array_keys(AdLayoutValidator::SIZES)) !== []) {
                $out[] = "{$t}: sizes must be a non-empty list of real ad sizes.";
            }
            foreach ((array) ($s['blocks'] ?? []) as $block) {
                if (! self::blockExists((string) $block)) {
                    $out[] = "{$t}: block '{$block}' is not a real element type or property value.";
                }
            }
            if (($s['blocks'] ?? []) === []) {
                $out[] = "{$t}: needs at least one suggested block.";
            }
        }

        return $out;
    }

    private static function blockExists(string $block): bool
    {
        if (! str_contains($block, '=')) {
            return isset(AdLayoutValidator::FIELDS[$block]);
        }
        [$prop, $value] = explode('=', $block, 2);
        if (($enum = AdLayoutValidator::enumValues($prop)) !== null) {
            return in_array($value, $enum, true);
        }
        if (($range = AdLayoutValidator::numberRange($prop)) !== null) {
            return is_numeric($value) && $value >= $range[0] && $value <= $range[1];
        }

        return false;
    }

    /** One style in full, as the instructions for "every option uses this style". */
    public static function chosenBlock(string $key, string $sizeKey): string
    {
        $s = self::get($key);
        if ($s === null) {
            return '';
        }
        $size = AdLayoutValidator::SIZES[$sizeKey]['label'] ?? $sizeKey;
        $out  = "STYLE - the user picked \"{$s['name']}\" and ALL options must be in this style (they still differ from each other in layout, not in style):\n"
            . "- Look: {$s['summary']}\n"
            . '- Fonts: ' . self::fontLine($s) . "\n"
            . "- Brand colours: {$s['brand']}\n"
            . "- Photo: it takes about " . (int) round($s['photo']['min'] * 100) . '% to ' . (int) round($s['photo']['max'] * 100) . "% of the canvas area; {$s['photo']['placement']}.\n"
            . "- Text: {$s['text']}\n"
            . '- Blocks that suit it: ' . implode(', ', $s['blocks']) . ".\n"
            . "- Keep out: {$s['avoid']}\n";
        if (! in_array($sizeKey, (array) $s['sizes'], true)) {
            $out .= "- Note: this style is usually made for " . implode(' / ', array_map(fn ($k) => AdLayoutValidator::SIZES[$k]['label'], (array) $s['sizes'])) . "; adapt it carefully to the requested {$size}.\n";
        }

        return $out;
    }

    /** All eight, compactly, for "Let the AI choose". */
    public static function catalogueBlock(string $sizeKey): string
    {
        $out = "STYLE - the user left the style to you. Choose, for EACH option, the one style below that best fits the idea. Different options may (and usually should) use different styles. Name the style you used in that option as \"style\": \"<key>\" (the key before the colon). Follow that style's fonts, brand-colour rule and photo share for that option.\n";
        foreach (self::all() as $key => $s) {
            $suits = in_array($sizeKey, (array) $s['sizes'], true) ? '' : ' (not usually made for this ad size)';
            $out  .= "- {$key}: {$s['name']}. {$s['summary']} Fonts: " . self::fontLine($s) . " Brand colours: {$s['brand']} Photo: "
                . (int) round($s['photo']['min'] * 100) . '-' . (int) round($s['photo']['max'] * 100) . '% of the canvas. Suits: ' . implode(', ', $s['blocks']) . ".{$suits}\n";
        }

        return $out;
    }

    /** @param array<string, mixed> $s */
    private static function fontLine(array $s): string
    {
        $pairs = [];
        foreach ((array) $s['fonts'] as $p) {
            $pairs[] = "{$p['headline']} (headlines) with {$p['body']} (body)";
        }
        $line = implode(', or ', $pairs) . '.';
        if (! empty($s['accent_script'])) {
            $line .= " One short headline phrase may use {$s['accent_script']}.";
        }

        return $line;
    }
}
