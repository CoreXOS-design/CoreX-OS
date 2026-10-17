<?php

declare(strict_types=1);

namespace App\Services\AdTemplates;

/**
 * Server-side gate between what the AI wrote and what a user is ever shown or can save
 * (AT-456 Part B, spec §B.3). The model's output is untrusted text: nothing reaches a
 * preview, the database or the Ad Builder unless it passes here.
 *
 * What it enforces
 *   - only known element types, only known properties (everything else is dropped);
 *   - numeric bounds on every number, and every element fully inside the canvas;
 *   - a minimum font size on every piece of text;
 *   - a contrast check of each text element against what is actually behind it;
 *   - strict colour syntax. This is a security rule, not polish: the render kernel
 *     concatenates colours straight into a style="" attribute, so a colour that is not
 *     rgb()/rgba()/hex is a CSS/HTML injection vector and is refused outright;
 *   - literal copy (custom text / badge) is length-limited and stripped of markup.
 *
 * What it returns is a COMPLETE layout_json — the same element shape the Ad Builder's own
 * makeElement() produces — so a saved AI template opens and edits like any other template.
 * The model is only asked for the properties that matter; the inert ones are filled here.
 *
 * Contrast is a heuristic, not a renderer: text is checked against the canvas, the nearest
 * covering block/shape/gradient beneath it, or its own pill. Text sitting straight on a
 * property photo with no scrim is refused unless it has a text shadow (a photo's pixels are
 * unknowable here). A gradient is judged by its opaque stops.
 *
 * Canvas sizes mirror CANVAS_PRESETS in public/js/corex-ad-render.js; a test fails if they drift.
 */
final class AdLayoutValidator
{
    /** Ad sizes the builder offers. Mirrors CANVAS_PRESETS in the render kernel. */
    public const SIZES = [
        'facebook'  => ['w' => 1200, 'h' => 628,  'label' => 'Facebook (1200×628)'],
        'instagram' => ['w' => 1080, 'h' => 1080, 'label' => 'Instagram (1080×1080)'],
        'story'     => ['w' => 1080, 'h' => 1920, 'label' => 'Story (1080×1920)'],
        'whatsapp'  => ['w' => 900,  'h' => 900,  'label' => 'WhatsApp (900×900)'],
    ];

    public const MIN_FONT_SIZE = 16;
    public const MIN_CONTRAST  = 3.0;
    public const MIN_ELEMENTS  = 3;
    public const MAX_ELEMENTS  = 40;
    public const MAX_TEXT_LEN  = 80;
    public const MAX_NAME_LEN  = 60;

    /** Tolerance (px) for an element a hair past the canvas edge. */
    private const EDGE_TOLERANCE = 2;

    /** Fields that show a property photo or logo. */
    private const IMAGE_FIELDS = ['image_1', 'image_2', 'image_3', 'image_4', 'image_5', 'agent_avatar', 'agency_logo'];

    /** Fields that draw a block of colour and carry no text. */
    private const BLOCK_FIELDS = ['color_block', 'shape', 'gradient', 'line'];

