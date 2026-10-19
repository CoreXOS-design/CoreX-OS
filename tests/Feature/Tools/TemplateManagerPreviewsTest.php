<?php

declare(strict_types=1);

namespace Tests\Feature\Tools;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Template Manager previews (Johan 2026-10-18: "it should show the actual template like a preview and not just the name").
 * Both tabs draw every row's ad with the render kernel, from the page's own rows only, with the user's own agency as
 * the sample — and the kernel is loaded once per page.
 */
final class TemplateManagerPreviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_property_templates_show_a_drawn_preview_per_row_in_the_users_own_brand(): void
    {
        [$a, $user] = $this->agencyWithUser('Alpha Homes');
        [$b, $other] = $this->agencyWithUser('Bravo Rentals');
        $mine = $this->template($a, $user, 'MY-TEMPLATE', ['variants' => ['Vacant Land' => ['canvasW' => 1080, 'canvasH' => 1080, 'elements' => []]]]);
        $this->template($b, $other, 'THEIR-TEMPLATE');

        $html = $this->actingAs($user)->get(route('tools.ad-manager.templates'))->assertOk()->getContent();

        $this->assertStringContainsString('>Preview</th>', $html);
        $this->assertStringContainsString('class="adt-thumb" data-ad-thumb="' . $mine . '"', $html);
        $this->assertSame(1, substr_count($html, 'data-ad-thumb="'), 'only this agency\'s rows get a preview');
        $this->assertStringContainsString('window.corexDrawAdThumbs(', $html);
        $this->assertSame(1, substr_count($html, 'js/corex-ad-render.js'), 'the kernel is loaded once (the AI pop-up shares it)');
        $this->assertStringContainsString('Alpha Homes', $html);
        $this->assertStringNotContainsString('Bravo Rentals', $html);
        $this->assertStringNotContainsString('THEIR-TEMPLATE', $html);
        // The preview shows the Default design; property-type versions are not sent to the page for it.
        $this->assertStringNotContainsString('Vacant Land', $html);
    }

    public function test_company_ads_use_the_same_preview(): void
    {
        [$a, $user] = $this->agencyWithUser('Alpha Homes');
        $id = (int) DB::table('company_ads')->insertGetId([
            'agency_id' => $a, 'user_id' => $user->id, 'name' => 'Xmas', 'canvas' => 'facebook',
            'layout_json' => json_encode(['canvasW' => 1200, 'canvasH' => 628, 'elements' => []]), 'ai_generated' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $html = $this->actingAs($user)->get(route('tools.ad-manager.templates.company'))->assertOk()->getContent();

        $this->assertStringContainsString('class="adt-thumb" data-ad-thumb="' . $id . '"', $html);
        $this->assertStringContainsString('window.corexDrawAdThumbs(this.layouts, this.brand', $html);
        $this->assertSame(1, substr_count($html, 'js/corex-ad-render.js'));
        $this->assertSame(1, substr_count($html, 'window.corexDrawAdThumbs = function'));
    }

    /** @return array{0:int,1:User} */
    private function agencyWithUser(string $name): array
    {
        $id = (int) DB::table('agencies')->insertGetId(['name' => $name, 'slug' => 'ag-' . Str::random(8), 'created_at' => now(), 'updated_at' => now()]);
        $branch = (int) DB::table('branches')->insertGetId(['agency_id' => $id, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);

        return [$id, User::factory()->create(['agency_id' => $id, 'branch_id' => $branch, 'role' => 'branch_manager'])];
    }

    private function template(int $agency, User $user, string $name, array $extra = []): int
    {
        return (int) DB::table('property_ad_templates')->insertGetId([
            'agency_id' => $agency, 'user_id' => $user->id, 'name' => $name,
            'layout_json' => json_encode(['canvasW' => 1200, 'canvasH' => 628, 'elements' => []] + $extra),
            'is_global' => 0, 'ai_generated' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
