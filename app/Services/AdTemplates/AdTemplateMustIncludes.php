<?php

declare(strict_types=1);

namespace App\Services\AdTemplates;

/**
 * The "must include" tick boxes of the Create with AI pop-up (AT-456 §B.10).
 *
 * One definition per option: its plain label, the group it sits in on the screen, which element types satisfy it,
 * and the sentence the AI is given as a hard requirement. The same table drives the screen, the prompt, the
 * server-side check and the "Missing: ..." line on a card, so the four can never disagree.
 *
 * "Satisfied" is decided by the program on the VALIDATED layout, never by trusting what the model says it did.
 * A hidden element never counts.
 */
final class AdTemplateMustIncludes
{
    public const BIG_PRICE_MIN_FONT = 48;
    public const RIBBON_KEY         = 'ribbon';

    /**
     * Key => definition. `needs` is a list of alternatives; each alternative is a list of element types that must ALL be
     * present (so [['agent_avatar', 'agent_phone'], ['agent_avatar', 'agent_email']] = the avatar AND (phone OR email)).
     */
    private const OPTIONS = [
        'big_price' => [
            'label' => 'Big price', 'group' => 'info',
            'needs' => [['price']],
            'ask'   => 'a "price" element with fontSize of at least ' . self::BIG_PRICE_MIN_FONT . ' (the price is the hero of the ad)',
        ],
        'show_day' => [
            'label' => 'Show day date', 'group' => 'info',
            'needs' => [['show_day']],
            'ask'   => 'a "show_day" element (the next show day with its times)',
        ],
        'agent_contact' => [
            'label' => 'Agent photo and contact', 'group' => 'info',
            'needs' => [['agent_avatar', 'agent_phone'], ['agent_avatar', 'agent_email']],
            'ask'   => 'the agent photo ("agent_avatar") together with the agent\'s phone ("agent_phone") or email ("agent_email")',
        ],
        'logo' => [
            'label' => 'Agency logo', 'group' => 'info',
            'needs' => [['logo'], ['agency_logo']],
            'ask'   => 'the agency logo (a "logo" element - it shows the agency name as text when no logo file is on file - or "agency_logo")',
        ],
        'feature_pills' => [
            'label' => 'Key features as pills', 'group' => 'info',
            'needs' => [['feature_pills']],
            'ask'   => 'a "feature_pills" element (the listing\'s key features as separate pills)',
        ],
        'qr' => [
            'label' => 'QR code to the listing', 'group' => 'extras',
            'needs' => [['qr']],
            'ask'   => 'a "qr" element (a scannable code to the listing\'s web page, large enough to scan)',
        ],
        'collage' => [
            'label' => 'Photo collage', 'group' => 'extras',
            'needs' => [['collage']],
            'ask'   => 'a "collage" element (several of the listing\'s photos together)',
        ],
        self::RIBBON_KEY => [
            'label' => 'Corner ribbon', 'group' => 'extras',
            'needs' => [['ribbon']],
            'ask'   => 'a "ribbon" element carrying exactly the words given below',
        ],
    ];

    private const GROUPS = ['info' => 'Information to show', 'extras' => 'Design extras'];

    /** @return array<int, string> every valid key, in screen order */
    public static function keys(): array
    {
        return array_keys(self::OPTIONS);
    }

    public static function label(string $key): string
    {
        return self::OPTIONS[$key]['label'] ?? $key;
    }

    /**
     * The options for the pop-up, grouped, in screen order.
     *
     * @return array<int, array{name: string, options: array<int, array{key: string, label: string}>}>
     */
    public static function forPanel(): array
    {
        $out = [];
        foreach (self::GROUPS as $gKey => $gName) {
            $options = [];
            foreach (self::OPTIONS as $key => $def) {
                if ($def['group'] === $gKey) {
                    $options[] = ['key' => $key, 'label' => $def['label']];
                }
            }
            $out[] = ['name' => $gName, 'options' => $options];
        }

        return $out;
    }

