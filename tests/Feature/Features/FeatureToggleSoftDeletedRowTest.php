<?php

declare(strict_types=1);

namespace Tests\Feature\Features;

use App\Models\Agency;
use App\Models\AgencyFeature;
use App\Models\Branch;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QA2 500: toggling Auctions on the Features page hit "Duplicate entry '1-auctions'"
 * because a soft-deleted agency_features row still holds the unique key and
 * FeatureSettingsController::update() didn't see it.
 */
final class FeatureToggleSoftDeletedRowTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggling_a_feature_whose_row_was_soft_deleted_revives_it_instead_of_500ing(): void
    {
        PermissionService::clearCache();
        $agency = Agency::create(['name' => 'Soft Del Agency', 'slug' => 'soft-del-'.uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);

        $row = AgencyFeature::create(['agency_id' => $agency->id, 'feature_key' => 'auctions', 'enabled' => true]);
        $row->delete();
        $this->assertSoftDeleted('agency_features', ['id' => $row->id]);

        $this->actingAs($admin)
            ->post(route('corex.settings.features.update'), ['auctions' => '1'])
            ->assertSessionHasNoErrors();

        $fresh = AgencyFeature::withTrashed()->where('agency_id', $agency->id)->where('feature_key', 'auctions')->get();
        $this->assertCount(1, $fresh, 'no duplicate row');
        $this->assertNull($fresh->first()->deleted_at);
        $this->assertTrue((bool) $fresh->first()->enabled);
    }
}
