<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Illuminate\Support\Facades\DB;

/**
 * Webinar-eve config sweep (2026-09-02) — agencies.logo_path was NULL for
 * the demo agency, so brochures/watermarks/the buyer-facing viewing-pack PDF
 * and every branded screen showed no logo at all. Generates a simple
 * fictional "CoreX Demo Realty" wordmark via GD (no external asset, no
 * network call) and stores it exactly where a real upload would land
 * (storage/app/public/agencies/{id}/logo.png, matching
 * SettingsController::updateCompany()'s own storeAs() convention).
 *
 * Idempotent: only generates + sets logo_path when it is currently unset —
 * never overwrites a logo a real agent/demo-presenter has since uploaded.
 */
final class DemoAgencyLogoSeeder
{
    public function run(int $agencyId): array
    {
        $agency = DB::table('agencies')->where('id', $agencyId)->first(['id', 'name', 'logo_path']);
        if (! $agency) {
            return ['created' => false, 'note' => "Skipped — agency {$agencyId} not found."];
        }
        if (! empty($agency->logo_path)) {
            return ['created' => false, 'note' => 'Skipped — logo already set, not overwriting.'];
        }

        $dir = storage_path("app/public/agencies/{$agencyId}");
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir . '/logo.png';

        $w = 512;
        $h = 512;
        $img = imagecreatetruecolor($w, $h);
        $navy = imagecolorallocate($img, 15, 42, 74);
        $gold = imagecolorallocate($img, 201, 162, 79);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledrectangle($img, 0, 0, $w, $h, $navy);
        imagefilledpolygon($img, [256, 90, 140, 210, 372, 210], $gold);
        imagefilledrectangle($img, 170, 210, 342, 320, $gold);
        imagefilledrectangle($img, 190, 230, 322, 320, $navy);

        $font = 5;
        $agencyName = strtoupper((string) $agency->name) ?: 'DEMO REALTY';
        $lineWidth = imagefontwidth($font) * strlen($agencyName);
        imagestring($img, $font, (int) (($w - $lineWidth) / 2), 360, $agencyName, $white);

        imagepng($img, $path);
        imagedestroy($img);

        DB::table('agencies')->where('id', $agencyId)->update([
            'logo_path' => "agencies/{$agencyId}/logo.png",
            'updated_at' => now(),
        ]);

        return ['created' => true];
    }
}
