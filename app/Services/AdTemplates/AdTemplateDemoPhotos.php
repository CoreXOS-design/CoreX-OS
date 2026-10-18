<?php

declare(strict_types=1);

namespace App\Services\AdTemplates;

/**
 * The generic demo property photos the "Create with AI" previews (and the full-screen viewer) are drawn with (AT-456 §B.10).
 *
 * Provenance: resized copies (1200 px wide) of photos from the committed demo pool database/seeders/data/demo-properties/ -
 * licensed stock (Unsplash, commercial use, no attribution; see .ai/DEMO_DATA.md "Listing photos"). They are generic stock
 * pictures of houses, rooms and pools - NOT any agency's own listing photos - and live in public/images/ad-demo/ so a browser
 * can load them with no database and no agency data involved. Nothing here may ever be replaced with a real listing's photo.
 *
 * The set has no bedroom picture (the pool has none); the fourth slot shows a bathroom until a licensed bedroom photo is added.
 */
final class AdTemplateDemoPhotos
{
    public const DIR = 'images/ad-demo';

    /** Every file that must exist in public/images/ad-demo/ (name without .jpg). */
    public const FILES = ['exterior', 'living', 'kitchen', 'bathroom', 'pool', 'lawn', 'garden', 'apartments', 'modern'];

    /**
     * Property-type category (AdTemplatePropertyTypes::category()) => the photos image_1..image_5 show, in order.
     * The first photo is the one that fits the kind of property (land opens on a lawn/garden, a flat on an apartment block).
     */
    private const SETS = [
        'default'    => ['exterior', 'living', 'kitchen', 'bathroom', 'pool'],
        'land'       => ['lawn', 'garden', 'pool', 'exterior', 'living'],
        'farm'       => ['lawn', 'garden', 'exterior', 'pool', 'living'],
        'sectional'  => ['apartments', 'living', 'kitchen', 'bathroom', 'pool'],
        'commercial' => ['modern', 'living', 'kitchen', 'exterior', 'pool'],
    ];

    /**
     * image_1 ... image_5 => URL, for a property category ('default' for anything not listed).
     *
     * @return array<string, string>
     */
    public static function set(string $category = 'default'): array
    {
        $names = self::SETS[$category] ?? self::SETS['default'];
        $out   = [];
        foreach ($names as $i => $name) {
            $out['image_' . ($i + 1)] = asset(self::DIR . '/' . $name . '.jpg');
        }

        return $out;
    }

    /**
     * For each of the agency's own property type names: the five photos that type's preview shows.
     * Only types whose set differs from the default are listed.
     *
     * @param  array<int, string> $typeNames
     * @return array<string, array<string, string>>
     */
    public static function forTypes(array $typeNames): array
    {
        $out = [];
        foreach ($typeNames as $name) {
            $category = AdTemplatePropertyTypes::category((string) $name);
            if (isset(self::SETS[$category]) && $category !== 'default') {
                $out[(string) $name] = self::set($category);
            }
        }

        return $out;
    }
}