    /**
     * Every element type the AI may use, with a one-line description (also fed to the prompt,
     * so the prompt and the validator can never disagree). Agent 2, custom image and custom
     * video are deliberately left out: a single listing may have no second agent, and the AI
     * has no media to put in a custom slot.
     */
    public const FIELDS = [
        'image_1'           => ['label' => 'Image 1',               'help' => 'main property photo'],
        'image_2'           => ['label' => 'Image 2',               'help' => 'second property photo'],
        'image_3'           => ['label' => 'Image 3',               'help' => 'third property photo'],
        'image_4'           => ['label' => 'Image 4',               'help' => 'fourth property photo'],
        'image_5'           => ['label' => 'Image 5',               'help' => 'fifth property photo'],
        'price'             => ['label' => 'Price',                 'help' => 'asking price, e.g. R 2 450 000'],
        'title'             => ['label' => 'Title',                 'help' => 'listing headline, up to ~40 characters'],
        'suburb'            => ['label' => 'Suburb',                'help' => 'suburb and city'],
        'property_type'     => ['label' => 'Type',                  'help' => 'e.g. HOUSE, APARTMENT'],
        'features'          => ['label' => 'Features',              'help' => 'one line like "4 Bed · 3 Bath · 2 Garage"'],
        'beds'              => ['label' => 'Beds',                  'help' => 'bedroom count'],
        'baths'             => ['label' => 'Baths',                 'help' => 'bathroom count'],
        'garages'           => ['label' => 'Garages',               'help' => 'garage count'],
        'parking'           => ['label' => 'Parking',               'help' => 'parking bays'],
        'garages_or_parking' => ['label' => 'Garages / Parking',    'help' => 'garages, else parking'],
        'size_m2'           => ['label' => 'Size m²',               'help' => 'floor size'],
        'size_or_land'      => ['label' => 'Size / Land Size',      'help' => 'floor size, else erf size'],
        'reference'         => ['label' => 'Reference',             'help' => 'listing reference number'],
        'address'           => ['label' => 'Address',               'help' => 'street address'],
        'status_badge'      => ['label' => 'Status Badge',          'help' => 'FOR SALE / TO LET / SOLD, coloured pill'],
        'agent_name'        => ['label' => 'Agent 1 · Name',        'help' => 'the listing agent'],
        'agent_email'       => ['label' => 'Agent 1 · Email',       'help' => 'agent email'],
        'agent_phone'       => ['label' => 'Agent 1 · Phone',       'help' => 'agent phone'],
        'agent_designation' => ['label' => 'Agent 1 · Designation', 'help' => 'e.g. Property Practitioner'],
        'agent_avatar'      => ['label' => 'Agent 1 · Image',       'help' => 'agent photo (use shapeType circle/rounded)'],
        'agency_name'       => ['label' => 'Agency Name',           'help' => 'the agency name'],
        'website'           => ['label' => 'Website',               'help' => 'agency website'],
        'logo'              => ['label' => 'CoreX / Agency Logo',   'help' => 'the agency logo (falls back to the agency name as text)'],
        'agency_logo'       => ['label' => 'Agency Logo (image)',   'help' => 'the agency logo image'],
        'watermark'         => ['label' => 'Watermark',             'help' => 'huge faint agency name behind everything (set opacity ≤ 0.1)'],
        'custom_text'       => ['label' => 'Custom Text',           'help' => 'your own words — needs "text"'],
        'badge'             => ['label' => 'Badge / Pill',          'help' => 'short coloured label like JUST LISTED — needs "text"'],
        'line'              => ['label' => 'Divider Line',          'help' => 'thin rule; colour in "color", thickness in "borderWidth"'],
        'shape'             => ['label' => 'Shape',                 'help' => 'filled shape; colour in "bg", kind in "shapeType"'],
        'gradient'          => ['label' => 'Gradient',              'help' => 'fade; "gradFrom" → "gradTo" at "gradAngle" (use as a scrim over photos)'],
        'color_block'       => ['label' => 'Color Block',           'help' => 'solid rectangle; colour in "bg"'],
    ];

