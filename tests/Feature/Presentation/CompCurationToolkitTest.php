<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation;

use App\Models\AgentOverride;
use App\Models\Agency;
use App\Models\Presentation;
use App\Models\PresentationSoldComp;
use App\Models\PresentationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-22 / AT-21 — comparable-sales curation toolkit endpoints.
 *
 * Covers the batch include-set write (slider / sort / select-all / bulk) and
 * the browse-and-add-beyond-the-pool flow. Sets the agency context explicitly
 * so the suite is not subject to the BelongsToAgency auto-fill gap.
 */
final class CompCurationToolkitTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;
    private Presentation $presentation;
    private PresentationVersion $version;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $branch = \App\Models\Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'is_active' => true]);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $this->actingAs($this->user);
        $this->branchId = $branch->id;

        $this->presentation = Presentation::create([
            'agency_id'        => $this->agency->id,
            'branch_id'        => $branch->id,
            'created_by_user_id' => $this->user->id,
            'title'            => 'Curation Test',
            'property_address' => '36 Test Drive',
            'suburb'           => 'Uvongo',
            'property_type'    => 'house',
            'status'           => 'draft',
            'currency'         => 'ZAR',
        ]);
        $this->version = PresentationVersion::create([
            'agency_id'         => $this->agency->id,
            'presentation_id'   => $this->presentation->id,
            'compiled_by'       => $this->user->id,
            'data_snapshot_json' => '{}',
            'snapshot_payload'  => [],
        ]);
    }

    private function comp(int $price): PresentationSoldComp
    {
        return PresentationSoldComp::create([
            'agency_id'       => $this->agency->id,
            'presentation_id' => $this->presentation->id,
            'sold_date'       => now()->subMonths(2),
            'sold_price_inc'  => $price,
            'suburb'          => 'Uvongo',
            'property_type'   => 'House',
            'size_m2'         => 1200,
            'raw_row_json'    => json_encode(['sale_price' => $price]),
            'parser_version'  => 'mic_snapshot_v1',
        ]);
    }

    public function test_set_comps_writes_the_full_included_set_once(): void
    {
        $a = $this->comp(2_400_000);
        $b = $this->comp(2_600_000);
        $c = $this->comp(900_000);

        $this->postJson(route('presentations.review.set-comps', ['version' => $this->version->id]), [
            'included_ids' => [$a->id, $b->id],
        ])->assertOk()->assertJson(['ok' => true, 'included_count' => 2]);

        $this->assertEqualsCanonicalizing(
            [$a->id, $b->id],
            $this->version->fresh()->included_comp_ids_json,
            'Only the posted comps are included — the excluded one is dropped'
        );
        $this->assertDatabaseHas('agent_overrides', [
            'presentation_version_id' => $this->version->id,
            'override_type'           => AgentOverride::TYPE_COMP_BULK_SET,
        ]);
    }

    public function test_set_comps_ignores_ids_from_other_presentations(): void
    {
        $mine = $this->comp(2_400_000);
        // A comp on a different presentation must never enter this set.
        $otherPres = Presentation::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branchId, 'created_by_user_id' => $this->user->id,
            'title' => 'Other', 'status' => 'draft', 'currency' => 'ZAR',
        ]);
        $alien = PresentationSoldComp::create([
            'agency_id' => $this->agency->id, 'presentation_id' => $otherPres->id,
            'sold_date' => now(), 'sold_price_inc' => 2_000_000, 'suburb' => 'X',
            'property_type' => 'House', 'raw_row_json' => '{}', 'parser_version' => 'mic_snapshot_v1',
        ]);

        $this->postJson(route('presentations.review.set-comps', ['version' => $this->version->id]), [
            'included_ids' => [$mine->id, $alien->id],
        ])->assertOk();

        $this->assertSame([$mine->id], array_values($this->version->fresh()->included_comp_ids_json));
    }
}
