<?php

declare(strict_types=1);

namespace App\Services\AdTemplates;

/**
 * One worked example, shown to the AI when it is asked to rebuild a reference picture on the Poster size (1000 x 1500): a premium
 * gold-on-black property poster made ONLY from the building blocks the validator allows (spec §22.13).
 *
 * It teaches TECHNIQUE, not a look to copy: how big a brush stroke must be for words to sit on it, that a status badge laid on the
 * stroke needs bgOpacity 0, which way a slanted panel leans, how a gold gradient is written, how a mosaic, an icon strip and an
 * overlapping round agent photo are placed. The prompt tells the model to take its geometry, colours and fonts from the PICTURE it
 * was given and use this only for how to express them. It uses fields, never anyone's words: every agency fills it with its own
 * listing and details (multi-agency rule). Kept valid by tests/Feature/Tools/AdPosterExemplarTest.php.
 */
final class AdPosterExemplar
{
    public const SIZE = 'pinterest';

    /** @return array<string, mixed> the answer-shaped option (what the model returns for one design) */
    public static function option(): array
    {
        $gold  = ['shapeGradient' => true, 'gradFrom' => '#f6e27a', 'gradTo' => '#b8860b', 'gradAngle' => 90];
        $black = '#0a0a0a';
        $el    = static fn (string $field, int $x, int $y, int $w, int $h, array $o = []): array => array_merge(['field' => $field, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h], $o);

        return [
            'name'         => 'Gold and black premium poster',
            'canvasBgMode' => 'solid',
            'canvasBg'     => $black,
            'elements'     => [
                $el('image_1', 0, 0, 1000, 500, ['zIndex' => 1]),
                // Header: a dark panel whose right edge leans in towards the bottom (cut_panel_left), the agency name on it.
                $el('shape', 0, 0, 520, 150, ['shapeType' => 'cut_panel_left', 'bg' => $black, 'zIndex' => 3]),
                $el('agency_name', 28, 28, 400, 90, ['fontFamily' => 'Playfair Display', 'fontSize' => 34, 'fontWeight' => '900', 'color' => '#d4af37', 'textTransform' => 'uppercase', 'letterSpacing' => 0.04, 'zIndex' => 5]),
                // A gold brush stroke with gradient fill. It paints only the middle band of its box, so the box is about 2.5 times the
                // height of the words on it; the status badge and the suburb sit inside that band, the badge with its pill switched off.
                $el('shape', 540, 0, 460, 300, ['shapeType' => 'brush_stroke_1', 'bg' => '#d4af37', 'zIndex' => 3] + $gold),
                $el('status_badge', 580, 84, 380, 70, ['fontFamily' => 'Great Vibes', 'fontSize' => 54, 'fontWeight' => '700', 'color' => '#111111', 'bgOpacity' => 0, 'textAlign' => 'center', 'zIndex' => 6]),
                $el('suburb', 580, 154, 380, 64, ['fontFamily' => 'Playfair Display', 'fontSize' => 22, 'fontWeight' => '700', 'color' => '#111111', 'textTransform' => 'uppercase', 'letterSpacing' => 0.04, 'lineHeight' => 1.1, 'textAlign' => 'center', 'zIndex' => 6]),
                // Location line (the agent's "note for this ad") on a dark bar, price on a dark block with gold rules.
                $el('color_block', 0, 500, 640, 72, ['bg' => $black, 'zIndex' => 3]),
                $el('listing_note', 24, 506, 600, 60, ['fontFamily' => 'Montserrat', 'fontSize' => 20, 'fontWeight' => '700', 'color' => '#ffffff', 'textTransform' => 'uppercase', 'icon' => 'pin', 'iconSize' => 28, 'zIndex' => 6]),
                $el('color_block', 640, 500, 360, 72, ['bg' => $black, 'zIndex' => 3]),
                $el('line', 640, 500, 360, 3, ['color' => '#d4af37', 'zIndex' => 6]),
                $el('line', 640, 569, 360, 3, ['color' => '#d4af37', 'zIndex' => 6]),
                $el('price', 650, 506, 340, 60, ['fontFamily' => 'Playfair Display', 'fontSize' => 44, 'fontWeight' => '900', 'color' => '#d4af37', 'textAlign' => 'center', 'zIndex' => 6]),
                // Six-photo mosaic.
                $el('collage', 0, 576, 1000, 570, ['collagePreset' => '6_mosaic', 'collageGap' => 4, 'zIndex' => 2]),
                // Icon strip on a dark band, gold rings, with gold rules above and below.
                $el('color_block', 0, 1146, 1000, 140, ['bg' => $black, 'zIndex' => 3]),
                $el('line', 0, 1146, 1000, 3, ['color' => '#d4af37', 'zIndex' => 6]),
                $el('feature_strip', 0, 1152, 1000, 128, ['stripCount' => 8, 'fontSize' => 15, 'fontWeight' => '700', 'color' => '#ffffff', 'iconColor' => '#d4af37', 'iconRingColor' => '#d4af37', 'dividerColor' => '#d4af37', 'zIndex' => 6]),
                // Agent panel: round photo with a gold ring overlapping the band above, name / role / phone, tagline and agency on the right.
                $el('color_block', 0, 1286, 1000, 214, ['bg' => $black, 'zIndex' => 3]),
                $el('line', 0, 1286, 1000, 3, ['color' => '#d4af37', 'zIndex' => 6]),
                $el('agent_avatar', 28, 1296, 196, 196, ['shapeType' => 'circle', 'frameBorderWidth' => 5, 'frameBorderColor' => '#d4af37', 'zIndex' => 8]),
                $el('agent_name', 252, 1300, 500, 80, ['fontFamily' => 'Playfair Display', 'fontSize' => 36, 'fontWeight' => '900', 'color' => '#d4af37', 'textTransform' => 'uppercase', 'zIndex' => 6]),
                $el('agent_designation', 252, 1384, 500, 34, ['fontFamily' => 'Montserrat', 'fontSize' => 16, 'fontWeight' => '500', 'color' => '#ffffff', 'textTransform' => 'uppercase', 'letterSpacing' => 0.08, 'zIndex' => 6]),
                $el('agent_phone', 252, 1424, 500, 56, ['fontFamily' => 'Playfair Display', 'fontSize' => 36, 'fontWeight' => '700', 'color' => '#ffffff', 'icon' => 'phone', 'iconSize' => 36, 'zIndex' => 6]),
                $el('tagline', 776, 1320, 216, 90, ['fontFamily' => 'Great Vibes', 'fontSize' => 32, 'color' => '#d4af37', 'textAlign' => 'center', 'zIndex' => 6]),
                $el('agency_name', 776, 1420, 216, 50, ['fontFamily' => 'Montserrat', 'fontSize' => 16, 'fontWeight' => '600', 'color' => '#ffffff', 'textTransform' => 'uppercase', 'letterSpacing' => 0.08, 'textAlign' => 'center', 'zIndex' => 6]),
            ],
        ];
    }
}
