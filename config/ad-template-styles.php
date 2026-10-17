<?php

/**
 * AI Template Builder — the built-in style library (AT-456 §B.9, item 10).
 *
 * Eight neutral looks the agent can pick on "Create with AI" (or leave on "Let the AI choose"). They are code, not an
 * agency setting: nothing here belongs to any one agency, so they are NOT in the Setup Wizard. Every style is written for
 * "the agency's own brand colours" — the colours themselves always come from the requesting agency's record at generation
 * time (AiTemplateBuilderService::brandBlock) and a style never brings a palette of its own.
 *
 * Shape of an entry (checked by App\Services\AdTemplates\AdTemplateStyles::problems(), and by a test, so a typo here fails
 * the build instead of confusing the AI):
 *   name          shown to the agent
 *   summary       one sentence, shown to the agent and the AI
 *   fonts         one or more pairs ['headline' => …, 'body' => …] — every name must be in AdLayoutValidator's font list;
 *                 optional 'accent_script' (Great Vibes / Dancing Script only) for one short phrase
 *   brand         how the agency's brand colours are used (accent rules)
 *   photo         ['min' => 0..1, 'max' => 0..1, 'placement' => words] share of the canvas the property photo(s) take
 *   text          where and how the text is placed
 *   sizes         the ad sizes it suits (keys of AdLayoutValidator::SIZES)
 *   blocks        the new building blocks that suit it: an element type (e.g. ribbon) or "property=value" (e.g. photoMask=arch)
 *   avoid         what to keep out of this look
 */
