<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression — editing a user through the admin user form (e.g. swapping their
 * profile photo) must NOT reset the per-agent "Show on website" toggle
 * (show_on_website) or the Property24 opt-out (exclude_from_p24).
 *
 * Both flags are driven in edit mode by their own instant AJAX switches, so the
 * main form submits NO field for them. UserManagementController::update() must
 * therefore leave them untouched when the request carries no field — otherwise a
 * plain Save silently flips a visible agent off the agency website.
 *
 * See UserManagementController::update() guard + commit e699b6e4 (2026-06-25).
 */
class UserEditPreservesWebsiteToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_the_photo_does_not_reset_show_on_website(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('', 200)]);
        Queue::fake(); // don't run the P24 sync job

        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal', 'website_enabled' => true]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        $admin = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin',
        ]);

        // A visible agent who is also live on P24.
        $agent = User::factory()->create([
            'agency_id'        => $agency->id,
            'branch_id'        => $branch->id,
            'role'             => 'agent',
            'name'             => 'Thandi Mbeki',
            'cell'             => '0825550100',
            'show_on_website'  => true,
            'exclude_from_p24' => false,
        ]);

        // Edit the agent and change ONLY the photo — exactly the form the admin
        // submits from the edit page (no show_on_website / exclude_from_p24 field).
        $response = $this->actingAs($admin)->put(route('admin.users.update', $agent), [
            'name'        => 'Thandi',
            'surname'     => 'Mbeki',
            'email'       => $agent->email,
            'cell'        => '0825550100',
            'role'        => 'agent',
            'branch_id'   => $branch->id,
            'agent_photo' => UploadedFile::fake()->image('photo.jpg', 1000, 1000),
        ]);

        $response->assertRedirect();

        $agent->refresh();
        $this->assertTrue((bool) $agent->show_on_website, 'Editing the photo must not hide the agent from the website.');
        $this->assertFalse((bool) $agent->exclude_from_p24, 'Editing the photo must not change the P24 opt-out.');
        $this->assertNotNull($agent->agent_photo_path, 'The new photo should have been stored.');
    }
}