    private const SHAPES   = ['rectangle', 'rounded', 'circle', 'pill', 'triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'chevron'];
    /** Mirrors FONTS in the render kernel (and _ad-fonts.blade.php). */
    private const FONTS    = ['Figtree', 'Inter', 'Poppins', 'Montserrat', 'Oswald', 'Bebas Neue', 'Playfair Display', 'Lora'];
    private const WEIGHTS  = ['400', '500', '600', '700', '800', '900'];
    private const ICONS    = ['bed', 'bath', 'garage', 'parking', 'ruler', 'house', 'pin', 'key', 'pool', 'tree', 'door', 'tag'];

    /** Design-time sample copy the builder shows in an empty slot (mirrors the kernel's `preview`). */
    private const PREVIEWS = [
        'features' => '4 Bed · 3 Bath · 2 Garage', 'beds' => '4', 'baths' => '3', 'garages' => '2', 'parking' => '2',
        'garages_or_parking' => '2', 'size_m2' => '450 m²', 'size_or_land' => '450 M²', 'reference' => 'REF 12345',
        'address' => '12 Marine Drive', 'status_badge' => 'FOR SALE', 'agent_phone' => '082 000 0000',
        'website' => 'WWW.AGENCY.CO.ZA',
    ];

    /**
     * The ordered list of allowed numeric properties: key => [min, max, decimals].
     */
    private const NUMBERS = [
        'rotation' => [-180, 180, 1], 'fontSize' => [1, 600, 0], 'letterSpacing' => [-0.2, 1, 3], 'lineHeight' => [0.8, 2.5, 2],
        'padding' => [0, 120, 0], 'elOpacity' => [0, 1, 2], 'bgOpacity' => [0, 1, 2], 'opacity' => [0, 1, 2],
        'borderRadius' => [0, 1000, 0], 'gradAngle' => [0, 360, 0], 'borderWidth' => [0, 60, 0], 'frameBorderWidth' => [0, 40, 0],
        'shadowX' => [-100, 100, 0], 'shadowY' => [-100, 100, 0], 'shadowBlur' => [0, 100, 0], 'shadowOpacity' => [0, 1, 2],
        'iconSize' => [8, 200, 0],
    ];

    private const COLOR_KEYS = ['color', 'bgColor', 'bg', 'gradFrom', 'gradTo', 'frameBorderColor', 'shadowColor'];

    private const ENUMS = [
        'fontFamily'    => self::FONTS,
        'fontWeight'    => self::WEIGHTS,
        'textAlign'     => ['left', 'center', 'right'],
        'verticalAlign' => ['top', 'middle', 'bottom'],
        'textTransform' => ['none', 'uppercase', 'capitalize'],
        'objectFit'     => ['cover', 'contain'],
        'shapeType'     => self::SHAPES,
        'numberFormat'  => ['number', 'label'],
        'icon'          => self::ICONS,
    ];

    /**
     * Validate one option (a name + a canvas + elements) as the model wrote it.
     *
     * @param  array<string, mixed> $option  Decoded JSON from the model.
     * @return array{ok: bool, name: string, layout: ?array<string, mixed>, errors: array<int, string>}
     */
    public function validate(array $option, string $sizeKey): array
    {
        $errors = [];
        $size   = self::SIZES[$sizeKey] ?? null;
        if ($size === null) {
            return ['ok' => false, 'name' => '', 'layout' => null, 'errors' => ["Unknown ad size '{$sizeKey}'."]];
        }
        $cw = $size['w'];
        $ch = $size['h'];

        $name = trim(strip_tags((string) ($option['name'] ?? '')));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));
        $name = mb_substr($name, 0, self::MAX_NAME_LEN);
        if ($name === '') {
            $name = 'AI template';
        }

        // ── Canvas background ───────────────────────────────────────────────
        $mode = in_array($option['canvasBgMode'] ?? 'solid', ['solid', 'gradient'], true) ? ($option['canvasBgMode'] ?? 'solid') : 'solid';
        $canvasBg   = $this->colorOr($option['canvasBg'] ?? null, '#111827', 'canvasBg', $errors);
        $canvasFrom = $this->colorOr($option['canvasBgFrom'] ?? null, $canvasBg, 'canvasBgFrom', $errors);
        $canvasTo   = $this->colorOr($option['canvasBgTo'] ?? null, $canvasBg, 'canvasBgTo', $errors);
        $canvasAng  = $this->number($option['canvasBgAngle'] ?? 160, 0, 360, 0, 'canvasBgAngle', $errors);
        foreach ([$canvasBg, $canvasFrom, $canvasTo] as $c) {
            $p = $this->parseColor($c);
            if ($p !== null && $p[3] < 1.0) {
                $errors[] = 'Canvas background colours must be fully opaque.';
                break;
            }
        }
        $canvasStops = $mode === 'gradient' ? [$canvasFrom, $canvasTo] : [$canvasBg];

