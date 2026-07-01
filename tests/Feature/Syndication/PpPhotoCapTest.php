<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Property;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use ReflectionMethod;
use Tests\TestCase;

/**
 * PP photo cap — mirrors the P24 AT-101 treatment. PP was hardcoded to 20
 * images off allImages() (commit d6621111); this proves the cap is now the
 * per-agency value (default 150) sourced from the curated gallery
 * (syndicationImages()), and that the gallery is truncated to the cap when it
 * exceeds it.
 */
class PpPhotoCapTest extends TestCase
{
    private function buildPhotoUrls(Property $property): array
    {
        $m = new ReflectionMethod(PrivatePropertyListingMapper::class, 'buildPhotoUrls');
        $m->setAccessible(true);

        return $m->invoke(new PrivatePropertyListingMapper(), $property);
    }

    private function gallery(int $n): array
    {
        return array_map(fn ($i) => "properties/pp/img{$i}.jpg", range(1, $n));
    }

    public function test_gallery_above_the_old_20_limit_is_no_longer_truncated_to_20(): void
    {
        // 30 curated images, no agency → falls back to PP_DEFAULT_MAX_PHOTOS (150)
        $property = (new Property())->forceFill([
            'gallery_images_json' => $this->gallery(30),
        ]);

        $this->assertCount(30, $this->buildPhotoUrls($property));
    }

    public function test_gallery_is_capped_at_the_agency_max(): void
    {
        // 200 curated images → capped at the 150 default
        $property = (new Property())->forceFill([
            'gallery_images_json' => $this->gallery(200),
        ]);

        $urls = $this->buildPhotoUrls($property);

        $this->assertCount(Agency::PP_DEFAULT_MAX_PHOTOS, $urls);
        $this->assertSame(150, Agency::PP_DEFAULT_MAX_PHOTOS);
    }

    public function test_source_is_the_curated_gallery_not_the_merged_all_images_set(): void
    {
        // gallery has 3; images_json (public mirror) adds 2 more that allImages()
        // would have merged. syndicationImages() must send only the gallery's 3.
        $property = (new Property())->forceFill([
            'gallery_images_json' => $this->gallery(3),
            'images_json'         => ['properties/pp/extra1.jpg', 'properties/pp/extra2.jpg'],
        ]);

        $this->assertCount(3, $this->buildPhotoUrls($property));
    }
}
