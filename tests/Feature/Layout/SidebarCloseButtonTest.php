<?php

declare(strict_types=1);

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-422 — the sidebar's "Close menu" X is for the mobile drawer only. It carried `lg:hidden` but ALSO an
 * inline `display:flex`, and an inline style beats a class, so the X showed on desktop where the sidebar
 * never closes. The display must come from a CLASS so `lg:hidden` can win at desktop widths.
 * (Behaviour proven in Chromium: hidden at >=1024px, shown below.)
 */
final class SidebarCloseButtonTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_close_button_is_mobile_only_and_no_inline_style_can_override_that(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('admin.users'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<button[^>]*aria-label="Close menu"[^>]*>/s', $html, $m), 'the close button is on the page');
        $tag = $m[0];
        $this->assertStringContainsString('lg:hidden', $tag, 'hidden from the desktop breakpoint up');
        $this->assertMatchesRegularExpression('/class="[^"]*\bflex\b[^"]*"/', $tag, 'shown as a flex box via a class (so lg:hidden can override it)');
        $this->assertDoesNotMatchRegularExpression('/style="[^"]*display\s*:/', $tag, 'no inline display, which would beat lg:hidden');
    }
}