        // ── Elements ────────────────────────────────────────────────────────
        $rawElements = $option['elements'] ?? null;
        if (! is_array($rawElements) || ! array_is_list($rawElements)) {
            return ['ok' => false, 'name' => $name, 'layout' => null, 'errors' => array_merge($errors, ['"elements" must be a list.'])];
        }
        if (count($rawElements) < self::MIN_ELEMENTS) {
            $errors[] = 'A template needs at least ' . self::MIN_ELEMENTS . ' elements.';
        }
        if (count($rawElements) > self::MAX_ELEMENTS) {
            $errors[] = 'A template may have at most ' . self::MAX_ELEMENTS . ' elements.';
            $rawElements = array_slice($rawElements, 0, self::MAX_ELEMENTS);
        }

        $elements = [];
        foreach ($rawElements as $i => $raw) {
            $built = $this->buildElement(is_array($raw) ? $raw : [], $i, count($elements) + 1, $cw, $ch, $errors);
            if ($built !== null) {
                $elements[] = $built;
            }
        }

        if (! collect($elements)->contains(fn ($e) => preg_match('/^image_[1-5]$/', $e['field']) === 1)) {
            $errors[] = 'A property ad needs at least one property photo element (image_1 … image_5).';
        }

        $this->checkContrast($elements, $canvasStops, $errors);

        if ($errors !== []) {
            return ['ok' => false, 'name' => $name, 'layout' => null, 'errors' => array_values(array_unique($errors))];
        }

