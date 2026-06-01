<?php

namespace Tests\Feature\Importer;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\P24ImportRow;
use App\Models\P24ImportRun;
use App\Models\P24OnboardingPortal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A portal that is no longer open for editing (completed / expired / revoked)
 * must show a friendly screen — never the raw 410 exception page.
 */
class OnboardingPortalStateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private P24ImportRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'State Agency', 'slug' => 'state-agency']);
        Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);

        $this->run = P24ImportRun::create([
            'agency_id' => $this->agency->id,
            'kind'      => 'listings_images',
            'status'    => 'completed',
        ]);

        foreach (['confirmed', 'confirmed', 'excluded', 'pending'] as $i => $status) {
            P24ImportRow::create([
                'run_id'      => $this->run->id,
                'row_type'    => 'listing',
                'external_id' => (string) (200000 + $i),
                'mapped_json' => ['address' => "Row {$i}"],
                'status'      => $status,
            ]);
        }
    }

    private function makePortal(array $attrs = []): P24OnboardingPortal
    {
        return P24OnboardingPortal::create(array_merge([
            'agency_id'  => $this->agency->id,
            'token'      => P24OnboardingPortal::generateToken(),
            'slug'       => 'state-portal-' . P24OnboardingPortal::generateToken(),
            'label'      => 'State Agency',
            'expires_at' => now()->addDays(30),
        ], $attrs));
    }

    public function test_completed_portal_shows_friendly_completion_screen(): void
    {
        $portal = $this->makePortal(['completed_at' => now()]);

        $resp = $this->get(route('onboarding.portal.review', $portal->urlKey()));

        $resp->assertOk();
        $resp->assertSee('Review already submitted');
        $resp->assertSee('CoreX has been notified', false);
        $resp->assertSee('2'); // 2 confirmed
        $resp->assertDontSee('This onboarding link is no longer active.');
    }

    public function test_completed_portal_returns_410_json_for_ajax(): void
    {
        $portal = $this->makePortal(['completed_at' => now()]);

        $resp = $this->getJson(route('onboarding.portal.review', $portal->urlKey()));

        $resp->assertStatus(410);
        $resp->assertJsonPath('message', 'This onboarding review has already been submitted.');
    }

    public function test_expired_portal_shows_friendly_expired_screen(): void
    {
        $portal = $this->makePortal(['expires_at' => now()->subDay()]);

        $resp = $this->get(route('onboarding.portal.review', $portal->urlKey()));

        $resp->assertStatus(410);
        $resp->assertSee('no longer active');
    }

    public function test_revoked_portal_shows_friendly_expired_screen(): void
    {
        $portal = $this->makePortal(['revoked_at' => now()]);

        $resp = $this->get(route('onboarding.portal.review', $portal->urlKey()));

        $resp->assertStatus(410);
        $resp->assertSee('no longer active');
    }
}
