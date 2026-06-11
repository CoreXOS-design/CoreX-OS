<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Agency;
use App\Models\Property;
use App\Models\User;
use App\Services\Presentations\CompetitorStockMatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-22 item 2 (CRITICAL) — competitor branding strip + shared image gate.
 *
 * Locks the contract that the competition row produced by
 * CompetitorStockMatchService::scoreAndMapRow:
 *   - STILL carries agency_name internally (provenance for the comp-picker
 *     modal and admin surfaces — we never lose the data), AND
 *   - emits a thumbnail ONLY when the file EXISTS on disk and passes the
 *     ListingImageValidator (never a logo) — otherwise null so the neutral
 *     "No photo" placeholder fires (also satisfies item 7).
 *
 * The seller-card JS (review.blade.php) consumes this row and is the surface
 * that drops the agency name; this test guards the row-shape contract that
 * makes both the strip and the image gate correct at the data layer.
 */
final class CompetitorBrandingStripTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(\App\Services\PermissionService::class);
        $seeded = $reflection->getProperty('seeded');
        $seeded->setAccessible(true);
        $seeded->setValue(null, null);
        \App\Models\Role::clearCache();
        parent::tearDown();
    }

    private function seedSubject(int $price, int $beds, string $suburb, string $type): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(4),
            'slug' => 'coastal-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        $subject = Property::create([
            'agency_id'     => $agencyId,
            'branch_id'     => $agencyId,
            'agent_id'      => $user->id,
            'title'         => 'Subject',
            'property_type' => $type,
            'category'      => 'Residential',
            'suburb'        => $suburb,
            'price'         => $price,
            'beds'          => $beds,
            'address'       => '36 Grindewald, Uvongo',
            'status'        => 'active',
            'listing_type'  => 'sale',
        ]);

        return [$subject, $agencyId];
    }

    private function seedListing(
        int $agencyId,
        string $suburb,
        int $price,
        ?int $beds,
        string $type,
        string $agencyName,
        ?string $thumbPath = null,
        ?string $thumbSourceUrl = null
    ): int {
        return (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id'            => $agencyId,
            'captured_by_user_id'  => User::factory()->create(['agency_id' => $agencyId])->id,
            'portal_source'        => 'p24',
            'portal_ref'           => 'P24-' . Str::random(8),
            'portal_url'           => 'https://www.property24.com/' . Str::random(10),
            'address'              => ($beds ?? 0) . 'BR ' . $type . ', ' . $suburb,
            'suburb'               => $suburb,
            'price'                => $price,
            'bedrooms'             => $beds,
            'bathrooms'            => 2,
            'property_size_m2'     => 150,
            'erf_size_m2'          => 500,
            'property_type'        => $type,
            'agent_name'           => null,
            'agency_name'          => $agencyName,
            'thumbnail_path'       => $thumbPath,
            'thumbnail_source_url' => $thumbSourceUrl,
            'first_seen_at'        => now(),
            'last_seen_at'         => now(),
            'is_active'            => 1,
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }

    public function test_row_retains_agency_name_for_internal_provenance(): void
    {
        [$subject, $agencyId] = $this->seedSubject(2_000_000, 3, 'Uvongo', 'House');
        $this->seedListing($agencyId, 'Uvongo', 1_950_000, 3, 'House', 'RE/MAX Coast and Country');

        $matches = (new CompetitorStockMatchService())->findCompetitors($subject)->all();
        $this->assertNotEmpty($matches);

        // agency_name is STILL on the row — provenance is not lost. The
        // seller-facing review card (JS) is what suppresses it.
        $this->assertArrayHasKey('agency_name', $matches[0]);
        $this->assertSame('RE/MAX Coast and Country', $matches[0]['agency_name']);
    }

    public function test_logo_thumbnail_is_not_emitted_so_placeholder_fires(): void
    {
        [$subject, $agencyId] = $this->seedSubject(2_000_000, 3, 'Uvongo', 'House');

        // Even though a file exists on disk, the stored path / source URL is a
        // logo → the image gate must NOT emit it (item 2 — never show a logo).
        $logoPath = 'prospecting/thumbnails/p24_remax_logo.jpg';
        Storage::disk('local')->put($logoPath, 'fake-logo-bytes');

        $this->seedListing(
            $agencyId, 'Uvongo', 1_950_000, 3, 'House', 'RE/MAX',
            thumbPath: $logoPath,
            thumbSourceUrl: 'https://cdn.remax.co.za/brand/logo.png'
        );

        $matches = (new CompetitorStockMatchService())->findCompetitors($subject)->all();
        $this->assertNotEmpty($matches);
        $this->assertNull($matches[0]['thumbnail_abs_path'], 'logo must never become the card image');
        $this->assertNull($matches[0]['thumbnail_url'], 'logo must never become the card image');

        Storage::disk('local')->delete($logoPath);
    }

    public function test_genuine_photo_present_on_disk_is_emitted(): void
    {
        [$subject, $agencyId] = $this->seedSubject(2_000_000, 3, 'Uvongo', 'House');

        $photoPath = 'prospecting/thumbnails/p24_P24-GENUINE-1.jpg';
        Storage::disk('local')->put($photoPath, 'fake-jpeg-bytes');

        $this->seedListing(
            $agencyId, 'Uvongo', 1_950_000, 3, 'House', 'Some Agency',
            thumbPath: $photoPath,
            thumbSourceUrl: 'https://images.prop24.com/247/photo-1.jpg'
        );

        $matches = (new CompetitorStockMatchService())->findCompetitors($subject)->all();
        $this->assertNotEmpty($matches);
        $this->assertNotNull($matches[0]['thumbnail_abs_path'], 'genuine photo on disk must be emitted');
        $this->assertStringContainsString($photoPath, $matches[0]['thumbnail_abs_path']);

        Storage::disk('local')->delete($photoPath);
    }

    public function test_missing_file_is_not_emitted(): void
    {
        [$subject, $agencyId] = $this->seedSubject(2_000_000, 3, 'Uvongo', 'House');

        // Path set but no file on disk (the Laravel 11 orphan case) → null.
        $this->seedListing(
            $agencyId, 'Uvongo', 1_950_000, 3, 'House', 'Some Agency',
            thumbPath: 'prospecting/thumbnails/p24_does-not-exist.jpg',
            thumbSourceUrl: 'https://images.prop24.com/247/photo-2.jpg'
        );

        $matches = (new CompetitorStockMatchService())->findCompetitors($subject)->all();
        $this->assertNotEmpty($matches);
        $this->assertNull($matches[0]['thumbnail_abs_path'], 'missing file → placeholder, not broken image');
        $this->assertNull($matches[0]['thumbnail_url']);
    }
}
