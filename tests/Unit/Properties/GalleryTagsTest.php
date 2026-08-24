<?php

namespace Tests\Unit\Properties;

use App\Models\Property;
use PHPUnit\Framework\TestCase;

/**
 * Property 6060 bug — custom gallery tags the agent created in the web sorter
 * and FILED PHOTOS under vanished from the tag library on reload. The web
 * "smart gallery" save persists those tag names only inside
 * gallery_categories_json (never gallery_custom_tags), but
 * getAvailableGalleryTags() read gallery_custom_tags alone — so a tag with
 * photos disappeared from the list while its images stayed filed.
 *
 * getAvailableGalleryTags() now unions: derived room tags ∪ gallery_custom_tags
 * ∪ category names in use in gallery_categories_json. Pure — no DB.
 */
class GalleryTagsTest extends TestCase
{
    private function property(array $attrs): Property
    {
        return (new Property())->forceFill($attrs);
    }

    public function test_derived_tags_come_from_spaces(): void
    {
        $p = $this->property([
            'spaces_json' => ['spaces' => [
                ['type' => 'Bedroom', 'count' => 2],
                ['type' => 'Garage',  'count' => 1],
            ]],
        ]);

        $this->assertSame(['Bedroom 1', 'Bedroom 2', 'Garage'], $p->getAvailableGalleryTags());
        $this->assertSame(['Bedroom 1', 'Bedroom 2', 'Garage'], $p->derivedGalleryTags());
    }

    public function test_in_use_category_names_are_included_even_without_registry(): void
    {
        // The 6060 shape: custom tags exist ONLY inside gallery_categories_json,
        // gallery_custom_tags is null.
        $p = $this->property([
            'spaces_json'          => ['spaces' => [['type' => 'Bedroom', 'count' => 1]]],
            'gallery_custom_tags'  => null,
            'gallery_categories_json' => ['categories' => [
                ['name' => 'View',       'images' => ['/a.jpg', '/b.jpg']],
                ['name' => 'Store room', 'images' => ['/c.jpg']],
            ], 'unsorted' => []],
        ]);

        $tags = $p->getAvailableGalleryTags();
        $this->assertContains('View', $tags);
        $this->assertContains('Store room', $tags);
        $this->assertContains('Bedroom', $tags); // derived still present
    }

    public function test_registry_and_categories_are_both_merged_and_deduped(): void
    {
        $p = $this->property([
            'spaces_json'         => ['spaces' => [['type' => 'Bedroom', 'count' => 1]]],
            'gallery_custom_tags' => ['View', 'Sunset'],
            'gallery_categories_json' => ['categories' => [
                ['name' => 'View',    'images' => ['/a.jpg']], // dupes the registry entry
                ['name' => 'Balcony', 'images' => ['/b.jpg']], // only in categories
            ], 'unsorted' => []],
        ]);

        $tags = $p->getAvailableGalleryTags();

        // Every source represented, no duplicate 'View'.
        $this->assertContains('Sunset', $tags);   // registry only
        $this->assertContains('Balcony', $tags);  // categories only
        $this->assertSame(1, count(array_filter($tags, fn ($t) => strcasecmp($t, 'View') === 0)));
    }

    public function test_category_name_matching_a_derived_tag_is_not_duplicated(): void
    {
        $p = $this->property([
            'spaces_json'         => ['spaces' => [['type' => 'Bedroom', 'count' => 2]]],
            'gallery_categories_json' => ['categories' => [
                ['name' => 'bedroom 1', 'images' => ['/a.jpg']], // case-insensitive dupe of derived
            ], 'unsorted' => []],
        ]);

        $tags = $p->getAvailableGalleryTags();
        $this->assertSame(1, count(array_filter($tags, fn ($t) => strcasecmp($t, 'Bedroom 1') === 0)));
    }

    /**
     * gallery_tag_order must win over the default "derived tags first, then
     * custom tags" concatenation — including a reorder that moves a derived
     * (room) tag, which gallery_custom_tags alone can never represent.
     */
    public function test_saved_tag_order_overrides_default_derived_then_custom_order(): void
    {
        $p = $this->property([
            'spaces_json'         => ['spaces' => [['type' => 'Bedroom', 'count' => 2], ['type' => 'Garage', 'count' => 1]]],
            'gallery_custom_tags' => ['Sunset'],
            'gallery_tag_order'   => ['Sunset', 'Garage', 'Bedroom 1', 'Bedroom 2'],
        ]);

        $this->assertSame(['Sunset', 'Garage', 'Bedroom 1', 'Bedroom 2'], $p->getAvailableGalleryTags());
    }

    /**
     * A tag that didn't exist yet when the order was last saved (a room
     * added afterwards) must still appear — appended at the end — rather
     * than being hidden because it's absent from gallery_tag_order.
     */
    public function test_tag_not_present_in_saved_order_is_appended_not_dropped(): void
    {
        $p = $this->property([
            'spaces_json'       => ['spaces' => [['type' => 'Bedroom', 'count' => 1], ['type' => 'Garage', 'count' => 1]]],
            'gallery_tag_order' => ['Garage'], // saved before the Bedroom space was added
        ]);

        $this->assertSame(['Garage', 'Bedroom'], $p->getAvailableGalleryTags());
    }

    public function test_no_saved_order_falls_back_to_default_derived_then_custom_order(): void
    {
        $p = $this->property([
            'spaces_json'         => ['spaces' => [['type' => 'Bedroom', 'count' => 1]]],
            'gallery_custom_tags' => ['Sunset'],
            'gallery_tag_order'   => null,
        ]);

        $this->assertSame(['Bedroom', 'Sunset'], $p->getAvailableGalleryTags());
    }
}
