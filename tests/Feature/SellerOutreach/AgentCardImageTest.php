<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\User;
use App\Services\SellerOutreach\AgentCardImageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-83 — composite agent business-card image (WhatsApp link-preview og:image).
 *
 * Robustness matrix (BUILD_STANDARD §5): happy path (photo), the lazy/degraded
 * paths (no photo → initials, no FFC, no designation, very long name), and the
 * cache contract (reuse on no change, fresh file on change). Asserts real PNG
 * bytes at the documented 1200×630 — never a blank/broken image.
 */
final class AgentCardImageTest extends TestCase
{
    use RefreshDatabase;

    private function seedAgency(?string $logoPath = 'agencies/logo.png'): int
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'          => 'Home Finders Coastal',
            'slug'          => 'hfc-' . Str::random(8),
            'ffc_no'        => 'FFC40/43916/5',
            'logo_path'     => $logoPath,
            'default_color' => '#0b2a4a',
            'button_color'  => '#33c4e0',
            'created_at'    => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($logoPath) {
            Storage::disk('public')->put($logoPath, $this->samplePng(420, 160, 'HFC'));
        }

        return $agencyId;
    }

    private function makeAgent(int $agencyId, array $attrs = [], bool $withPhoto = true): User
    {
        $user = User::factory()->create(array_merge([
            'agency_id'   => $agencyId,
            'branch_id'   => $agencyId,
            'role'        => 'agent',
            'designation' => 'Property Practitioner',
            'ffc_number'  => 'FFC2024-9931',
        ], $attrs));

        if ($withPhoto) {
            $path = 'agents/' . $user->id . '/photo.jpg';
            Storage::disk('public')->put($path, $this->samplePhoto());
            $user->forceFill(['agent_photo_path' => $path])->save();
        }

        return $user->fresh();
    }

    /** A non-square portrait so the centre-crop-to-square path is exercised. */
    private function samplePhoto(): string
    {
        $img = imagecreatetruecolor(600, 800);
        for ($y = 0; $y < 800; $y++) {
            $c = imagecolorallocate($img, 40, 60 + (int) ($y / 8), 120);
            imageline($img, 0, $y, 600, $y, $c);
        }
        $skin = imagecolorallocate($img, 230, 200, 170);
        imagefilledellipse($img, 300, 360, 280, 320, $skin);
        ob_start();
        imagejpeg($img);
        $bin = (string) ob_get_clean();
        imagedestroy($img);
        return $bin;
    }

    private function samplePng(int $w, int $h, string $label): string
    {
        $img = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($img, 255, 255, 255);
        $navy = imagecolorallocate($img, 11, 42, 74);
        imagefilledrectangle($img, 0, 0, $w, $h, $white);
        imagettftext($img, 48, 0, 20, (int) ($h / 2) + 16, $navy, base_path('resources/fonts/DejaVuSans-Bold.ttf'), $label);
        ob_start();
        imagepng($img);
        $bin = (string) ob_get_clean();
        imagedestroy($img);
        return $bin;
    }

    /** @return array{0:int,1:int,2:string} width, height, mime */
    private function assertValidPng(string $fsPath): array
    {
        $this->assertFileExists($fsPath);
        $info = getimagesize($fsPath);
        $this->assertNotFalse($info, 'output is a parseable image');
        $this->assertSame('image/png', $info['mime']);
        return [$info[0], $info[1], $info['mime']];
    }

    public function test_card_with_photo_renders_1200x630_png(): void
    {
        Storage::fake('public');
        $svc = app(AgentCardImageService::class);
        $agent = $this->makeAgent($this->seedAgency());

        $path = $svc->resolve($agent);
        [$w, $h] = $this->assertValidPng($path);
        $this->assertSame(1200, $w);
        $this->assertSame(630, $h);

        @copy($path, '/tmp/agent-card-photo.png'); // for human eyeballing
    }

    public function test_card_without_photo_falls_back_to_initials_card(): void
    {
        Storage::fake('public');
        $svc = app(AgentCardImageService::class);
        $agent = $this->makeAgent($this->seedAgency(), ['name' => 'Retha Kelly'], withPhoto: false);

        $path = $svc->resolve($agent);
        [$w, $h] = $this->assertValidPng($path);
        $this->assertSame(1200, $w);
        $this->assertSame(630, $h);

        @copy($path, '/tmp/agent-card-initials.png');
    }

    public function test_degraded_inputs_still_render(): void
    {
        Storage::fake('public');
        $svc = app(AgentCardImageService::class);
        $agencyId = $this->seedAgency(logoPath: null); // no logo either

        // No FFC, no designation, very long name — must not crash or clip-garbage.
        $agent = $this->makeAgent($agencyId, [
            'name'        => 'Bartholomew Maximilian Featherstonehaugh-Cholmondeley',
            'designation' => null,
            'ffc_number'  => null,
        ], withPhoto: false);

        $path = $svc->resolve($agent);
        [$w, $h] = $this->assertValidPng($path);
        $this->assertSame(1200, $w);
        $this->assertSame(630, $h);

        @copy($path, '/tmp/agent-card-degraded.png');
    }

    public function test_cache_reuses_file_until_a_detail_changes(): void
    {
        Storage::fake('public');
        $svc = app(AgentCardImageService::class);
        $agent = $this->makeAgent($this->seedAgency());

        $rel1 = $svc->relativePath($agent);
        $svc->resolve($agent);
        $this->assertTrue(Storage::disk('public')->exists($rel1));

        // Same inputs → same cache key/path (no churn).
        $this->assertSame($rel1, $svc->relativePath($agent->fresh()));

        // Change a detail → new content hash → new path; old one pruned on regen.
        $agent->forceFill(['designation' => 'Principal Property Practitioner'])->save();
        $rel2 = $svc->relativePath($agent->fresh());
        $this->assertNotSame($rel1, $rel2, 'changing a detail produces a new cache key');

        $svc->resolve($agent->fresh());
        $this->assertTrue(Storage::disk('public')->exists($rel2));
        $this->assertFalse(Storage::disk('public')->exists($rel1), 'stale card pruned on regenerate');
    }
}
