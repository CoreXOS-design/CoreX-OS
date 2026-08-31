<?php

namespace Tests\Feature\Properties;

use App\Models\Property;
use Tests\TestCase;

/**
 * The public agency website showed NO photos for any listing an agent shot
 * themselves (property 15936, 2026-08-28): the blades read `images_json`, a
 * column only the P24 row-image downloader and the sold-listing importer ever
 * write. Older stock happened to have been scraped, which masked it. The blades
 * then wrapped each value in asset('storage/'.$value) even though the stored
 * values already carry `/storage/` or a full host — so the src double-prefixed.
 *
 * publicGalleryUrls() is now the single source for those pages. No DB needed:
 * the method is pure over the model's image columns.
 */
class PublicGalleryUrlsTest extends TestCase
{
    private function property(array $attrs): Property
    {
        return (new Property())->forceFill($attrs);
    }

    public function test_photos_an_agent_uploaded_are_returned_even_with_no_images_json(): void
    {
        // Exactly the shape a mobile/web gallery upload leaves behind.
        $p = $this->property([
            'gallery_images_json' => [
                'https://corexos.co.za/storage/properties/15936/a.jpg',
                'https://corexos.co.za/storage/properties/15936/b.jpg',
            ],
            'images_json' => [],
        ]);

        $this->assertSame([
            'https://corexos.co.za/storage/properties/15936/a.jpg',
            'https://corexos.co.za/storage/properties/15936/b.jpg',
        ], $p->publicGalleryUrls());
    }

    public function test_host_relative_values_are_absolutised_not_double_prefixed(): void
    {
        $p = $this->property([
            'gallery_images_json' => ['/storage/properties/1068/a.jpg'],
            'images_json'         => [],
        ]);

        $urls = $p->publicGalleryUrls();

        $this->assertCount(1, $urls);
        $this->assertSame(asset('storage/properties/1068/a.jpg'), $urls[0]);
        $this->assertStringNotContainsString('storage/storage', $urls[0]);
    }

    public function test_empty_and_non_string_entries_are_dropped(): void
    {
        $p = $this->property([
            'gallery_images_json' => ['', '   ', '/storage/properties/1/a.jpg'],
            'images_json'         => [],
        ]);

        $this->assertSame([asset('storage/properties/1/a.jpg')], $p->publicGalleryUrls());
    }
}