return [

    'luxury' => [
        'name'    => 'Luxury',
        'summary' => 'Quiet, high-end and unhurried: one big photograph, fine type, lots of space.',
        'fonts'   => [
            ['headline' => 'Cormorant Garamond', 'body' => 'Raleway'],
            ['headline' => 'DM Serif Display', 'body' => 'Inter'],
        ],
        'accent_script' => 'Great Vibes',
        'brand'   => 'Use the darkest brand colour (or near-black) as the ground and the main brand colour as ONE restrained accent: the price, a thin rule and the logo. Everything else is white, ivory or soft grey. Never a second accent colour.',
        'photo'   => ['min' => 0.6, 'max' => 0.78, 'placement' => 'one dominant photo, full bleed on one side or the whole top; a calm panel for the words'],
        'text'    => 'Few lines, generous letter-spacing (0.05 to 0.15 em), upper-case small labels, a large light-weight serif for the title, the price clear but not shouting. Put words on a solid dark panel or a strong gradient, never straight on the photo.',
        'sizes'   => ['facebook', 'instagram', 'story', 'whatsapp'],
        'blocks'  => ['line', 'qr', 'photoMask=arch', 'photoEffect=warm', 'frameBorderWidth=2', 'show_day'],
        'avoid'   => 'Loud colours, badges, ribbons, collages, more than two font families, anything crowded.',
    ],

    'modern_minimal' => [
        'name'    => 'Modern minimal',
        'summary' => 'Clean and airy: a lot of white space, one accent colour, tidy left-aligned type.',
        'fonts'   => [
            ['headline' => 'Space Grotesk', 'body' => 'Inter'],
            ['headline' => 'Work Sans', 'body' => 'Work Sans'],
        ],
        'brand'   => 'A light or white ground with the main brand colour as the single accent (price, one thin line, small icons). Text is near-black or the dark brand colour. No gradients across the whole canvas.',
        'photo'   => ['min' => 0.5, 'max' => 0.68, 'placement' => 'a clean rectangle or rounded photo with clear margins; never touching the text'],
        'text'    => 'Left-aligned, strict grid, big title, small quiet details. Icons (bed, bath, garage, pin) before the numbers. Plenty of empty space between groups.',
        'sizes'   => ['facebook', 'instagram', 'whatsapp'],
        'blocks'  => ['line', 'feature_pills', 'qr', 'photoMask=rounded', 'erf_size', 'price_per_m2'],
        'avoid'   => 'Ribbons, blobs, waves, drop shadows, more than one accent colour.',
    ],

    'bold_sale' => [
        'name'    => 'Bold sale',
        'summary' => 'High energy and impossible to scroll past: huge price, strong colour blocks, bold badges.',
        'fonts'   => [
            ['headline' => 'Anton', 'body' => 'Work Sans'],
            ['headline' => 'Bebas Neue', 'body' => 'Montserrat'],
        ],
        'brand'   => 'Fill big blocks with the main brand colour and use the accent / highlight brand colour for the price bar and the badges. White or near-black text on those blocks. Two brand colours at full strength; the rest neutral.',
        'photo'   => ['min' => 0.45, 'max' => 0.62, 'placement' => 'a strong photo with a heavy colour block carrying the price over or beside it'],
        'text'    => 'Very large upper-case price (60 px or more on a big canvas) and headline, short punchy labels, text on solid colour blocks.',
        'sizes'   => ['facebook', 'instagram', 'story', 'whatsapp'],
        'blocks'  => ['ribbon', 'badge', 'status_badge', 'show_day', 'auction_date', 'shape'],
        'avoid'   => 'Thin fonts, pale colours, script fonts, subtle grey-on-grey text.',
    ],

    'coastal' => [
        'name'    => 'Coastal',
        'summary' => 'Light, relaxed and breezy: soft curves, sandy neutrals and a sea-toned accent.',
        'fonts'   => [
            ['headline' => 'Quicksand', 'body' => 'Nunito'],
            ['headline' => 'Lora', 'body' => 'Raleway'],
        ],
        'brand'   => 'Light sand, white and soft grey as the ground; the main brand colour as the "sea" tone for curves, the price and icons; the accent colour sparingly. Never invent blues or greens that are not in the brand colours (use a tint of a brand colour instead).',
        'photo'   => ['min' => 0.55, 'max' => 0.72, 'placement' => 'a large bright photo, often with a curved or wave edge where it meets the colour'],
        'text'    => 'Centred or left-aligned, relaxed spacing, dark text on light grounds, friendly weights (500 to 700).',
        'sizes'   => ['facebook', 'instagram', 'story', 'whatsapp'],
        'blocks'  => ['shapeType=wave', 'shapeType=curve_bottom', 'shapeType=arc', 'photoMask=rounded', 'photoEffect=brighter', 'icon=sea_view', 'icon=beach', 'shapeType=blob_1'],
        'avoid'   => 'Dark heavy blocks, condensed fonts, sharp corners everywhere.',
    ],

    'family_home' => [
        'name'    => 'Family home',
        'summary' => 'Warm and welcoming: friendly rounded type, soft shapes, the practical details up front.',
        'fonts'   => [
            ['headline' => 'Nunito', 'body' => 'Nunito'],
            ['headline' => 'Poppins', 'body' => 'Nunito'],
        ],
        'brand'   => 'A warm, light ground; the main brand colour for the headline and badges; the accent colour for the price and one shape. Keep it friendly: rounded corners everywhere (borderRadius 16 or more).',
        'photo'   => ['min' => 0.5, 'max' => 0.7, 'placement' => 'one large photo with rounded corners'],
        'text'    => 'Bedrooms, bathrooms and garages as icons with numbers; key features as short labelled lines; a short friendly title. Left-aligned, comfortable spacing.',
        'sizes'   => ['facebook', 'instagram', 'whatsapp'],
        'blocks'  => ['feature_pills', 'collage', 'pet_friendly', 'photoMask=rounded', 'icon=bed', 'icon=garden', 'show_day'],
        'avoid'   => 'Anything corporate or aggressive: condensed fonts, hard black blocks, sharp polygons.',
    ],

    'rental' => [
        'name'    => 'Rental',
        'summary' => 'Straight to the point for lets: the rent, when it is free, what is included, how to enquire.',
        'fonts'   => [
            ['headline' => 'Poppins', 'body' => 'Inter'],
            ['headline' => 'Montserrat', 'body' => 'Inter'],
        ],
        'brand'   => 'The main brand colour for a clear rent bar and the call to action; white or very light ground; the accent colour for one highlight (e.g. the availability line).',
        'photo'   => ['min' => 0.5, 'max' => 0.65, 'placement' => 'a bright photo; the rent bar sits directly under or over it'],
        'text'    => 'The rent (with its period) is the biggest text. Under it: availability, pet friendly, bedrooms and bathrooms as icons. Short, scannable, one idea per line. The phone number is the call to action.',
        'sizes'   => ['instagram', 'whatsapp', 'story', 'facebook'],
        'blocks'  => ['rent_per_month', 'available_from', 'pet_friendly', 'feature_pills', 'qr', 'icon=pet_friendly', 'icon=calendar'],
        'avoid'   => 'Sale wording or sale-only facts (price per m2, levy), flowery type, anything that makes the rent hard to find.',
    ],

    'commercial' => [
        'name'    => 'Commercial',
        'summary' => 'Structured and businesslike: a clear grid, hard numbers, nothing decorative.',
        'fonts'   => [
            ['headline' => 'Montserrat', 'body' => 'Work Sans'],
            ['headline' => 'Barlow Condensed', 'body' => 'Inter'],
        ],
        'brand'   => 'The dark brand colour for the structure and header band, the main brand colour for the key figure, white and grey elsewhere. Square corners; thin rules in the brand colour.',
        'photo'   => ['min' => 0.45, 'max' => 0.6, 'placement' => 'a photo in a hard-edged rectangle, with a data panel beside or below it'],
        'text'    => 'The numbers carry the ad: size, erf size, price per square metre, rates and levies, zoning-style facts as a tidy two-column list. Upper-case small labels, tabular feel.',
        'sizes'   => ['facebook', 'instagram', 'whatsapp'],
        'blocks'  => ['erf_size', 'price_per_m2', 'rates_taxes', 'levy', 'size_or_land', 'line', 'qr', 'auction_date'],
        'avoid'   => 'Rounded blobs, waves, script fonts, icons for the sake of it, emotive language.',
    ],

    'classic' => [
        'name'    => 'Classic',
        'summary' => 'Traditional and trustworthy: serif type, centred layout, a fine frame around the photo.',
        'fonts'   => [
            ['headline' => 'Playfair Display', 'body' => 'Lora'],
            ['headline' => 'Libre Baskerville', 'body' => 'Raleway'],
        ],
        'brand'   => 'A cream, white or deep brand-coloured ground; the main brand colour for headings and the frame; the accent colour only for a thin rule or the status badge. Muted and balanced.',
        'photo'   => ['min' => 0.5, 'max' => 0.68, 'placement' => 'a framed photo (frameBorderWidth 4 to 8) centred in the upper part, the words centred below'],
        'text'    => 'Centred, serif headline and price, small-caps style labels with letter-spacing, a divider line between groups, agent details at the foot.',
        'sizes'   => ['facebook', 'instagram', 'story', 'whatsapp'],
        'blocks'  => ['line', 'frameBorderWidth=6', 'badge', 'status_badge', 'photoMask=rounded', 'lineStyle=dotted'],
        'avoid'   => 'Condensed or script fonts for anything but a single flourish, neon colours, busy shapes.',
    ],

];
