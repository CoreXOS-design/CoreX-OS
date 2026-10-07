<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Models\SuggestedActionThresholds;
use App\Models\User;
use App\Services\Presentations\PresentationPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Johan 2026-10-07 — the Active Competition section must never vanish silently on the agent's
 * screens: with no active competitors it says "No active competition found for this area" and
 * recommends refreshing the suburb's portal stock with the Chrome extension. The seller PDF
 * prints NO empty section.
 */
final class ActiveCompetitionEmptyStateTest extends TestCase
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

    public function test_review_screen_with_no_competitors_shows_the_card_with_the_suburb_and_the_fix(): void
    {
        [$agencyId, $user, $version] = $this->seed1();

        $html = $this->actingAs($user)->get(route('presentations.review.show', $version->id))->assertOk()->getContent();

        $this->assertStringContainsString('2b · Active Competition', $html);
        $this->assertStringContainsString('No active competition found for this area', $html);
        $this->assertStringContainsString('Uvongo', $html);                       // names the suburb
        $this->assertStringContainsString('Chrome extension', $html);
        $this->assertStringContainsString('after 90 days without being seen again', $html);
        $this->assertStringContainsString('data-chrome-extension-link', $html);   // links to where the capture starts
        $this->assertStringNotContainsString('id="competitor-stock-list"', $html); // the card grid is not drawn empty
    }

    public function test_card_quotes_the_agencys_own_window(): void
    {
        [$agencyId, $user, $version] = $this->seed1();
        SuggestedActionThresholds::getOrCreateForAgency($agencyId)->update(['listing_off_market_days' => 45]);

        $html = $this->actingAs($user)->get(route('presentations.review.show', $version->id))->assertOk()->getContent();

        $this->assertStringContainsString('after 45 days without being seen again', $html);
    }

    public function test_analysis_step_section_uses_the_same_notice_partial_when_empty(): void
    {
        $src = file_get_contents(resource_path('views/presentations/partials/analysis-data-review.blade.php'));

        $this->assertStringContainsString("@if(empty(\$compVisible5))", $src);
        $this->assertStringContainsString("@include('presentations.partials._no-active-competition')", $src);
    }

    public function test_seller_pdf_prints_no_active_competition_section_when_there_are_no_competitors(): void
    {
        [, , $version] = $this->seed1();

        $html = (new PresentationPdfService())->buildHtml($version);

        $this->assertStringNotContainsString("Beat 3 — What's On The Market Now", $html);
        $this->assertStringNotContainsString('competes against', $html);
        $this->assertStringNotContainsString('No active competition found', $html, 'agent-screen notice must not leak onto the seller PDF');
    }

    /** @return array{0:int,1:User,2:PresentationVersion} */
    private function seed1(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert(['id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin']);
        $presentation = Presentation::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'created_by_user_id' => $user->id,
            'title' => 'Empty-competition Test', 'property_address' => '1 Test Street', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'draft', 'currency' => 'ZAR',
        ]);
        $version = PresentationVersion::create([
            'agency_id' => $agencyId, 'presentation_id' => $presentation->id, 'compiled_by' => $user->id,
            'blueprint_version' => 'v1', 'data_snapshot_json' => json_encode(['sections' => []]), 'compiled_at' => now(),
            'review_status' => PresentationVersion::REVIEW_AWAITING, 'awaiting_review_at' => now(),
        ]);

        return [$agencyId, $user, $version];
    }
}
