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
 * The public onboarding portal review page must let the reviewer choose how many
 * listings show per page (default 30) instead of being locked to 30.
 */
class OnboardingPortalPerPageTest extends TestCase
{
    use RefreshDatabase;

    private P24OnboardingPortal $portal;

    protected function setUp(): void
    {
        parent::setUp();

        $agency = Agency::create(['name' => 'Portal Agency', 'slug' => 'portal-agency']);
        Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        $run = P24ImportRun::create([
            'agency_id' => $agency->id,
            'kind'      => 'listings_images',
            'status'    => 'completed',
        ]);

        // 45 pending listing rows — enough to span several page sizes.
        for ($i = 1; $i <= 45; $i++) {
            P24ImportRow::create([
                'run_id'      => $run->id,
                'row_type'    => 'listing',
                'external_id' => (string) (100000 + $i),
                'mapped_json' => ['address' => "No {$i} Test Rd", 'listing_type' => 'Sale'],
                'status'      => 'pending',
            ]);
        }

        $this->portal = P24OnboardingPortal::create([
            'agency_id'  => $agency->id,
            'token'      => P24OnboardingPortal::generateToken(),
            'slug'       => 'portal-agency-review',
            'label'      => 'Portal Agency',
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function test_defaults_to_thirty_per_page(): void
    {
        $resp = $this->get(route('onboarding.portal.review', $this->portal->urlKey()));

        $resp->assertOk();
        $rows = $resp->viewData('rows');
        $this->assertSame(30, $rows->perPage());
        $this->assertCount(30, $rows);
        $this->assertSame(45, $rows->total());
    }

    public function test_honours_a_valid_per_page_choice(): void
    {
        $resp = $this->get(route('onboarding.portal.review', $this->portal->urlKey()) . '?per_page=100');

        $resp->assertOk();
        $rows = $resp->viewData('rows');
        $this->assertSame(100, $rows->perPage());
        $this->assertCount(45, $rows); // all of them fit on one page
    }

    public function test_a_smaller_page_size_is_allowed(): void
    {
        $resp = $this->get(route('onboarding.portal.review', $this->portal->urlKey()) . '?per_page=15');

        $resp->assertOk();
        $this->assertSame(15, $resp->viewData('rows')->perPage());
    }

    public function test_invalid_per_page_falls_back_to_thirty(): void
    {
        $resp = $this->get(route('onboarding.portal.review', $this->portal->urlKey()) . '?per_page=999');

        $resp->assertOk();
        $this->assertSame(30, $resp->viewData('rows')->perPage());
    }
}