        return [
            'ok'     => true,
            'name'   => $name,
            'layout' => [
                'canvasW'       => $cw,
                'canvasH'       => $ch,
                'canvasPreset'  => $sizeKey,
                'canvasBg'      => $canvasBg,
                'canvasBgMode'  => $mode,
                'canvasBgFrom'  => $canvasFrom,
                'canvasBgTo'    => $canvasTo,
                'canvasBgAngle' => $canvasAng,
                'elements'      => $elements,
            ],
            'errors' => [],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Element construction
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<int, string> $errors
     * @return array<string, mixed>|null
     */
    private function buildElement(array $raw, int $index, int $order, int $cw, int $ch, array &$errors): ?array
    {
        $field = $raw['field'] ?? null;
        $tag   = 'Element ' . ($index + 1) . (is_string($field) ? " ({$field})" : '');

        if (! is_string($field) || ! isset(self::FIELDS[$field])) {
            $errors[] = "{$tag}: unknown element type. Allowed: " . implode(', ', array_keys(self::FIELDS)) . '.';

            return null;
        }

        $local = [];

        // Geometry — required, and the whole element must sit inside the canvas.
        $geo = [];
        foreach (['x', 'y', 'w', 'h'] as $k) {
            if (! isset($raw[$k]) || ! is_numeric($raw[$k])) {
                $local[] = "{$tag}: \"{$k}\" is required and must be a number.";
                continue;
            }
            $geo[$k] = (int) round((float) $raw[$k]);
        }
        if (count($geo) === 4) {
            if ($geo['w'] < 4 || $geo['h'] < 4) {
                $local[] = "{$tag}: width and height must be at least 4.";
            }
            $t = self::EDGE_TOLERANCE;
            if ($geo['x'] < -$t || $geo['y'] < -$t || $geo['x'] + $geo['w'] > $cw + $t || $geo['y'] + $geo['h'] > $ch + $t) {
                $local[] = "{$tag}: sits outside the {$cw}×{$ch} canvas (x {$geo['x']}, y {$geo['y']}, w {$geo['w']}, h {$geo['h']}).";
            }
        }

        $isText = $this->isTextField($field);

        // Start from the builder's own element shape, then lay the AI's checked values over it.
        $el = $this->baseElement($order, $field);
        foreach ($geo as $k => $v) {
            $el[$k] = $v;
        }
        $z = $raw['zIndex'] ?? $order;
        $el['zIndex'] = is_numeric($z) ? max(1, min(100, (int) round((float) $z))) : $order;

        foreach (self::NUMBERS as $k => [$min, $max, $dec]) {
            if (array_key_exists($k, $raw) && $raw[$k] !== null) {
                $v = $this->number($raw[$k], $min, $max, $dec, "{$tag}: \"{$k}\"", $local);
                if ($v !== null) {
                    $el[$k] = $v;
                }
            }
        }
        foreach (self::COLOR_KEYS as $k) {
            if (array_key_exists($k, $raw) && $raw[$k] !== null) {
                $c = $this->colorOr($raw[$k], '', "{$tag}: \"{$k}\"", $local);
                if ($c !== '') {
                    $el[$k] = $c;
                }
            }
        }
        foreach (self::ENUMS as $k => $allowed) {
            if (array_key_exists($k, $raw) && $raw[$k] !== null) {
                $v = is_scalar($raw[$k]) ? (string) $raw[$k] : '';
                if (in_array($v, $allowed, true)) {
                    $el[$k] = $v;
                } else {
                    $local[] = "{$tag}: \"{$k}\" must be one of " . implode(', ', $allowed) . '.';
                }
            }
        }
        if (array_key_exists('shadowOn', $raw)) {
            $el['shadowOn'] = filter_var($raw['shadowOn'], FILTER_VALIDATE_BOOLEAN);
        }

        // Literal copy — only the element types that carry it.
        if (in_array($field, ['custom_text', 'badge', 'watermark'], true)) {
            $text = trim(strip_tags((string) ($raw['text'] ?? '')));
            $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
            if ($field !== 'watermark' && $text === '') {
                $local[] = "{$tag}: \"text\" is required.";
            }
            if (mb_strlen($text) > self::MAX_TEXT_LEN) {
                $local[] = "{$tag}: \"text\" is longer than " . self::MAX_TEXT_LEN . ' characters.';
                $text    = mb_substr($text, 0, self::MAX_TEXT_LEN);
            }
            $el['text'] = $text;
        }

        // Text must stay readable at phone size.
        if ($isText && $field !== 'watermark' && $el['fontSize'] < self::MIN_FONT_SIZE) {
            $local[] = "{$tag}: fontSize {$el['fontSize']} is below the minimum of " . self::MIN_FONT_SIZE . '.';
        }
        if ($field === 'watermark' && $el['opacity'] > 0.15) {
            $local[] = "{$tag}: a watermark must be faint (opacity at most 0.15).";
        }
        array_push($errors, ...$local);

        return $local === [] ? $el : null;
    }

    /** Text-bearing element types (everything that prints words). */
    private function isTextField(string $field): bool
    {
        return ! in_array($field, self::IMAGE_FIELDS, true)
            && ! in_array($field, self::BLOCK_FIELDS, true)
            && $field !== 'logo';
    }

    /**
     * The complete element, exactly the keys CoreXAd.makeElement() creates, with the
     * builder's per-type starting values. Everything the model does not set stays here.
     *
     * @return array<string, mixed>
     */
    private function baseElement(int $order, string $field): array
    {
        $el = [
            'id' => $order, 'field' => $field, 'label' => self::FIELDS[$field]['label'],
            'x' => 0, 'y' => 0, 'w' => 200, 'h' => 60, 'zIndex' => $order,
            'rotation' => 0, 'hidden' => false, 'locked' => false, 'elOpacity' => 1,
            'fontFamily' => 'Figtree', 'fontSize' => 18, 'fontWeight' => '600', 'color' => '#ffffff',
            'textAlign' => 'left', 'verticalAlign' => 'middle', 'textTransform' => 'none',
            'letterSpacing' => 0, 'lineHeight' => 1.2, 'padding' => 8,
            'preview' => self::PREVIEWS[$field] ?? '', 'text' => '',
            'bgColor' => '#000000', 'bgOpacity' => 0, 'objectFit' => 'cover', 'borderRadius' => 0,
            'bg' => '#07111e', 'opacity' => 1, 'shapeType' => 'rounded', 'src' => '', 'mediaKind' => '',
            'selectedFeatures' => null, 'numberFormat' => 'number', 'icon' => null, 'iconSize' => null,
            'gradFrom' => '#071325', 'gradTo' => 'rgba(7,19,37,0)', 'gradAngle' => 180, 'borderWidth' => 3,
            'frameBorderWidth' => 0, 'frameBorderColor' => '#ffffff',
            'shadowOn' => false, 'shadowX' => 0, 'shadowY' => 4, 'shadowBlur' => 12, 'shadowColor' => '#000000', 'shadowOpacity' => 0.45,
            'groupId' => null, 'removeBackground' => false,
        ];

        if ($field === 'status_badge') {
            $el = array_merge($el, ['fontSize' => 16, 'fontWeight' => '800', 'textAlign' => 'center', 'textTransform' => 'uppercase',
                'bgColor' => '#e63946', 'bgOpacity' => 1, 'borderRadius' => 6, 'padding' => 8]);
        }
        if ($field === 'badge') {
            $el = array_merge($el, ['fontSize' => 16, 'fontWeight' => '800', 'textAlign' => 'center', 'textTransform' => 'uppercase',
                'bgColor' => '#00b4d8', 'bgOpacity' => 1, 'borderRadius' => 22, 'padding' => 8]);
        }
        if ($field === 'shape') {
            $el['bg'] = '#00b4d8';
        }
        if (in_array($field, ['agent_avatar'], true)) {
            $el['shapeType']    = 'circle';
            $el['borderRadius'] = 16;
        }
        if ($field === 'agency_logo') {
            $el['objectFit'] = 'contain';
        }
        if ($field === 'line') {
            $el['color'] = '#00b4d8';
        }
        if ($field === 'logo') {
            $el['fontSize'] = 28;
            $el['padding']  = 0;
        }
        if ($field === 'watermark') {
            $el['fontSize'] = 60;
            $el['opacity']  = 0.06;
        }

        return $el;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Contrast
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $elements
     * @param array<int, string>               $canvasStops
     * @param array<int, string>               $errors
     */
    private function checkContrast(array $elements, array $canvasStops, array &$errors): void
    {
        foreach ($elements as $idx => $el) {
            if (! $this->isTextField($el['field']) || $el['field'] === 'watermark') {
                continue;
            }
            $fg = $this->parseColor((string) $el['color']);
            if ($fg === null) {
                continue; // already reported as a colour error
            }
            $fg[3] *= (float) $el['elOpacity'];
            $tag    = 'Element ' . ($idx + 1) . " ({$el['field']})";

            $bgStops = $this->backgroundBehind($elements, $idx, $canvasStops);
            if ($bgStops === null) {
                if (! $el['shadowOn']) {
                    $errors[] = "{$tag}: text sits straight on a photo with nothing behind it to keep it readable — put a gradient/colour block under it, give it a pill background (bgColor + bgOpacity 0.6+), or turn shadowOn on.";
                }
                continue;
            }

            foreach ($bgStops as $bgHex) {
                $bg = $this->parseColor($bgHex);
                if ($bg === null) {
                    continue;
                }
                $ratio = $this->contrast($fg, $bg);
                if ($ratio < self::MIN_CONTRAST) {
                    $errors[] = sprintf(
                        '%s: text colour %s on %s has contrast %.1f:1 — it must be at least %.1f:1.',
                        $tag, $el['color'], $bgHex, $ratio, self::MIN_CONTRAST
                    );
                    break;
                }
            }
        }
    }

    /**
     * What a text element is drawn on top of: its own pill, else the nearest covering
     * block/shape/gradient below it, else the canvas. null = a photo (unknowable).
     *
     * @param  array<int, array<string, mixed>> $elements
     * @param  array<int, string>               $canvasStops
     * @return array<int, string>|null  Opaque background colours to test against.
     */
    private function backgroundBehind(array $elements, int $idx, array $canvasStops): ?array
    {
        $el = $elements[$idx];

        if ((float) $el['bgOpacity'] >= 0.5) {
            return [(string) $el['bgColor']];
        }

        $cx = $el['x'] + $el['w'] / 2;
        $cy = $el['y'] + $el['h'] / 2;

        // Elements stacked below this one, nearest first.
        $below = [];
        foreach ($elements as $j => $other) {
            if ($j === $idx) {
                continue;
            }
            if ($other['zIndex'] < $el['zIndex'] || ($other['zIndex'] === $el['zIndex'] && $j < $idx)) {
                $below[] = $other;
            }
        }
        usort($below, fn ($a, $b) => [$b['zIndex'], $b['id']] <=> [$a['zIndex'], $a['id']]);

        foreach ($below as $o) {
            $inside = $cx >= $o['x'] && $cx <= $o['x'] + $o['w'] && $cy >= $o['y'] && $cy <= $o['y'] + $o['h'];
            if (! $inside) {
                continue;
            }
            $alpha = (float) $o['opacity'] * (float) $o['elOpacity'];

            if (in_array($o['field'], ['color_block', 'shape'], true) && $alpha >= 0.5) {
                return [(string) $o['bg']];
            }
            if ($o['field'] === 'gradient') {
                $stops = [];
                foreach (['gradFrom', 'gradTo'] as $k) {
                    $p = $this->parseColor((string) $o[$k]);
                    if ($p !== null && $p[3] * $alpha >= 0.5) {
                        $stops[] = (string) $o[$k];
                    }
                }
                if ($stops !== []) {
                    return $stops;
                }
            }
            if (in_array($o['field'], self::IMAGE_FIELDS, true) && $o['field'] !== 'agency_logo' && $o['field'] !== 'agent_avatar') {
                return null; // a property photo
            }
        }

        return $canvasStops;
    }

    /** WCAG contrast ratio of a (possibly translucent) foreground over an opaque background. */
    private function contrast(array $fg, array $bg): float
    {
        $a   = $fg[3];
        $mix = [
            (int) round($fg[0] * $a + $bg[0] * (1 - $a)),
            (int) round($fg[1] * $a + $bg[1] * (1 - $a)),
            (int) round($fg[2] * $a + $bg[2] * (1 - $a)),
        ];
        $l1 = $this->luminance($mix);
        $l2 = $this->luminance($bg);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    /** @param array{0:int,1:int,2:int} $rgb */
    private function luminance(array $rgb): float
    {
        $c = array_map(function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Primitives
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Parse #rgb / #rrggbb / rgb() / rgba() into [r, g, b, a]. Anything else is null.
     *
     * @return array{0:int,1:int,2:int,3:float}|null
     */
    public function parseColor(string $value): ?array
    {
        $v = trim($value);

        if (preg_match('/^#([0-9a-f]{3})$/i', $v, $m)) {
            $h = $m[1];

            return [hexdec($h[0] . $h[0]), hexdec($h[1] . $h[1]), hexdec($h[2] . $h[2]), 1.0];
        }
        if (preg_match('/^#([0-9a-f]{6})$/i', $v, $m)) {
            return [hexdec(substr($m[1], 0, 2)), hexdec(substr($m[1], 2, 2)), hexdec(substr($m[1], 4, 2)), 1.0];
        }
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*(0|1|0?\.\d+|1\.0+)\s*)?\)$/i', $v, $m)) {
            if ((int) $m[1] > 255 || (int) $m[2] > 255 || (int) $m[3] > 255) {
                return null;
            }

            return [(int) $m[1], (int) $m[2], (int) $m[3], isset($m[4]) && $m[4] !== '' ? (float) $m[4] : 1.0];
        }

        return null;
    }

    /**
     * A syntactically safe colour string, or $fallback (and an error) when the model wrote
     * something else. Returns the trimmed original — never an interpolation of user text.
     *
     * @param array<int, string> $errors
     */
    private function colorOr(mixed $value, string $fallback, string $label, array &$errors): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }
        if (is_string($value) && $this->parseColor($value) !== null) {
            return trim($value);
        }
        $errors[] = "{$label} is not a valid colour (use #rrggbb, rgb() or rgba()).";

        return $fallback;
    }

    /**
     * @param array<int, string> $errors
     */
    private function number(mixed $value, float $min, float $max, int $decimals, string $label, array &$errors): int|float|null
    {
        if (! is_numeric($value)) {
            $errors[] = "{$label} must be a number.";

            return null;
        }
        $n = (float) $value;
        if ($n < $min || $n > $max) {
            $errors[] = "{$label} {$n} is outside the allowed range {$min} to {$max}.";

            return null;
        }
        $n = round($n, $decimals);

        return $decimals === 0 ? (int) $n : $n;
    }
}
