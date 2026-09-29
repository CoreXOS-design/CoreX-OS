<?php

declare(strict_types=1);

namespace Tests\Feature\Images;

use App\Services\Images\PropertyImageStorer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-436 incident, 2026-09-27 — property 5792, cc5: a rental_inspection_photos
 * row whose storage_path pointed at a file that did not exist on disk. Traced
 * to a dev-server process writing to a different filesystem root than the one
 * actually served — not a code-ordering bug (storePhotos() already calls
 * store() before creating the row on every path) — but the fix Johan asked
 * for is real regardless: this service is the one place a caller gets a URL
 * back for a photo record to point at, so it must never hand one back for a
 * file that isn't verifiably there.
 */
final class PropertyImageStorerTest extends TestCase
{
    public function test_store_returns_a_url_whose_file_actually_exists_on_the_same_disk(): void
    {
        Storage::fake('public');

        $url = app(PropertyImageStorer::class)->store(
            UploadedFile::fake()->image('ceiling.jpg', 800, 600),
            5792
        );

        $path = ltrim(parse_url($url, PHP_URL_PATH), '/');
        $path = preg_replace('#^storage/#', '', $path);

        $this->assertTrue(Storage::disk('public')->exists($path));
        $this->assertGreaterThan(0, Storage::disk('public')->size($path));
    }

    public function test_a_path_that_does_not_exist_on_disk_is_refused_rather_than_handed_back_as_a_url(): void
    {
        Storage::fake('public');

        $storer = app(PropertyImageStorer::class);
        $assertStored = new \ReflectionMethod($storer, 'assertStored');
        $assertStored->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $assertStored->invoke($storer, Storage::disk('public'), 'properties/5792/never-actually-written.png', 'in this test');
    }

    public function test_a_zero_byte_file_on_disk_is_also_refused(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('properties/5792/empty.png', '');

        $storer = app(PropertyImageStorer::class);
        $assertStored = new \ReflectionMethod($storer, 'assertStored');
        $assertStored->setAccessible(true);

        $this->expectException(\RuntimeException::class);

        $assertStored->invoke($storer, Storage::disk('public'), 'properties/5792/empty.png', 'in this test');
    }
}