    /**
     * Clean a submitted selection: only known keys, each once, in screen order.
     *
     * @param  array<int, mixed> $keys
     * @return array<int, string>
     */
    public static function sanitiseKeys(array $keys): array
    {
        $given = array_map(static fn ($k) => is_string($k) ? trim($k) : '', $keys);

        return array_values(array_filter(self::keys(), static fn (string $k) => in_array($k, $given, true)));
    }

    /**
     * The ribbon's words as they will be stored and drawn: plain text (markup stripped, control characters removed,
     * spaces collapsed) and capped. The kernel escapes text when it draws it, so what is kept here is the words and nothing else.
     */
    public static function cleanRibbonText(?string $text): string
    {
        $t = strip_tags((string) $text);
        $t = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $t);
        $t = trim((string) preg_replace('/\s+/u', ' ', $t));

        return mb_substr($t, 0, AdLayoutValidator::MAX_RIBBON_TEXT);
    }

    /**
     * The labels of the ticked options this layout does NOT contain (empty = it has everything).
     *
     * @param  array<string, mixed> $layout     a validated layout (its `elements`)
     * @param  array<int, string>   $keys       the ticked keys
     * @return array<int, string>
     */
    public static function missing(array $layout, array $keys, string $ribbonText = ''): array
    {
        $elements = array_values(array_filter((array) ($layout['elements'] ?? []), 'is_array'));
        $present  = [];
        foreach ($elements as $el) {
            if (! empty($el['hidden'])) {
                continue;
            }
            $present[(string) ($el['field'] ?? '')][] = $el;
        }

        $missing = [];
        foreach ($keys as $key) {
            if (! isset(self::OPTIONS[$key])) {
                continue;
            }
            if (! self::satisfied($key, $present, $ribbonText)) {
                $missing[] = self::OPTIONS[$key]['label'];
            }
        }

        return $missing;
    }

    /**
     * The hard-requirement lines for the prompt ('' when nothing is ticked).
     *
     * @param array<int, string> $keys
     */
    public static function promptBlock(array $keys, string $ribbonText = ''): string
    {
        if ($keys === []) {
            return '';
        }

        $lines = [];
        foreach ($keys as $key) {
            if (! isset(self::OPTIONS[$key])) {
                continue;
            }
            $ask = self::OPTIONS[$key]['ask'];
            if ($key === self::RIBBON_KEY) {
                $ask .= ': ' . json_encode($ribbonText, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' (use these exact words as the ribbon "text", nothing added or changed)';
            }
            $lines[] = '- ' . $ask;
        }

        return "\nMUST INCLUDE - the user ticked these, so EVERY option you return must contain each one (a program checks this; an option without them is sent back):\n"
            . implode("\n", $lines) . "\n"
            . "Fit them into a design that still looks good; they replace nothing else you would normally include.\n";
    }

    /**
     * Put the user's own ribbon words into every ribbon element of a raw option, before it is validated (the AI's own
     * wording is never trusted for what the user typed).
     *
     * @param  array<string, mixed> $option
     * @return array<string, mixed>
     */
    public static function withRibbonText(array $option, string $ribbonText): array
    {
        if ($ribbonText === '' || ! is_array($option['elements'] ?? null)) {
            return $option;
        }
        foreach ($option['elements'] as $i => $el) {
            if (is_array($el) && ($el['field'] ?? null) === 'ribbon') {
                $option['elements'][$i]['text'] = $ribbonText;
            }
        }

        return $option;
    }

    /** @param array<string, array<int, array<string, mixed>>> $present element type => elements of that type (visible only) */
    private static function satisfied(string $key, array $present, string $ribbonText): bool
    {
        $def = self::OPTIONS[$key];

        if ($key === 'big_price') {
            foreach ($present['price'] ?? [] as $el) {
                if ((float) ($el['fontSize'] ?? 0) >= self::BIG_PRICE_MIN_FONT) {
                    return true;
                }
            }

            return false;
        }
        if ($key === self::RIBBON_KEY) {
            foreach ($present['ribbon'] ?? [] as $el) {
                if ((string) ($el['text'] ?? '') === $ribbonText && $ribbonText !== '') {
                    return true;
                }
            }

            return false;
        }

        foreach ($def['needs'] as $alternative) {
            $all = true;
            foreach ($alternative as $type) {
                if (empty($present[$type])) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }
}
