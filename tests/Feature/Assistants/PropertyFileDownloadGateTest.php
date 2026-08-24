<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Document;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-267 / POPIA — property Drive files now land on the private LOCAL disk and are served only via
 * the gated PropertyFileController::download route (never a direct /storage URL), which honours the
 * assistant download toggle.
 */
final class PropertyFileDownloadGateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $assistant;
    private AssistantAssignment $assignment;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agent     = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $this->assistant = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'assistant', 'is_active' => true, 'is_assistant' => true]);

        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->assistant->id, 'agent_user_id' => $this->agent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        $this->property = Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'agent_id' => $this->agent->id,
            'title' => 'Marine Drive', 'street_name' => 'Marine Drive', 'street_number' => '14',
            'suburb' => 'Margate', 'city' => 'Margate', 'status' => 'active',
        ]);

        foreach (['access_properties', 'properties.view'] as $k) {
            $this->grant($k, $k === 'properties.view' ? 'branch' : null);
        }
        PermissionService::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }

    public function test_uploaded_files_land_on_the_local_disk_and_download_through_the_gated_route(): void
    {
        $this->actingAs($this->agent)
            ->post(route('corex.properties.files.store', $this->property), [
                'file' => UploadedFile::fake()->create('mandate.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect();

        $doc = Document::first();
        $this->assertNotNull($doc);
        $this->assertSame('local', $doc->disk, 'New property files must be private (local disk).');
        Storage::disk('local')->assertExists($doc->storage_path);

        // Download streams for a permitted user.
        $this->actingAs($this->agent)
            ->get(route('corex.properties.files.download', [$this->property, $doc]))
            ->assertOk();
    }

    public function test_an_assistant_with_downloads_off_is_blocked_from_the_property_file(): void
    {
        $this->actingAs($this->agent)
            ->post(route('corex.properties.files.store', $this->property), [
                'file' => UploadedFile::fake()->create('fica.pdf', 20, 'application/pdf'),
            ]);
        $doc = Document::first();

        $this->assignment->forceFill(['can_download_documents' => false])->save();
        User::flushAssistantsEnabledCache();

        $this->actingAs(User::find($this->assistant->id))
            ->get(route('corex.properties.files.download', [$this->property, $doc]))
            ->assertForbidden();
    }

    private function grant(string $key, ?string $scope = null): void
    {
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id],
            ['scope' => $scope],
        );
        AssistantAssignmentPermission::updateOrCreate(
            ['assistant_assignment_id' => $this->assignment->id, 'permission_key' => $key],
            ['agency_id' => $this->agency->id, 'granted' => true, 'scope' => $scope],
        );
    }
}
