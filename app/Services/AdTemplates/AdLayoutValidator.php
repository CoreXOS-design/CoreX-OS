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
 * AT-456 §B.9-2: it also knows every B.9-1 block (collage, corner ribbon, QR code, feature pills, the listing facts, photo
 * masks / effects, duotone, line styles, decorative shapes, icons on any text, the 20 fonts) and has a deterministic safe
 * repair for each (QR raised to 120 px, a collage preset snapped to the nearest real one, a ribbon's words cut or shrunk to
 * fit, a script font moved off a price, ...). Colour, injection and contrast guards are as strict as ever.
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

    /** A QR code must be at least this many pixels on each side or phones cannot scan it (spec §B.9 item 7). */
    public const MIN_QR_SIZE = 120;

    /** QR dark-on-light contrast needed to scan reliably (WCAG-style ratio between the two colours' luminance). */
    public const MIN_QR_CONTRAST = 4.0;

    /** A corner ribbon's words are a label, not a sentence. */
    public const MAX_RIBBON_TEXT = 24;

    /** A corner ribbon is clipped to its own box, so a box smaller than this shows almost none of the band. */
    public const MIN_RIBBON_SIZE = 80;

    /** Script faces (Great Vibes, Dancing Script) are unreadable small and on long text: size and length floor/cap. */
    public const MIN_SCRIPT_FONT_SIZE = 28;
    public const MAX_SCRIPT_TEXT      = 30;

    /** A watermark must be at most this opaque. */
    public const WATERMARK_MAX_OPACITY = 0.15;

    /** A pill / block / gradient stop only counts as "something behind the text" at or above this opacity. */
    public const BACKDROP_MIN_ALPHA = 0.5;

    /** Tolerance (px) for an element a hair past the canvas edge. */
    private const EDGE_TOLERANCE = 2;
    /** The margin used in the prompt's worked example of an inset line (a prompt illustration only; the validator never enforces it). */
    private const EXAMPLE_INSET = 40;

    /** Plain colour names the model sometimes writes; swapped for these exact hex values (never passed through). */
    private const NAMED_COLORS = [
        'white' => '#ffffff', 'black' => '#000000', 'red' => '#ff0000', 'green' => '#008000', 'blue' => '#0000ff',
        'yellow' => '#ffff00', 'orange' => '#ffa500', 'gray' => '#808080', 'grey' => '#808080', 'navy' => '#000080',
        'gold' => '#ffd700', 'silver' => '#c0c0c0', 'teal' => '#008080', 'purple' => '#800080', 'maroon' => '#800000',
        'transparent' => 'rgba(0,0,0,0)',
    ];

    /** Fields that show a property photo or logo. */
    private const IMAGE_FIELDS = ['image_1', 'image_2', 'image_3', 'image_4', 'image_5', 'agent_avatar', 'agency_logo'];

    /** Fields that draw a block of colour and carry no text. (QR and the photo collage draw pictures, no words.) */
    private const BLOCK_FIELDS = ['color_block', 'shape', 'gradient', 'line', 'qr', 'collage'];

    /** Elements that show the listing's own photos (a collage is 2-5 of them): "a property photo is on the ad". */
    private const PHOTO_FIELDS = ['image_1', 'image_2', 'image_3', 'image_4', 'image_5', 'collage'];

    /** Elements that sit on their OWN colour (bgColor + bgOpacity), which must therefore be solid enough to count. */
    private const OWN_BACKGROUND_FIELDS = ['ribbon', 'feature_pills'];

    /** The two script faces. Short headline-type words only. */
    private const SCRIPT_FONTS = ['Great Vibes', 'Dancing Script'];

    /** The only elements a script face may be put on (headline-type, short words; never a price, phone number or body text). */
    private const SCRIPT_OK_FIELDS = ['title', 'custom_text', 'badge', 'agency_name'];

    /** The listing facts (text elements whose value is hidden on a real ad when the listing has none). */
    public const FACT_FIELDS = ['erf_size', 'levy', 'rates_taxes', 'price_per_m2', 'pet_friendly', 'available_from', 'rent_per_month',
        'show_day', 'auction_date', 'auction_venue'];

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
        // AT-456 §B.9-2 — the building blocks added in §B.9-1.
        'collage'           => ['label' => 'Photo Collage',         'help' => '2-5 of the listing\'s photos in a preset layout ("collagePreset", "collageGap", "collageFirst", corner radius in "borderRadius"); counts as a property photo'],
        'ribbon'            => ['label' => 'Corner Ribbon',         'help' => 'diagonal sash across one corner of its box ("ribbonCorner" tl/tr/bl/br), band colour "bgColor"+"bgOpacity", words in "text" (short, like JUST LISTED); box at least 80x80'],
        'qr'                => ['label' => 'QR Code to Listing',    'help' => 'scannable code that opens the listing\'s web page; "qrDark" on "qrLight"; at least 120x120; the data is filled in per listing'],
        'feature_pills'     => ['label' => 'Feature Pills',         'help' => '1-4 of the listing\'s own features as separate rounded pills ("pillCount", "pillDirection" row/column, "pillGap"); pill colour "bgColor"+"bgOpacity", text "color"; you cannot choose which features'],
        'erf_size'          => ['label' => 'Erf Size',              'help' => 'plot size, e.g. "Erf: 800 m²" (hidden when the listing has none)'],
        'levy'              => ['label' => 'Levy per Month',        'help' => 'sectional-title levy, e.g. "Levy: R 1 250 pm" (hidden when the listing has none)'],
        'rates_taxes'       => ['label' => 'Rates & Taxes per Month', 'help' => 'rates and taxes (hidden when the listing has none)'],
        'price_per_m2'      => ['label' => 'Price per m²',          'help' => 'asking price per square metre, sales only (hidden when it cannot be worked out)'],
        'pet_friendly'      => ['label' => 'Pet Friendly',          'help' => '"Pet friendly" (hidden unless the listing allows pets)'],
        'available_from'    => ['label' => 'Available From',        'help' => 'rentals: "Available now" or "Available from 1 Nov 2026" (hidden on sales)'],
        'rent_per_month'    => ['label' => 'Rent per Month',        'help' => 'rentals: the rent with its period, e.g. "R 12 500 per month" (hidden on sales)'],
        'show_day'          => ['label' => 'Show Day',              'help' => 'next show day / open house with times (hidden when none is booked)'],
        'auction_date'      => ['label' => 'Auction Date',          'help' => 'auction date and time (hidden when the listing is not going to auction)'],
        'auction_venue'     => ['label' => 'Auction Venue',         'help' => 'auction venue (hidden when the listing is not going to auction)'],
    ];

    /** Mirrors SHAPES + DECOR_SHAPES in the render kernel (a drift test compares them). */
    private const SHAPES   = ['rectangle', 'rounded', 'circle', 'pill', 'triangle', 'diamond', 'pentagon', 'hexagon', 'star', 'chevron',
        'wave', 'curve_top', 'curve_bottom', 'arc', 'blob_1', 'blob_2', 'blob_3'];
    /** Mirrors FONTS in the render kernel (and _ad-fonts.blade.php). */
    private const FONTS    = ['Figtree', 'Inter', 'Poppins', 'Montserrat', 'Oswald', 'Bebas Neue', 'Playfair Display', 'Lora',
        'Cormorant Garamond', 'DM Serif Display', 'Libre Baskerville', 'Great Vibes', 'Dancing Script', 'Barlow Condensed', 'Anton',
        'Nunito', 'Quicksand', 'Raleway', 'Work Sans', 'Space Grotesk'];
    private const WEIGHTS  = ['400', '500', '600', '700', '800', '900'];
    /** Mirrors ICON_LIST in the render kernel (a drift test compares them). */
    private const ICONS    = ['bed', 'bath', 'garage', 'parking', 'ruler', 'house', 'pin', 'key', 'pool', 'tree', 'door', 'tag',
        'sea_view', 'mountain_view', 'pet_friendly', 'solar', 'security', 'cctv', 'alarm', 'braai', 'fireplace', 'air_con', 'garden', 'flatlet', 'wifi', 'golf_estate', 'school', 'shopping', 'beach', 'estate_gated', 'borehole', 'backup_power', 'lift', 'gym', 'study', 'kitchen', 'laundry', 'balcony', 'calendar', 'clock', 'phone', 'email', 'chat', 'star', 'heart', 'check', 'sparkle'];

    // AT-456 §B.9-1 — values of the new per-element properties (mirrors of the kernel's PHOTO_MASKS / PHOTO_EFFECTS /
    // LINE_STYLES / COLLAGE_PRESETS / RIBBON_CORNERS; drift tests compare them). AT-456 §B.9-2: the AI is now told every one of
    // them — promptLimits() is generated from ENUMS below, there is no separate "what the prompt mentions" list.
    private const PHOTO_MASKS     = ['none', 'rounded', 'circle', 'arch', 'blob'];
    private const PHOTO_EFFECTS   = ['none', 'bw', 'duotone', 'warm', 'cool', 'brighter'];
    private const LINE_STYLES     = ['solid', 'dashed', 'dotted'];
    private const COLLAGE_PRESETS = ['2_side', '2_stack', '3_hero_left', '3_hero_top', '4_grid', '5_hero'];
    private const RIBBON_CORNERS  = ['tl', 'tr', 'bl', 'br'];
    private const PILL_DIRECTIONS = ['row', 'column'];

    /** Design-time sample copy the builder shows in an empty slot (mirrors the kernel's `preview`). */
    private const PREVIEWS = [
        'features' => '4 Bed · 3 Bath · 2 Garage', 'beds' => '4', 'baths' => '3', 'garages' => '2', 'parking' => '2',
        'garages_or_parking' => '2', 'size_m2' => '450 m²', 'size_or_land' => '450 M²', 'reference' => 'REF 12345',
        'address' => '12 Marine Drive', 'status_badge' => 'FOR SALE', 'agent_phone' => '082 000 0000',
        'website' => 'WWW.AGENCY.CO.ZA',
        'erf_size' => '800 m²', 'levy' => 'R 1 250 pm', 'rates_taxes' => 'R 900 pm', 'price_per_m2' => 'R 7 656 per m²',
        'pet_friendly' => 'Pet friendly', 'available_from' => 'Available from 1 Nov 2026', 'rent_per_month' => 'R 12 500 per month',
        'show_day' => 'Sat 25 Oct · 10:00–13:00', 'auction_date' => '12 Nov 2026 · 11:00', 'auction_venue' => 'Sample Venue, Sample Town',
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
        // AT-456 §B.9-1 — collage, pills.
        'collageGap' => [0, 60, 0], 'collageFirst' => [1, 5, 0], 'pillCount' => [1, 4, 0], 'pillGap' => [0, 60, 0],
    ];

    private const COLOR_KEYS = ['color', 'bgColor', 'bg', 'gradFrom', 'gradTo', 'frameBorderColor', 'shadowColor', 'duoColor1', 'duoColor2', 'qrDark', 'qrLight'];

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
        'photoMask'     => self::PHOTO_MASKS,
        'photoEffect'   => self::PHOTO_EFFECTS,
        'lineStyle'     => self::LINE_STYLES,
        'collagePreset' => self::COLLAGE_PRESETS,
        'ribbonCorner'  => self::RIBBON_CORNERS,
        'pillDirection' => self::PILL_DIRECTIONS,
    ];

    /** Every font the AI may use (also the style library's font check). */
    public static function fontNames(): array
    {
        return self::FONTS;
    }

    /** Allowed words for an enum property (e.g. 'photoMask'), or null when it is not one. */
    public static function enumValues(string $key): ?array
    {
        return self::ENUMS[$key] ?? null;
    }

    /** [min, max] for a numeric property (e.g. 'frameBorderWidth'), or null when it is not one. */
    public static function numberRange(string $key): ?array
    {
        return isset(self::NUMBERS[$key]) ? [self::NUMBERS[$key][0], self::NUMBERS[$key][1]] : null;
    }

    /** The script faces — short headline-type words only. */
    public static function scriptFontNames(): array
    {
        return self::SCRIPT_FONTS;
    }

    /**
     * Validate one option (a name + a canvas + elements) as the model wrote it.
     *
     * @param  array<string, mixed> $option  Decoded JSON from the model.
     * @return array{ok: bool, name: string, layout: ?array<string, mixed>, errors: array<int, string>}
     */
    public function validate(array $option, string $sizeKey): array
    {
        $repairs = [];

        return $this->run($option, $sizeKey, false, $repairs) + ['repairs' => []];
    }

    /**
     * Like validate(), but first fixes what can be fixed WITHOUT weakening any rule, so a design
     * that is nearly right is kept instead of thrown away. Repairs are deterministic and only ever
     * move a value INTO the allowed range or swap it for a known-good literal:
     *   - an element a little outside the canvas is moved/shrunk back inside;
     *   - a font below the minimum is raised to it; numbers out of range are clamped;
     *   - text longer than the limit is cut; a text element with no text, or of an unknown type, is dropped;
     *   - a plain colour name or 8/4-digit hex becomes the equivalent hex/rgba; translucent canvas colours become opaque;
     *   - a wrong-case or numeric enum value is normalised; an unusable one is removed (the default applies);
     *   - text colour that fails real contrast is switched to white or black when that passes against
     *     every backdrop; text straight on a photo gets a text shadow (the validator's own accepted remedy).
     * NOT repaired, still rejected: a colour that is not plain hex/rgb()/rgba() after the above (injection
     * guard), a missing property photo, too few elements, contrast that neither white nor black can meet.
     *
     * @param  array<string, mixed> $option
     * @return array{ok: bool, name: string, layout: ?array<string, mixed>, errors: array<int, string>, repairs: array<int, string>}
     */
    public function validateAndRepair(array $option, string $sizeKey): array
    {
        $repairs = [];
        $size    = self::SIZES[$sizeKey] ?? null;
        if ($size !== null) {
            $option = $this->repairRaw($option, $size['w'], $size['h'], $repairs);
        }

        $result = $this->run($option, $sizeKey, true, $repairs);
        $result['repairs'] = array_values(array_unique($repairs));

        return $result;
    }

    /**
     * @param  array<string, mixed> $option
     * @param  array<int, string>   $repairs
     * @return array{ok: bool, name: string, layout: ?array<string, mixed>, errors: array<int, string>}
     */
    private function run(array $option, string $sizeKey, bool $repair, array &$repairs): array
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

        if (! collect($elements)->contains(fn ($e) => in_array($e['field'], self::PHOTO_FIELDS, true))) {
            $errors[] = 'A property ad needs at least one property photo element (image_1 … image_5, or a collage).';
        }

        $this->checkContrast($elements, $canvasStops, $errors, $repair, $repairs);

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
    // Repair (see validateAndRepair)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed> $option
     * @param  array<int, string>   $repairs
     * @return array<string, mixed>
     */
    private function repairRaw(array $option, int $cw, int $ch, array &$repairs): array
    {
        // Canvas colours: tidy the syntax, and a see-through canvas becomes opaque.
        foreach (['canvasBg', 'canvasBgFrom', 'canvasBgTo'] as $k) {
            if (isset($option[$k]) && is_string($option[$k])) {
                $fixed = $this->tidyColor($option[$k]);
                $p     = $this->parseColor($fixed);
                if ($p !== null && $p[3] < 1.0) {
                    $fixed = sprintf('#%02x%02x%02x', $p[0], $p[1], $p[2]);
                    $repairs[] = "{$k}: a see-through canvas colour was made opaque.";
                }
                $option[$k] = $fixed;
            }
        }
        if (isset($option['canvasBgAngle']) && is_numeric($option['canvasBgAngle'])) {
            $option['canvasBgAngle'] = max(0, min(360, (float) $option['canvasBgAngle']));
        }

        if (! isset($option['elements']) || ! is_array($option['elements']) || ! array_is_list($option['elements'])) {
            return $option;
        }

        $kept = [];
        foreach ($option['elements'] as $i => $raw) {
            if (! is_array($raw)) {
                $kept[] = $raw;
                continue;
            }
            $field = $raw['field'] ?? null;
            $tag   = 'Element ' . ($i + 1) . (is_string($field) ? " ({$field})" : '');

            if (! is_string($field) || ! isset(self::FIELDS[$field])) {
                $repairs[] = "{$tag}: an element type that does not exist was left out.";
                continue;
            }

            // Text elements that carry their own words must have some, else there is nothing to draw.
            if (in_array($field, ['custom_text', 'badge', 'ribbon'], true)
                && trim(strip_tags((string) ($raw['text'] ?? ''))) === '') {
                $repairs[] = "{$tag}: an element with no text was left out.";
                continue;
            }
            if (isset($raw['text']) && is_string($raw['text']) && in_array($field, ['custom_text', 'badge', 'watermark'], true)) {
                $clean = trim(strip_tags($raw['text']));
                if (mb_strlen($clean) > self::MAX_TEXT_LEN) {
                    $raw['text'] = rtrim(mb_substr($clean, 0, self::MAX_TEXT_LEN - 1)) . '…';
                    $repairs[]   = "{$tag}: text longer than " . self::MAX_TEXT_LEN . ' characters was shortened.';
                }
            }
            if (isset($raw['text']) && is_string($raw['text']) && $field === 'ribbon') {
                $clean = trim(strip_tags($raw['text']));
                if (mb_strlen($clean) > self::MAX_RIBBON_TEXT) {
                    $raw['text'] = $this->cutAtWord($clean, self::MAX_RIBBON_TEXT);
                    $repairs[]   = "{$tag}: ribbon words longer than " . self::MAX_RIBBON_TEXT . ' characters were cut.';
                }
            }

            // Geometry: numbers written as strings, then back inside the canvas.
            $geoOk = true;
            foreach (['x', 'y', 'w', 'h'] as $k) {
                if (isset($raw[$k]) && is_numeric($raw[$k])) {
                    $raw[$k] = (float) $raw[$k];
                } else {
                    $geoOk = false;
                }
            }
            if ($geoOk) {
                $t = self::EDGE_TOLERANCE;
                $outside = $raw['x'] < -$t || $raw['y'] < -$t || $raw['x'] + $raw['w'] > $cw + $t || $raw['y'] + $raw['h'] > $ch + $t
                    || $raw['w'] < 4 || $raw['h'] < 4;
                if ($outside) {
                    $w = max(4, min((int) round($raw['w']), $cw));
                    $h = max(4, min((int) round($raw['h']), $ch));
                    $raw['x'] = max(0, min((int) round($raw['x']), $cw - $w));
                    $raw['y'] = max(0, min((int) round($raw['y']), $ch - $h));
                    $raw['w'] = $w;
                    $raw['h'] = $h;
                    $repairs[] = "{$tag}: sat outside the {$cw}×{$ch} canvas, so it was moved back inside.";
                }
            }

            // Numbers: clamp into range (a non-number is removed so the default applies).
            foreach (self::NUMBERS as $k => [$min, $max]) {
                if (! array_key_exists($k, $raw) || $raw[$k] === null) {
                    continue;
                }
                if (! is_numeric($raw[$k])) {
                    unset($raw[$k]);
                    $repairs[] = "{$tag}: \"{$k}\" was not a number, so the default was used.";
                    continue;
                }
                $v = (float) $raw[$k];
                if ($v < $min || $v > $max) {
                    $raw[$k]   = max($min, min($max, $v));
                    $repairs[] = "{$tag}: \"{$k}\" {$v} was outside {$min} to {$max}, so it was clamped.";
                }
            }
            if ($this->isTextField($field) && $field !== 'watermark' && isset($raw['fontSize']) && is_numeric($raw['fontSize']) && (float) $raw['fontSize'] < self::MIN_FONT_SIZE) {
                $repairs[]       = "{$tag}: fontSize {$raw['fontSize']} was raised to the minimum of " . self::MIN_FONT_SIZE . '.';
                $raw['fontSize'] = self::MIN_FONT_SIZE;
            }
            if ($field === 'watermark' && isset($raw['opacity']) && is_numeric($raw['opacity']) && (float) $raw['opacity'] > self::WATERMARK_MAX_OPACITY) {
                $raw['opacity'] = 0.1;
                $repairs[]      = "{$tag}: a watermark was too solid, so its opacity was set to 0.1.";
            }

            // Colours: only a known-good rewrite; anything else is left for the validator to reject.
            foreach (self::COLOR_KEYS as $k) {
                if (isset($raw[$k]) && is_string($raw[$k])) {
                    $fixed = $this->tidyColor($raw[$k]);
                    if ($fixed !== trim($raw[$k])) {
                        $repairs[] = "{$tag}: colour \"{$raw[$k]}\" was written as {$fixed}.";
                    }
                    $raw[$k] = $fixed;
                }
            }

            // Enums: numeric weights, wrong case; an unusable value is removed so the default applies.
            foreach (self::ENUMS as $k => $allowed) {
                if (! array_key_exists($k, $raw) || $raw[$k] === null) {
                    continue;
                }
                $v = is_scalar($raw[$k]) ? trim((string) $raw[$k]) : '';
                if (in_array($v, $allowed, true)) {
                    $raw[$k] = $v;
                    continue;
                }
                $match = null;
                foreach ($allowed as $a) {
                    if (strcasecmp($a, $v) === 0) {
                        $match = $a;
                        break;
                    }
                }
                if ($match !== null) {
                    $raw[$k]   = $match;
                    $repairs[] = "{$tag}: \"{$k}\" {$v} was corrected to {$match}.";
                } elseif ($k === 'collagePreset' && $v !== '') {
                    $near      = $this->nearestCollagePreset($v);
                    $raw[$k]   = $near;
                    $repairs[] = "{$tag}: collage layout \"{$v}\" is not one of ours, so the nearest one, {$near}, was used.";
                } else {
                    unset($raw[$k]);
                    $repairs[] = "{$tag}: \"{$k}\" had a value that is not allowed, so the default was used.";
                }
            }

            $raw = $this->repairByType($raw, $field, $tag, $cw, $ch, $repairs);

            $kept[] = $raw;
        }
        $option['elements'] = $kept;

        return $option;
    }

    // ─────────────────────────────────────────────────────────────────────
    // AT-456 §B.9-2 — rules and safe repairs for the B.9-1 blocks
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Per-type repairs, run after the generic ones. Each is deterministic and only moves a value INTO what the strict
     * rules (blockProblems) accept; whatever it cannot fix is left for the validator to reject.
     *
     * @param  array<string, mixed> $raw
     * @param  array<int, string>   $repairs
     * @return array<string, mixed>
     */
    private function repairByType(array $raw, string $field, string $tag, int $cw, int $ch, array &$repairs): array
    {
        $d       = $this->baseElement(1, $field);
        $num     = static fn (string $k) => isset($raw[$k]) && is_numeric($raw[$k]) ? (float) $raw[$k] : (float) $d[$k];
        $resized = false;

        // A ribbon / pill row sits on its OWN colour, so that colour has to be solid enough to count as the backdrop.
        if (in_array($field, self::OWN_BACKGROUND_FIELDS, true) && $num('bgOpacity') < self::BACKDROP_MIN_ALPHA) {
            $raw['bgOpacity'] = 0.9;
            $repairs[]        = "{$tag}: its own background was too faint to read on, so bgOpacity was set to 0.9.";
        }

        if ($field === 'ribbon') {
            foreach (['w', 'h'] as $k) {
                if (isset($raw[$k]) && $raw[$k] < self::MIN_RIBBON_SIZE) {
                    $raw[$k]  = self::MIN_RIBBON_SIZE;
                    $resized  = true;
                    $repairs[] = "{$tag}: a ribbon box smaller than " . self::MIN_RIBBON_SIZE . ' pixels was enlarged.';
                }
            }
            $text = trim(strip_tags((string) ($raw['text'] ?? '')));
            if ($text !== '' && isset($raw['w'], $raw['h'])) {
                $fs    = (int) round($num('fontSize'));
                $upper = ($raw['textTransform'] ?? $d['textTransform']) !== 'none' && ($raw['textTransform'] ?? 'uppercase') !== 'capitalize';
                $fit   = fn (int $size, string $t) => $this->ribbonTextFits((float) $raw['w'], (float) $raw['h'], $size, $num('padding'), $num('letterSpacing'), $upper, $t);
                if (! $fit($fs, $text)) {
                    $size = $fs;
                    while ($size > self::MIN_FONT_SIZE && ! $fit($size, $text)) {
                        $size--;
                    }
                    if ($size !== $fs) {
                        $raw['fontSize'] = $size;
                        $repairs[]       = "{$tag}: the ribbon words did not fit the band, so fontSize was lowered to {$size}.";
                    }
                    $cut = $text;
                    while (! $fit($size, $cut) && mb_strlen($cut) > 3) {
                        $cut = mb_substr($cut, 0, -1);
                    }
                    if ($cut !== $text) {
                        $raw['text'] = rtrim($cut);
                        $repairs[]   = "{$tag}: the ribbon words still did not fit the band, so they were cut.";
                    }
                }
            }
        }

        if ($field === 'qr') {
            foreach (['w', 'h'] as $k) {
                if (isset($raw[$k]) && $raw[$k] < self::MIN_QR_SIZE) {
                    $raw[$k]   = self::MIN_QR_SIZE;
                    $resized   = true;
                    $repairs[] = "{$tag}: a QR code smaller than " . self::MIN_QR_SIZE . ' pixels cannot be scanned, so it was enlarged.';
                }
            }
            foreach (['qrDark', 'qrLight'] as $k) {
                $p = isset($raw[$k]) && is_string($raw[$k]) ? $this->parseColor($raw[$k]) : null;
                if ($p !== null && $p[3] < 1.0) {
                    $raw[$k]   = sprintf('#%02x%02x%02x', $p[0], $p[1], $p[2]);
                    $repairs[] = "{$tag}: a see-through QR colour was made solid.";
                }
            }
            $dark  = (string) ($raw['qrDark'] ?? $d['qrDark']);
            $light = (string) ($raw['qrLight'] ?? $d['qrLight']);
            if ($this->parseColor($dark) !== null && $this->parseColor($light) !== null && $this->qrColourProblem($dark, $light) !== null) {
                $raw['qrDark']  = '#000000';
                $raw['qrLight'] = '#ffffff';
                $repairs[]      = "{$tag}: the QR colours would not scan reliably, so black on white was used.";
            }
        }

        if ($field === 'feature_pills' && isset($raw['h'])) {
            $min = $this->pillsMinHeight($num('fontSize'), $num('padding'), (string) ($raw['pillDirection'] ?? $d['pillDirection']), (int) $num('pillCount'), (int) $num('pillGap'));
            if ($raw['h'] < $min) {
                $raw['h']  = $min;
                $resized   = true;
                $repairs[] = "{$tag}: the pills did not fit their box, so it was made taller ({$min} pixels).";
            }
        }

        // A script face is for short headline-type words only.
        if (isset($raw['fontFamily']) && in_array($raw['fontFamily'], self::SCRIPT_FONTS, true) && $this->isTextField($field)) {
            $why = null;
            if (! in_array($field, self::SCRIPT_OK_FIELDS, true)) {
                $why = 'a script font is for a short headline, not for this element';
            } elseif (in_array($field, ['custom_text', 'badge'], true) && mb_strlen(trim(strip_tags((string) ($raw['text'] ?? '')))) > self::MAX_SCRIPT_TEXT) {
                $why = 'a script font is only readable on a few words';
            }
            if ($why !== null) {
                unset($raw['fontFamily']);
                $repairs[] = "{$tag}: {$why}, so the standard font was used.";
            } elseif ($num('fontSize') < self::MIN_SCRIPT_FONT_SIZE) {
                $raw['fontSize'] = self::MIN_SCRIPT_FONT_SIZE;
                $repairs[]       = "{$tag}: a script font is unreadable small, so fontSize was raised to " . self::MIN_SCRIPT_FONT_SIZE . '.';
            }
        }

        if ($resized && isset($raw['x'], $raw['y'], $raw['w'], $raw['h'])) {
            $w         = max(4, min((int) round($raw['w']), $cw));
            $h         = max(4, min((int) round($raw['h']), $ch));
            $raw['x']  = max(0, min((int) round($raw['x']), $cw - $w));
            $raw['y']  = max(0, min((int) round($raw['y']), $ch - $h));
            $raw['w']  = $w;
            $raw['h']  = $h;
        }

        return $raw;
    }

    /**
     * The rules for the §B.9-1 blocks (strict mode; repairByType brings values into these). Returns plain-words problems.
     *
     * @param  array<string, mixed> $el  the built element
     * @return array<int, string>
     */
    private function blockProblems(array $el, string $tag): array
    {
        $out   = [];
        $field = $el['field'];

        if (in_array($field, self::OWN_BACKGROUND_FIELDS, true) && (float) $el['bgOpacity'] < self::BACKDROP_MIN_ALPHA) {
            $out[] = "{$tag}: its own background must be solid enough to read on (bgOpacity at least " . self::BACKDROP_MIN_ALPHA . ').';
        }

        if ($field === 'ribbon') {
            if ($el['w'] < self::MIN_RIBBON_SIZE || $el['h'] < self::MIN_RIBBON_SIZE) {
                $out[] = "{$tag}: a ribbon box must be at least " . self::MIN_RIBBON_SIZE . '×' . self::MIN_RIBBON_SIZE . ' pixels.';
            }
            $upper = ! in_array($el['textTransform'], ['none', 'capitalize'], true);
            if ($el['text'] !== '' && ! $this->ribbonTextFits((float) $el['w'], (float) $el['h'], (float) $el['fontSize'], (float) $el['padding'], (float) $el['letterSpacing'], $upper, (string) $el['text'])) {
                $out[] = "{$tag}: the ribbon words do not fit the band — use fewer words, a smaller fontSize or a bigger box.";
            }
        }

        if ($field === 'qr') {
            if ($el['w'] < self::MIN_QR_SIZE || $el['h'] < self::MIN_QR_SIZE) {
                $out[] = "{$tag}: a QR code must be at least " . self::MIN_QR_SIZE . '×' . self::MIN_QR_SIZE . ' pixels or phones cannot scan it.';
            }
            $problem = $this->qrColourProblem((string) $el['qrDark'], (string) $el['qrLight']);
            if ($problem !== null) {
                $out[] = "{$tag}: {$problem}";
            }
        }

        if ($field === 'feature_pills') {
            $min = $this->pillsMinHeight((float) $el['fontSize'], (float) $el['padding'], (string) $el['pillDirection'], (int) $el['pillCount'], (int) $el['pillGap']);
            if ($el['h'] < $min) {
                $out[] = "{$tag}: the pills need a box at least {$min} pixels tall for pillCount {$el['pillCount']}, pillDirection {$el['pillDirection']}.";
            }
        }

        if (in_array($el['fontFamily'], self::SCRIPT_FONTS, true) && $this->isTextField($field) && $field !== 'watermark') {
            if (! in_array($field, self::SCRIPT_OK_FIELDS, true)) {
                $out[] = "{$tag}: the script font {$el['fontFamily']} is for short headlines only (" . implode(', ', self::SCRIPT_OK_FIELDS) . '), not for this element.';
            } else {
                if (in_array($field, ['custom_text', 'badge'], true) && mb_strlen((string) $el['text']) > self::MAX_SCRIPT_TEXT) {
                    $out[] = "{$tag}: a script font is only readable on at most " . self::MAX_SCRIPT_TEXT . ' characters.';
                }
                if ($el['fontSize'] < self::MIN_SCRIPT_FONT_SIZE) {
                    $out[] = "{$tag}: a script font needs fontSize of at least " . self::MIN_SCRIPT_FONT_SIZE . '.';
                }
            }
        }

        return $out;
    }

    /**
     * Why these two QR colours would not scan reliably, or null when they will. Rule (stated in the prompt too): both must be
     * solid colours, the "dark" one must be darker than the "light" one, and the two must differ by a contrast ratio of at
     * least MIN_QR_CONTRAST. (Phone scanners read the code as dark modules on a light ground.)
     */
    private function qrColourProblem(string $dark, string $light): ?string
    {
        $d = $this->parseColor($dark);
        $l = $this->parseColor($light);
        if ($d === null || $l === null) {
            return null; // already reported as a colour error
        }
        if ($d[3] < 1.0 || $l[3] < 1.0) {
            return 'qrDark and qrLight must be solid colours (no transparency).';
        }
        $ld = $this->luminance([$d[0], $d[1], $d[2]]);
        $ll = $this->luminance([$l[0], $l[1], $l[2]]);
        if ($ld >= $ll) {
            return 'qrDark must be darker than qrLight (a phone reads dark squares on a light ground).';
        }
        $ratio = ($ll + 0.05) / ($ld + 0.05);
        if ($ratio < self::MIN_QR_CONTRAST) {
            return sprintf('qrDark on qrLight has contrast %.1f:1 — it must be at least %.1f:1 or phones will not scan it.', $ratio, self::MIN_QR_CONTRAST);
        }

        return null;
    }

    /**
     * Do the ribbon's words fit the visible part of its band? The band runs corner to corner across 75% of the box; the
     * width of the words is ESTIMATED from the character count (the kernel draws the real thing), so this is a guard
     * against a clearly-too-long label, not a typesetting engine.
     */
    private function ribbonTextFits(float $w, float $h, float $fs, float $pad, float $letterSpacing, bool $upper, string $text): bool
    {
        $usable = min($w, $h) * 0.75 * M_SQRT2 - ($fs * 1.5 + 2 * $pad);
        $est    = mb_strlen($text) * $fs * (($upper ? 0.68 : 0.58) + $letterSpacing);

        return $est <= $usable;
    }

    /** The least height a feature-pill box needs for its pills (a row wraps, so one row is the floor; a column stacks them). */
    private function pillsMinHeight(float $fs, float $pad, string $direction, int $count, int $gap): int
    {
        $one = (int) round($fs * 1.2) + 2 * (int) round($pad * 0.6);

        return $direction === 'column' ? $count * $one + max(0, $count - 1) * $gap : $one;
    }

    /** Cut to at most $max characters at a word boundary when there is one (no ellipsis: a ribbon is a label). */
    private function cutAtWord(string $text, int $max): string
    {
        $cut = mb_substr($text, 0, $max);
        if (mb_strlen($text) > $max && ($sp = mb_strrpos($cut, ' ')) !== false && $sp >= (int) ($max / 2)) {
            $cut = mb_substr($cut, 0, $sp);
        }

        return rtrim($cut);
    }

    /**
     * The closest real collage layout to whatever the model wrote ("3_hero_right", "grid of 4", "two stacked"): first by the
     * number of photos it names, then by the layout words. Never throws; with no clue at all it is the builder's default.
     */
    private function nearestCollagePreset(string $value): string
    {
        $v = strtolower(trim($value));
        $n = null;
        if (preg_match('/\d/', $v, $m) === 1) {
            $n = (int) $m[0];
        } else {
            foreach (['two' => 2, 'three' => 3, 'four' => 4, 'five' => 5] as $word => $digit) {
                if (str_contains($v, $word)) {
                    $n = $digit;
                    break;
                }
            }
        }
        $n = $n === null ? null : max(2, min(5, $n));

        $synonyms = ['side' => ['side', 'beside', 'horizontal', 'row', 'duo'], 'stack' => ['stack', 'stacked', 'vertical', 'column'],
            'top' => ['top', 'above', 'upper'], 'left' => ['left', 'right'], 'grid' => ['grid', 'tile', 'tiles', 'quad'], 'hero' => ['hero', 'main', 'big', 'large', 'feature']];
        $tokens = preg_split('/[^a-z]+/', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $best      = null;
        $bestScore = -1;
        foreach (self::COLLAGE_PRESETS as $preset) {
            if ($n !== null && (int) $preset[0] !== $n) {
                continue;
            }
            $score = 0;
            foreach (explode('_', $preset) as $word) {
                if (ctype_digit($word)) {
                    continue;
                }
                foreach ($synonyms[$word] ?? [$word] as $syn) {
                    if (in_array($syn, $tokens, true)) {
                        $score++;
                        break;
                    }
                }
            }
            if ($score > $bestScore) {
                $best      = $preset;
                $bestScore = $score;
            }
        }

        return ($n === null && $bestScore <= 0) ? '3_hero_left' : ($best ?? '3_hero_left');
    }

    /**
     * Rewrite the colour spellings the model commonly uses into the strict forms we accept: plain
     * names (from a fixed table), #rgba / #rrggbbaa, and rgb()/rgba() with spaces or percentages
     * are NOT guessed at. A value this cannot rewrite is returned unchanged for the validator to refuse.
     */
    private function tidyColor(string $value): string
    {
        $v = trim($value);
        if ($this->parseColor($v) !== null) {
            return $v;
        }
        $lower = strtolower($v);
        if (isset(self::NAMED_COLORS[$lower])) {
            return self::NAMED_COLORS[$lower];
        }
        if (preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $v, $m)) {
            return sprintf('rgba(%d,%d,%d,%s)', hexdec($m[1]), hexdec($m[2]), hexdec($m[3]), $this->alpha(hexdec($m[4]) / 255));
        }
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])([0-9a-f])$/i', $v, $m)) {
            return sprintf('rgba(%d,%d,%d,%s)', hexdec($m[1] . $m[1]), hexdec($m[2] . $m[2]), hexdec($m[3] . $m[3]), $this->alpha(hexdec($m[4] . $m[4]) / 255));
        }

        return $v;
    }

    private function alpha(float $a): string
    {
        $a = round(max(0.0, min(1.0, $a)), 2);

        return $a >= 1.0 ? '1' : ($a <= 0.0 ? '0' : rtrim(rtrim(number_format($a, 2), '0'), '.'));
    }

    /**
     * The hard limits, as plain sentences for the model's instructions. Every number comes from
     * this class's own constants, so the prompt can never promise a limit the validator does not
     * enforce (or the other way round).
     */
    public function promptLimits(int $cw, int $ch, array $forbidden = []): string
    {
        // Decision 20: an extra the user did not tick (qr, collage, ribbon, show_day, feature_pills) is not mentioned at all.
        $on      = static fn (string $field): bool => ! in_array($field, $forbidden, true);
        $geoNames = array_keys(array_filter(['ribbons' => 'ribbon', 'pills' => 'feature_pills', 'the QR code' => 'qr'], $on));
        $lastGeo  = $geoNames === [] ? null : array_pop($geoNames);
        $geoList  = $lastGeo === null ? '' : ', ' . ($geoNames === [] ? $lastGeo : implode(', ', $geoNames) . ' and ' . $lastGeo) . ' included';
        $t = self::EDGE_TOLERANCE;
        $inset  = self::EXAMPLE_INSET;
        $insetW = $cw - 2 * $inset;
        $ranges = [];
        foreach (self::NUMBERS as $k => [$min, $max]) {
            $ranges[] = "{$k} {$min} to {$max}";
        }
        // AT-456 §B.9-2: the AI is told every allowed word, straight from ENUMS (no separate, hand-kept "prompt" lists).
        $enum = static fn (string $k): string => $k . ': ' . implode(' / ', self::ENUMS[$k]);
        $taughtEnums = array_keys(self::ENUMS);

        $min = self::MIN_FONT_SIZE;
        $con = self::MIN_CONTRAST;
        $bm  = self::BACKDROP_MIN_ALPHA;
        $own = implode(' and ', self::OWN_BACKGROUND_FIELDS);
        $okScript = implode(', ', self::SCRIPT_OK_FIELDS);
        $script   = implode(' / ', self::SCRIPT_FONTS);

        return "HARD LIMITS - every option is checked by a program. One broken rule throws the whole option away, so check your own numbers before you answer:\n"
            . "1. Geometry: EVERY element - divider lines, shapes, gradients{$geoList}, not only photos and text - must lie fully inside the canvas: x >= 0, y >= 0, x + w <= {$cw}, y + h <= {$ch}. "
            . "{$t}px of slack is tolerated; never rely on it. w and h are at least 4. "
            . "A line or block across the whole width is x=0 with w={$cw}; across the whole height is y=0 with h={$ch}; inset by a margin of {$inset}px it is x={$inset} with w={$insetW} (the width MINUS the margin on BOTH sides). "
            . "The usual mistake is a divider line given the full width ({$cw}) but an x greater than 0, which runs past the right edge (x + w is then more than {$cw}).\n"
            . "1b. CHECK BEFORE YOU ANSWER: for every element in every option, add x + w and y + h; the first must be at most {$cw} and the second at most {$ch}, and x and y must not be negative. Fix any element that fails before you write the answer.\n"
            . '2. Count: between ' . self::MIN_ELEMENTS . ' and ' . self::MAX_ELEMENTS . " elements per option, and at least one property photo (image_1 to image_5).\n"
            . "3. Font size: fontSize is at least {$min} on every text element (the watermark is exempt). Never write a smaller number.\n"
            . "4. Contrast: text must reach {$con}:1 against what is directly behind it (its own pill, else the nearest block/shape/gradient under it, else the canvas). "
            . "A pill (bgColor + bgOpacity), block or gradient stop only counts as 'behind the text' when its opacity is {$bm} or more; use 0.8 or more. "
            . "A see-through background is checked over BOTH white and black, so light text on a pale or faint overlay fails. Text over a photo with nothing under it fails unless shadowOn is true.\n"
            . '4b. Colours: only #rgb, #rrggbb, rgb(r,g,b) or rgba(r,g,b,a). No colour names, no hsl(), no 8-digit hex, no url(), var() or gradients written as colours. Canvas colours (canvasBg, canvasBgFrom, canvasBgTo) must be fully opaque.' . "\n"
            . '5. Words: custom_text and badge need a non-empty "text" of at most ' . self::MAX_TEXT_LEN . ' characters, plain words, no markup. The option "name" is at most ' . self::MAX_NAME_LEN . " characters.\n"
            . '6. Watermark: opacity at most ' . self::WATERMARK_MAX_OPACITY . ".\n"
            . '7. Allowed numeric ranges: ' . implode('; ', $ranges) . ".\n"
            . '8. Allowed words: ' . implode('; ', array_map($enum, $taughtEnums)) . '. fontWeight is a string such as "700".' . "\n"
            . "9. {$own} sit on their OWN colour (bgColor + bgOpacity): bgOpacity must be {$bm} or more (use 0.9 or 1) and their text colour must pass rule 4 against that colour.\n"
            . ($on('ribbon') ? '10. ribbon: "text" is required and at most ' . self::MAX_RIBBON_TEXT . ' characters (e.g. JUST LISTED, SOLD, NEW PRICE); w and h at least ' . self::MIN_RIBBON_SIZE
            . '; the words must fit along the band, which is about 0.75 x the shorter box side x 1.41 long minus the band thickness (fontSize x 1.5 + 2 x padding) - about 0.75 x fontSize pixels per capital letter. A 200 x 200 ribbon at fontSize 18 holds about 12 capitals.' . "\n" : '')
            . ($on('qr') ? '11. qr: w and h at least ' . self::MIN_QR_SIZE . ' (a smaller code cannot be scanned). qrDark and qrLight must be solid colours, qrDark darker than qrLight, with a contrast of at least '
            . self::MIN_QR_CONTRAST . ':1 (black #000000 on white #ffffff is always safe). Keep other elements off the QR.' . "\n" : '')
            . "12. Script fonts ({$script}) are for a few headline words only: allowed on {$okScript}; at most " . self::MAX_SCRIPT_TEXT . ' characters; fontSize at least ' . self::MIN_SCRIPT_FONT_SIZE
            . ". Never on a price, phone number, address, listing fact or any body text.\n"
            . ($on('feature_pills') ? '13. feature_pills: the box must be tall enough: one pill is about fontSize x 1.2 + padding x 1.2 pixels high; a column needs pillCount of those plus the pillGap between them. You cannot choose which features show - the listing supplies its own.' . "\n" : '')
            . '14. Photo effects, masks' . ($on('collage') ? ' and collages' : ' ') . ' apply to photos only (image_1 to image_5' . ($on('collage') ? ', collage); a collage counts as a property photo.' : ').')
            . ' duoColor1 / duoColor2 (only used when photoEffect is duotone) follow the colour rule in 4b.' . "\n";
    }

    // ─────────────────────────────────────────────────────────────────────
    // Compact examples (AT-456 §B.9-2 item 11: "Match our existing templates")
    // ─────────────────────────────────────────────────────────────────────

    /**
     * A saved layout reduced to what the model needs to see as an example of the agency's look, in the same sparse shape it is
     * asked to answer in: ids, labels, builder-only keys, media (custom image / video / agent 2, and every uploaded file's
     * address) and every property that simply equals the starting value are left out. Colours that would not pass this class's
     * own colour check, and enum values it would refuse, are left out too (the model must never be shown something it would be
     * rejected for copying). Text is stripped of markup and cut to the same limits as an answer.
     *
     * @param  array<string, mixed> $layout  a saved layout_json (the default design; variants are ignored)
     * @param  int                  $maxElements  at most this many elements are kept (the largest, then by stacking order)
     * @return array<string, mixed>|null  null when it has no usable element
     */
    public function compactExample(array $layout, int $maxElements = 24): ?array
    {
        $elements = [];
        foreach ((array) ($layout['elements'] ?? []) as $i => $el) {
            if (! is_array($el)) {
                continue;
            }
            $c = $this->compactElement($el);
            if ($c !== null) {
                $elements[] = ['area' => ($c['w'] * $c['h']), 'z' => $c['zIndex'], 'i' => $i, 'el' => $c];
            }
        }
        if ($elements === []) {
            return null;
        }
        if (count($elements) > $maxElements) {
            usort($elements, fn ($a, $b) => [$b['area'], $a['i']] <=> [$a['area'], $b['i']]);
            $elements = array_slice($elements, 0, $maxElements);
        }
        usort($elements, fn ($a, $b) => [$a['z'], $a['i']] <=> [$b['z'], $b['i']]);

        $out = [];
        foreach (['canvasBg', 'canvasBgFrom', 'canvasBgTo'] as $k) {
            if (isset($layout[$k]) && is_string($layout[$k]) && $this->parseColor($layout[$k]) !== null) {
                $out[$k] = trim($layout[$k]);
            }
        }
        $out['canvasBgMode'] = ($layout['canvasBgMode'] ?? 'solid') === 'gradient' ? 'gradient' : 'solid';
        if ($out['canvasBgMode'] === 'gradient' && isset($layout['canvasBgAngle']) && is_numeric($layout['canvasBgAngle'])) {
            $out['canvasBgAngle'] = (int) round((float) $layout['canvasBgAngle']);
        }
        $out['elements'] = array_map(fn ($e) => $e['el'], $elements);

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function compactElement(array $el): ?array
    {
        $field = $el['field'] ?? null;
        if (! is_string($field) || ! isset(self::FIELDS[$field]) || ($el['hidden'] ?? false)) {
            return null;     // custom image / video / agent 2 / unknown: never shown to the model
        }
        if (! isset($el['x'], $el['y'], $el['w'], $el['h']) || ! is_numeric($el['x']) || ! is_numeric($el['y']) || ! is_numeric($el['w']) || ! is_numeric($el['h'])) {
            return null;
        }

        $base = $this->baseElement(1, $field);
        $out  = ['field' => $field, 'x' => (int) round((float) $el['x']), 'y' => (int) round((float) $el['y']),
            'w' => (int) round((float) $el['w']), 'h' => (int) round((float) $el['h']),
            'zIndex' => is_numeric($el['zIndex'] ?? null) ? (int) round((float) $el['zIndex']) : 1];

        $same = static fn ($a, $b): bool => (string) (is_bool($a) ? (int) $a : $a) === (string) (is_bool($b) ? (int) $b : $b);

        foreach (self::NUMBERS as $k => [$min, $max, $dec]) {
            if (isset($el[$k]) && is_numeric($el[$k]) && ! $same(round((float) $el[$k], $dec), $base[$k]) && (float) $el[$k] >= $min && (float) $el[$k] <= $max) {
                $out[$k] = round((float) $el[$k], $dec) == (int) round((float) $el[$k], $dec) ? (int) round((float) $el[$k], $dec) : round((float) $el[$k], $dec);
            }
        }
        foreach (self::COLOR_KEYS as $k) {
            if (isset($el[$k]) && is_string($el[$k]) && $this->parseColor($el[$k]) !== null && ! $same(trim($el[$k]), $base[$k])) {
                $out[$k] = trim($el[$k]);
            }
        }
        foreach (self::ENUMS as $k => $allowed) {
            if (isset($el[$k]) && is_scalar($el[$k]) && in_array((string) $el[$k], $allowed, true) && ! $same($el[$k], $base[$k])) {
                $out[$k] = (string) $el[$k];
            }
        }
        foreach (['shadowOn', 'showLabel'] as $k) {
            if (isset($el[$k]) && is_bool($el[$k]) && $el[$k] !== $base[$k]) {
                $out[$k] = $el[$k];
            }
        }
        if (in_array($field, ['custom_text', 'badge', 'watermark', 'ribbon'], true)) {
            $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags((string) ($el['text'] ?? ''))));
            $max  = $field === 'ribbon' ? self::MAX_RIBBON_TEXT : self::MAX_TEXT_LEN;
            if ($text !== '') {
                $out['text'] = mb_substr($text, 0, $max);
            }
        }

        return $out;
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
        if (array_key_exists('showLabel', $raw)) {
            $el['showLabel'] = filter_var($raw['showLabel'], FILTER_VALIDATE_BOOLEAN);
        }

        // Literal copy — only the element types that carry it.
        if (in_array($field, ['custom_text', 'badge', 'watermark', 'ribbon'], true)) {
            $text = trim(strip_tags((string) ($raw['text'] ?? '')));
            $text = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text));
            if ($field !== 'watermark' && $text === '') {
                $local[] = "{$tag}: \"text\" is required.";
            }
            $max = $field === 'ribbon' ? self::MAX_RIBBON_TEXT : self::MAX_TEXT_LEN;
            if (mb_strlen($text) > $max) {
                $local[] = "{$tag}: \"text\" is longer than {$max} characters.";
                $text    = mb_substr($text, 0, $max);
            }
            $el['text'] = $text;
        }

        // Text must stay readable at phone size.
        if ($isText && $field !== 'watermark' && $el['fontSize'] < self::MIN_FONT_SIZE) {
            $local[] = "{$tag}: fontSize {$el['fontSize']} is below the minimum of " . self::MIN_FONT_SIZE . '.';
        }
        if ($field === 'watermark' && $el['opacity'] > self::WATERMARK_MAX_OPACITY) {
            $local[] = "{$tag}: a watermark must be faint (opacity at most " . self::WATERMARK_MAX_OPACITY . ').';
        }
        if (count($geo) === 4) {
            array_push($local, ...$this->blockProblems($el, $tag));
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
            // AT-456 §B.9-1 — defaults for the new per-element properties (all mean "not set"), the keys makeElement() now creates.
            'photoMask' => 'none', 'photoEffect' => 'none', 'duoColor1' => '#0b2a4a', 'duoColor2' => '#f4d58d', 'lineStyle' => 'solid',
            'collagePreset' => '3_hero_left', 'collageGap' => 8, 'collageFirst' => 1, 'ribbonCorner' => 'tl',
            'qrDark' => '#000000', 'qrLight' => '#ffffff', 'pillCount' => 4, 'pillDirection' => 'row', 'pillGap' => 10, 'showLabel' => true,
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
        if ($field === 'ribbon') {
            // The builder's own starting values for a Corner Ribbon.
            $el = array_merge($el, ['w' => 200, 'h' => 200, 'fontSize' => 18, 'fontWeight' => '800', 'textAlign' => 'center', 'textTransform' => 'uppercase',
                'letterSpacing' => 0.08, 'padding' => 6, 'bgColor' => '#e63946', 'bgOpacity' => 1]);
        }
        if ($field === 'feature_pills') {
            // Same as the builder's, except the font starts at the minimum the validator allows (the builder's 15 is below it).
            $el = array_merge($el, ['fontSize' => 16, 'fontWeight' => '700', 'padding' => 8, 'bgColor' => '#00b4d8', 'bgOpacity' => 1, 'borderRadius' => 999]);
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
     * @param array<int, array<string, mixed>> $elements  Updated in place when $repair fixes a text colour / shadow.
     * @param array<int, string>               $canvasStops
     * @param array<int, string>               $errors
     * @param array<int, string>               $repairs
     */
    private function checkContrast(array &$elements, array $canvasStops, array &$errors, bool $repair, array &$repairs): void
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
                    if ($repair) {
                        $elements[$idx]['shadowOn'] = true;
                        $repairs[] = "{$tag}: sat straight on a photo, so a text shadow was switched on.";

                        continue;
                    }
                    $errors[] = "{$tag}: text sits straight on a photo with nothing behind it to keep it readable — put a gradient/colour block under it, give it a pill background (bgColor + bgOpacity 0.6+), or turn shadowOn on.";
                }
                continue;
            }

            $failure = $this->contrastFailure($fg, $bgStops);
            if ($failure === null) {
                continue;
            }

            if ($repair && $bgStops !== []) {
                foreach (['#ffffff', '#000000'] as $candidate) {
                    $alt = $this->parseColor($candidate);
                    $alt[3] *= (float) $el['elOpacity'];
                    if ($this->contrastFailure($alt, $bgStops) === null) {
                        $repairs[] = "{$tag}: text colour {$el['color']} was too faint against what is behind it, so it was changed to {$candidate}.";
                        $elements[$idx]['color'] = $candidate;
                        continue 2;
                    }
                }
            }

            $errors[] = sprintf(
                '%s: text colour %s on %s%s has contrast %.1f:1 — it must be at least %.1f:1.',
                $tag, $el['color'], $failure['label'], $failure['where'], $failure['ratio'], self::MIN_CONTRAST
            );
        }
    }

    /**
     * The first backdrop (over white / over black for see-through ones) the foreground cannot hold
     * 3:1 against, or null when it holds against all of them.
     *
     * @param  array{0:int,1:int,2:int,3:float}                                                        $fg
     * @param  array<int, array{rgba: array{0:int,1:int,2:int,3:float}, label: string}> $layers
     * @return array{ratio: float, label: string, where: string}|null
     */
    private function contrastFailure(array $fg, array $layers): ?array
    {
        foreach ($layers as $layer) {
            // A see-through backdrop sits on something we cannot see (a photo, another
            // block), so it must hold up over BOTH white and black, not just its own RGB.
            foreach ($this->backdropsToTest($layer['rgba']) as $where => $bg) {
                $ratio = $this->contrast($fg, $bg);
                if ($ratio < self::MIN_CONTRAST) {
                    return ['ratio' => $ratio, 'label' => $layer['label'], 'where' => $where];
                }
            }
        }

        return null;
    }

    /**
     * The opaque colours a backdrop must be readable against: itself when it is opaque, otherwise
     * itself composited over white AND over black (the unknown thing underneath).
     *
     * @param  array{0:int,1:int,2:int,3:float} $rgba
     * @return array<string, array{0:int,1:int,2:int,3:float}> keyed by a plain-words suffix for the error
     */
    private function backdropsToTest(array $rgba): array
    {
        $a = max(0.0, min(1.0, $rgba[3]));
        if ($a >= 0.999) {
            return ['' => [$rgba[0], $rgba[1], $rgba[2], 1.0]];
        }

        $over = static fn (int $under): array => [
            (int) round($rgba[0] * $a + $under * (1 - $a)),
            (int) round($rgba[1] * $a + $under * (1 - $a)),
            (int) round($rgba[2] * $a + $under * (1 - $a)),
            1.0,
        ];

        return [
            ' (see-through, over white)' => $over(255),
            ' (see-through, over black)' => $over(0),
        ];
    }

    /**
     * What a text element is drawn on top of: its own pill, else the nearest covering
     * block/shape/gradient below it, else the canvas. null = a photo (unknowable).
     *
     * Each backdrop keeps its real transparency (its colour's own alpha x the element's opacity
     * settings); checkContrast then tests anything see-through over both white and black.
     *
     * @param  array<int, array<string, mixed>> $elements
     * @param  array<int, string>               $canvasStops
     * @return array<int, array{rgba: array{0:int,1:int,2:int,3:float}, label: string}>|null
     */
    private function backgroundBehind(array $elements, int $idx, array $canvasStops): ?array
    {
        $el = $elements[$idx];

        if ((float) $el['bgOpacity'] >= self::BACKDROP_MIN_ALPHA) {
            $pill = $this->layer((string) $el['bgColor'], (float) $el['bgOpacity'] * (float) $el['elOpacity']);

            return $pill === null ? [] : [$pill];
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

            if (in_array($o['field'], ['color_block', 'shape'], true) && $alpha >= self::BACKDROP_MIN_ALPHA) {
                $block = $this->layer((string) $o['bg'], $alpha);

                return $block === null ? [] : [$block];
            }
            if ($o['field'] === 'gradient') {
                $stops = [];
                foreach (['gradFrom', 'gradTo'] as $k) {
                    $layer = $this->layer((string) $o[$k], $alpha);
                    if ($layer !== null && $layer['rgba'][3] >= self::BACKDROP_MIN_ALPHA) {
                        $stops[] = $layer;
                    }
                }
                if ($stops !== []) {
                    return $stops;
                }
            }
            if (in_array($o['field'], self::IMAGE_FIELDS, true) && $o['field'] !== 'agency_logo' && $o['field'] !== 'agent_avatar') {
                return null; // a property photo
            }
            if (in_array($o['field'], ['collage', 'qr'], true)) {
                return null; // a collage is photos; a QR is dark modules on a light square - neither is a readable ground for words
            }
        }

        $canvas = [];
        foreach ($canvasStops as $stop) {
            $layer = $this->layer($stop, 1.0);
            if ($layer !== null) {
                $canvas[] = $layer;
            }
        }

        return $canvas;
    }

    /**
     * A colour string plus extra opacity as a backdrop layer: effective alpha = the colour's own
     * alpha x $extraAlpha. Null for an unreadable colour (already reported elsewhere).
     *
     * @return array{rgba: array{0:int,1:int,2:int,3:float}, label: string}|null
     */
    private function layer(string $colour, float $extraAlpha): ?array
    {
        $c = $this->parseColor($colour);
        if ($c === null) {
            return null;
        }
        $c[3] = max(0.0, min(1.0, $c[3] * $extraAlpha));

        return ['rgba' => $c, 'label' => $colour];
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
