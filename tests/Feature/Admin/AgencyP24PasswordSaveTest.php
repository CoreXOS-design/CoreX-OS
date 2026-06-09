<?php

namespace Tests\Feature\Admin;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyP24PasswordSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_p24_password_persists_via_update(): void
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role' => 'super_admin',
        ]);

        $resp = $this->actingAs($user)->put(route('agencies.update', $agency), [
            'name' => 'Coastal',
            'active_tab' => 'syndication',
            'p24_username' => '31357@hfcoastal.co.za',
            'p24_password' => 'SuperSecret123',
        ]);

        $resp->assertSessionHasNoErrors();
        // Stays on the edit page, on the tab the user was on — not bounced to
        // the agency list — with a success flash for in-context confirmation.
        $resp->assertRedirect(route('agencies.edit', $agency) . '#syndication');
        $resp->assertSessionHas('success');

        $agency->refresh();
        $this->assertSame('31357@hfcoastal.co.za', $agency->p24_username);
        $this->assertSame('SuperSecret123', $agency->p24_password, 'P24 password should persist');
        $this->assertTrue((bool) $agency->p24_enabled, 'P24 should auto-enable');

        // Reopening the edit page shows the masked "leave blank to keep"
        // placeholder, confirming a password is stored (the field itself never
        // re-displays the value).
        $this->actingAs($user)->get(route('agencies.edit', $agency))
            ->assertOk()
            ->assertSee('leave blank to keep')
            ->assertSee('name="active_tab"', false);
    }
}
