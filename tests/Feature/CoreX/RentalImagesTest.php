<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Rental inspection galleries — the "Rental Images" tab on a rental property.
 * Spec: .ai/specs/rental-images.md
 *
 * Covers: tab visibility (rental only), uploads into fixed + custom sections,
 * date/section metadata saves, single-image deletes, and cross-agency safety.
 */
final class RentalImagesTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        PermissionService::clearCache();

        $this->agency = Agency::create([
            'name' => 'Rental Test Agency',
            'slug' => 'rental-test-' . uniqid(),
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    private function makeProperty(array $attrs = []): Property
    {
        return Property::create(array_merge([
            'title'     => 'Rental Test Property',
            'agency_id' => $this->agency->id,
            'agent_id'  => $this->user->id,
            'branch_id' => $this->branch->id,
            'listing_type' => 'rental',
        ], $attrs));
    }

    public function test_tab_hidden_for_sale_and_shown_for_rental(): void
    {
        $rental = $this->makeProperty(['listing_type' => 'rental']);
        $this->actingAs($this->user)
            ->get(route('corex.properties.show', $rental))
            ->assertOk()
            ->assertSee('Rental Images');

        $sale = $this->makeProperty(['listing_type' => 'sale', 'title' => 'Sale One']);
        $this->actingAs($this->user)
            ->get(route('corex.properties.show', $sale))
            ->assertOk()
            ->assertDontSee('Rental Images');
    }

    public function test_upload_appends_images_to_in_inspection_and_custom_section(): void
    {
        $p = $this->makeProperty();

        // Add a custom section first so we have a target id.
        $sectionId = $this->actingAs($this->user)
            ->postJson(route('corex.properties.rental-images.save', $p), [
                'action' => 'add_section', 'name' => 'Garden handover',
            ])
            ->assertOk()
            ->json('rental_images.custom.0.id');

        $this->assertNotEmpty($sectionId);

        // Upload to the fixed In Inspection section.
        $this->actingAs($this->user)
            ->postJson(route('corex.properties.rental-images.upload', $p), [
                'section' => 'in_inspection',
                'images'  => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        // Upload to the custom section.
        $this->actingAs($this->user)
            ->postJson(route('corex.properties.rental-images.upload', $p), [
                'section'   => 'custom',
                'custom_id' => $sectionId,
                'images'    => [UploadedFile::fake()->image('c.jpg')],
            ])
            ->assertOk();

        $structure = $p->fresh()->rentalImagesStructure();
        $this->assertCount(2, $structure['in_inspection']['images']);
        $this->assertCount(0, $structure['out_inspection']['images']);
        $this->assertSame($sectionId, $structure['custom'][0]['id']);
        $this->assertCount(1, $structure['custom'][0]['images']);

        // Files actually landed on disk under the property's directory.
        foreach ($structure['in_inspection']['images'] as $url) {
            $rel = ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            $rel = str_starts_with($rel, 'storage/') ? substr($rel, strlen('storage/')) : $rel;
            $this->assertTrue(Storage::disk('public')->exists($rel));
        }
    }

    public function test_save_sets_section_date_and_mints_custom_section_id(): void
    {
        $p = $this->makeProperty();

        // custom_id: '' mirrors the live request — the ConvertEmptyStringsToNull
        // middleware turns it to null, which must NOT trip the validation.
        $this->actingAs($this->user)
            ->postJson(route('corex.properties.rental-images.save', $p), [
                'action' => 'set_date', 'section' => 'in_inspection', 'custom_id' => '', 'date' => '2026-06-24',
            ])
            ->assertOk();

        $add = $this->actingAs($this->user)
            ->postJson(route('corex.properties.rental-images.save', $p), [
                'action' => 'add_section', 'name' => 'Damage — kitchen',
            ])
            ->assertOk()->json('rental_images.custom.0');

        $structure = $p->fresh()->rentalImagesStructure();
        $this->assertSame('2026-06-24', $structure['in_inspection']['date']);
        $this->assertSame('Damage — kitchen', $structure['custom'][0]['name']);
        $this->assertNotEmpty($add['id']);
        $this->assertNull($structure['custom'][0]['date']);
    }

    public function test_delete_removes_the_right_image_and_leaves_others(): void
    {
        $p = $this->makeProperty();

        $this->actingAs($this->user)
            ->postJson(route('corex.properties.rental-images.upload', $p), [
                'section' => 'out_inspection',
                'images'  => [UploadedFile::fake()->image('x.jpg'), UploadedFile::fake()->image('y.jpg')],
            ])->assertOk();

        $before = $p->fresh()->rentalImagesStructure()['out_inspection']['images'];
        $this->assertCount(2, $before);
        $kept = $before[1];

        $this->actingAs($this->user)
            ->postJson(route('corex.properties.rental-images.delete', $p), [
                'section' => 'out_inspection', 'custom_id' => '', 'index' => 0,
            ])->assertOk();

        $after = $p->fresh()->rentalImagesStructure()['out_inspection']['images'];
        $this->assertCount(1, $after);
        $this->assertSame($kept, $after[0]);

        // Deleted file is gone from disk.
        $deletedRel = ltrim((string) parse_url($before[0], PHP_URL_PATH), '/');
        $deletedRel = str_starts_with($deletedRel, 'storage/') ? substr($deletedRel, strlen('storage/')) : $deletedRel;
        $this->assertFalse(Storage::disk('public')->exists($deletedRel));
    }

    public function test_cannot_touch_another_agencys_property(): void
    {
        $otherAgency = Agency::create(['name' => 'Other', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Main']);
        $otherAgent = User::factory()->create([
            'agency_id' => $otherAgency->id,
            'branch_id' => $otherBranch->id,
        ]);
        $foreign = Property::create([
            'title'     => 'Foreign',
            'agency_id' => $otherAgency->id,
            'agent_id'  => $otherAgent->id,
            'branch_id' => $otherBranch->id,
            'listing_type' => 'rental',
        ]);

        // AgencyScope blocks route-model binding across agencies → 404.
        $this->actingAs($this->user)
            ->postJson(route('corex.properties.rental-images.save', $foreign), [
                'action' => 'add_section', 'name' => 'Sneaky',
            ])
            ->assertNotFound();
    }
}
