<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\ProspectingListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-22 item 2/7 — recover orphaned thumbnails from the portal page og:image.
 *
 * Locks the `prospecting:rehydrate-thumbnails --derive-from-portal` path: for a
 * row with a portal_url but NO source_url and NO file on disk (the live broken
 * state of ~4032 rows), the command fetches the listing page, reads og:image,
 * persists it to thumbnail_source_url, and downloads the image through the
 * content gate. Network is faked end-to-end (portal page via Http::fake; the
 * image itself via an in-memory https stream so the download job reads it without a socket).
 */
final class ThumbnailDeriveFromPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
    }

    /**
     * A continuous-tone PNG served at an https:// URL — a genuine photo for the gate. The portal
     * resolver only accepts http(s) og:image URLs (a data: URI is refused), and the download job
     * reads the image with file_get_contents(), so the https stream wrapper is swapped for an
     * in-memory one for the duration of the test: no socket, and the persisted URL stays short
     * enough for thumbnail_source_url (varchar 255).
     */
    private function photoUrl(): string
    {
        $im = imagecreatetruecolor(120, 90);
        for ($y = 0; $y < 90; $y++) {
            for ($x = 0; $x < 120; $x++) {
                imagesetpixel($im, $x, $y, imagecolorallocate($im, ($x * 7) % 256, ($y * 11) % 256, ($x * $y) % 256));
            }
        }
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        imagedestroy($im);

        $url = 'https://cdn.example.test/derive-photo.png';
        FakeHttpsImageStream::$bodies[$url] = $png;
        if (! $this->httpsWrapperSwapped) {
            stream_wrapper_unregister('https');
            stream_wrapper_register('https', FakeHttpsImageStream::class);
            $this->httpsWrapperSwapped = true;
        }

        return $url;
    }

    private bool $httpsWrapperSwapped = false;

    protected function tearDown(): void
    {
        if ($this->httpsWrapperSwapped) {
            stream_wrapper_restore('https');
        }
        FakeHttpsImageStream::$bodies = [];
        parent::tearDown();
    }

    public function test_derives_source_url_from_portal_and_downloads(): void
    {
        Storage::fake('local');

        $listing = ProspectingListing::create([
            'captured_by_user_id'      => \App\Models\User::factory()->create()->id,
            'first_seen_at'            => now(),
            'last_seen_at'             => now(),
            'portal_source'            => 'pp',
            'portal_ref'               => 'PP-DERIVE-1',
            'portal_url'               => 'https://www.privateproperty.co.za/for-sale/x/T1',
            'address'                  => '5 Baker Road',
            'suburb'                   => 'Margate',
            'price'                    => 1_950_000,
            'property_type'            => 'House',
            'thumbnail_path'           => 'prospecting/thumbnails/pp_PP-DERIVE-1.jpg', // orphan: path set, no file
            'thumbnail_source_url'     => null,
            'thumbnail_blocked_reason' => null,
        ]);

        // The portal page exposes the image URL as og:image.
        $ogImage = $this->photoUrl();
        Http::fake([
            'www.privateproperty.co.za/*' => Http::response(
                '<html><head><meta property="og:image" content="' . $ogImage . '"></head></html>',
                200
            ),
        ]);

        $this->artisan('prospecting:rehydrate-thumbnails', [
            '--derive-from-portal' => true,
            '--sync'               => true,
            '--throttle-ms'        => 0,
        ])->assertSuccessful();

        $listing->refresh();
        $this->assertSame($ogImage, $listing->thumbnail_source_url, 'og:image must be persisted as source_url');
        $this->assertNull($listing->thumbnail_blocked_reason, 'a genuine photo must not be blocked');
        Storage::disk('local')->assertExists($listing->thumbnail_path);
    }

    public function test_row_with_no_portal_url_is_left_unrecovered(): void
    {
        Storage::fake('local');
        Http::fake();

        $listing = ProspectingListing::create([
            'captured_by_user_id'      => \App\Models\User::factory()->create()->id,
            'first_seen_at'            => now(),
            'last_seen_at'             => now(),
            'suburb'                   => 'Margate',
            'portal_source'        => 'pp',
            'portal_ref'           => 'PP-DERIVE-2',
            'portal_url'           => null, // no recovery path
            'address'              => 'Unknown',
            'price'                => 1_000_000,
            'property_type'        => 'House',
            'thumbnail_path'       => 'prospecting/thumbnails/pp_PP-DERIVE-2.jpg',
            'thumbnail_source_url' => null,
        ]);

        $this->artisan('prospecting:rehydrate-thumbnails', [
            '--derive-from-portal' => true,
            '--sync'               => true,
            '--throttle-ms'        => 0,
        ])->assertSuccessful();

        $listing->refresh();
        $this->assertNull($listing->thumbnail_source_url);
        Storage::disk('local')->assertMissing($listing->thumbnail_path);
    }
}

/** In-memory stand-in for the https stream wrapper (see ThumbnailDeriveFromPortalTest::photoUrl()). */
final class FakeHttpsImageStream
{
    /** @var array<string,string> url => body */
    public static array $bodies = [];

    /** @var resource|null */
    public $context;

    private string $data = '';
    private int $pos = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        if (! isset(self::$bodies[$path])) {
            return false;
        }
        $this->data = self::$bodies[$path];
        $this->pos = 0;

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $chunk = substr($this->data, $this->pos, $count);
        $this->pos += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->pos >= strlen($this->data);
    }

    /** @return array<string,int>|false */
    public function stream_stat(): array|false
    {
        return ['size' => strlen($this->data)];
    }

    public function stream_close(): void
    {
    }
}
