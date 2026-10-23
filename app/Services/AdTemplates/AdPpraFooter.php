<?php

namespace App\Services\AdTemplates;

/**
 * The compulsory "Registered with the PPRA" line at the bottom of every AI-designed ad (Johan, 2026-10-23).
 *
 * The model is asked to keep the bottom strip free; the server - never the model - then puts the line in, with the
 * wording, position and size fixed here, so it is always present and always spelled correctly. Any element the model
 * made that already mentions the PPRA is taken out first (it would be a duplicate, possibly misspelled). Applied to
 * the raw answer BEFORE AdLayoutValidator, so the line is checked like any other element (overlap, contrast, fit) and a
 * design that crowds it is rejected and retried with the reason. Neutral wording: true of every South African agency.
 */
final class AdPpraFooter
{
    public const TEXT      = 'Registered with the PPRA';
    public const HEIGHT    = 40;
    public const FONT_SIZE = 16;

    /** The prompt lines that reserve the strip (all sizes in canvas pixels). */
    public static function promptBlock(int $w, int $h): string
    {
        $top = $h - self::HEIGHT;

        return "\nCOMPULSORY FOOTER: the server adds the line \"" . self::TEXT . "\" in a full-width strip at the very bottom of every design "
            . "(x 0, y {$top}, w {$w}, h " . self::HEIGHT . "). Do NOT add it yourself and do not mention the PPRA anywhere else. "
            . "Keep every element that shows words or a picture of its own fully above y {$top}; photos, gradients, colour blocks and shapes may run underneath the strip.\n";
    }

    /**
     * @param  array<string, mixed> $item one raw design from the model
     * @return array<string, mixed>
     */
    public static function apply(array $item, int $w, int $h): array
    {
        $kept = [];
        foreach ((array) ($item['elements'] ?? []) as $el) {
            if (is_array($el) && stripos(strip_tags((string) ($el['text'] ?? '')), 'ppra') !== false) {
                continue;
            }
            $kept[] = $el;
        }

        $kept[] = [
            'field' => 'custom_text', 'text' => self::TEXT,
            'x' => 0, 'y' => $h - self::HEIGHT, 'w' => $w, 'h' => self::HEIGHT, 'zIndex' => 100,
            'fontSize' => self::FONT_SIZE, 'fontWeight' => '600', 'color' => '#ffffff',
            'textAlign' => 'center', 'verticalAlign' => 'middle', 'padding' => 6,
            'bgColor' => '#000000', 'bgOpacity' => 0.85,
        ];
        $item['elements'] = $kept;

        return $item;
    }
}
