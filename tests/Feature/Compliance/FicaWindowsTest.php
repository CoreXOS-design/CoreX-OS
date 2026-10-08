<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\User;
use App\Services\Compliance\FicaWindows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Rentals cross-cut (8 Oct 2026) — the FICA time windows that were literals in code (a 14-day link, an 11-month "still current", a
 * 24-month validity stamp, a 60-day "expiring soon") are per-agency settings whose DEFAULT is the old literal:
 * nothing changes for an agency that never opens them; the setting is bounded, saved from Settings and the Setup Wizard,
 * and found by Settings search.
 */
final class FicaWindowsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $co;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00:00'));
        $this->agency = Agency::create(['name' => 'Fica Windows ' . uniqid(), 'slug' => 'fw-' . uniqid()]);
        DB::table('branches')->insert(['id' => $this->agency->id, 'agency_id' => $this->agency->id, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now()]);
        $this->co = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->agency->id, 'role' => 'super_admin']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setWindow(string $key, ?int $value, ?Agency $agency = null): void
    {
        Agency::withoutGlobalScopes()->whereKey(($agency ?? $this->agency)->id)->update([$key => $value]);
    }

    private function contact(): Contact
    {
        return Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->agency->id, 'first_name' => 'Thandi', 'last_name' => 'Mkhize',
            'email' => 't' . uniqid() . '@example.test', 'created_by_user_id' => $this->co->id,
        ]));
    }

    private function submission(Contact $contact, array $over = []): FicaSubmission
    {
        return FicaSubmission::withoutEvents(fn () => FicaSubmission::create($over + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->agency->id, 'contact_id' => $contact->id,
            'requested_by' => $this->co->id, 'status' => 'draft', 'token' => 'tok-' . uniqid(),
            'token_expires_at' => now()->addDay(),
        ]));
    }

    // ── defaults: exactly the old literals ─────────────────────────────────

    public function test_with_nothing_saved_every_window_is_exactly_the_old_literal(): void
    {
        $id = $this->agency->id;
        $this->assertSame(14, FicaWindows::linkExpiryDays($id));
        $this->assertSame(11, FicaWindows::currentMonths($id));
        $this->assertSame(24, FicaWindows::validityMonths($id));
        $this->assertSame(60, FicaWindows::expiringSoonDays($id));
        $this->assertSame(14, FicaWindows::linkExpiryDays(null), 'no agency in scope = the default, never an error');
    }

    public function test_a_saved_value_is_used_for_that_agency_only_and_an_out_of_range_one_falls_back(): void
    {
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        $this->setWindow('fica_link_expiry_days', 30);
        $this->assertSame(30, FicaWindows::linkExpiryDays($this->agency->id));
        $this->assertSame(14, FicaWindows::linkExpiryDays($other->id));

        $this->setWindow('fica_link_expiry_days', 0);
        $this->assertSame(14, FicaWindows::linkExpiryDays($this->agency->id));
        $this->setWindow('fica_link_expiry_days', 9999);
        $this->assertSame(14, FicaWindows::linkExpiryDays($this->agency->id));
    }

    // ── the places that used the literals ─────────────────────────────────

    public function test_the_emailed_link_lasts_the_agencys_days_14_by_default(): void
    {
        Mail::fake();
        $sub = $this->submission($this->contact());

        $this->actingAs($this->co)->post(route('compliance.fica.resend', $sub))->assertRedirect();
        $this->assertSame('2026-10-22', $sub->fresh()->token_expires_at->toDateString(), '14 days by default');

        $this->setWindow('fica_link_expiry_days', 30);
        $this->actingAs($this->co)->post(route('compliance.fica.resend', $sub))->assertRedirect();
        $this->assertSame('2026-11-07', $sub->fresh()->token_expires_at->toDateString(), 'the agency chose 30');
    }

    public function test_corrections_requested_gives_the_client_a_link_for_the_agencys_days(): void
    {
        Mail::fake();
        $this->setWindow('fica_link_expiry_days', 21);
        $sub = $this->submission($this->contact(), ['status' => 'submitted']);

        $this->actingAs($this->co)->post(route('compliance.fica.request-corrections', $sub), ['reviewer_notes' => 'ID copy is blurred'])->assertRedirect();
        $this->assertSame('2026-10-29', $sub->fresh()->token_expires_at->toDateString());
    }

    public function test_a_contacts_fica_turns_expiring_after_the_agencys_months_11_by_default(): void
    {
        $recent = $this->contact();
        $this->submission($recent, ['status' => 'approved', 'verified_at' => now()->subMonths(10)]);
        $this->assertSame('complete', $recent->fresh()->ficaStatus(), '10 months is still current');

        $old = $this->contact();
        $this->submission($old, ['status' => 'approved', 'verified_at' => now()->subMonths(11)->subDay()]);
        $this->assertSame('expiring', $old->fresh()->ficaStatus(), '11 months and a day is expiring (the old 11)');

        // the agency decides an approval is current for 6 months
        $fresh = $this->contact();
        $this->submission($fresh, ['status' => 'approved', 'verified_at' => now()->subMonths(7)]);
        $this->assertSame('complete', $fresh->fresh()->ficaStatus());
        $this->setWindow('fica_current_months', 6);
        $this->assertSame('expiring', $fresh->fresh()->ficaStatus());
        $this->setWindow('fica_current_months', 24);
        $this->assertSame('complete', $old->fresh()->ficaStatus());
    }

    public function test_the_expiring_soon_list_looks_ahead_by_the_agencys_days_60_by_default(): void
    {
        $c = $this->contact();
        $this->submission($c, ['status' => 'approved', 'fica_expires_at' => now()->addDays(45)->toDateString()]);
        $this->submission($c, ['status' => 'approved', 'fica_expires_at' => now()->addDays(100)->toDateString()]);
        $this->actingAs($this->co);

        $this->assertSame(1, FicaSubmission::expiringSoon()->count(), '60 days by default');
        $this->setWindow('fica_expiring_soon_days', 120);
        $this->assertSame(2, FicaSubmission::expiringSoon()->count());
        $this->setWindow('fica_expiring_soon_days', 30);
        $this->assertSame(0, FicaSubmission::expiringSoon()->count());
        $this->assertSame(2, FicaSubmission::expiringSoon(120)->count(), 'an explicit window still wins');
    }

    public function test_no_fica_code_path_still_carries_the_old_literals(): void
    {
        $base = dirname(__DIR__, 3) . '/app/';
        $fica = file_get_contents($base . 'Http/Controllers/Compliance/FicaController.php');
        $this->assertStringNotContainsString('addDays(14)', $fica);
        $this->assertStringNotContainsString('addMonths(24)', $fica);
        $this->assertStringContainsString('FicaWindows::validityMonths', $fica);
        $this->assertStringNotContainsString('addDays(14)', file_get_contents($base . 'Http/Controllers/Docuperfect/SigningController.php'));
        $this->assertStringNotContainsString('subMonths(11)', file_get_contents($base . 'Http/Controllers/CoreX/RentalApplicationController.php'));
        $this->assertStringNotContainsString('>= 11', file_get_contents($base . 'Models/Contact.php'));
    }

    // ── the settings ─────────────────────────────────────────────────────

    public function test_the_saver_writes_only_the_fields_posted_clears_blanks_and_is_bounded(): void
    {
        $url = route('corex.settings.fica-windows.save');

        $this->actingAs($this->co)->post($url, ['fica_link_expiry_days' => '30', 'fica_validity_months' => '12'])->assertSessionHasNoErrors();
        $this->assertSame(30, FicaWindows::linkExpiryDays($this->agency->id));
        $this->assertSame(12, FicaWindows::validityMonths($this->agency->id));
        $this->assertSame(11, FicaWindows::currentMonths($this->agency->id), 'a field not posted is left alone');

        // a post that carries none of them (an older wizard render) changes nothing
        $this->actingAs($this->co)->post($url, [])->assertSessionHasNoErrors();
        $this->assertSame(30, FicaWindows::linkExpiryDays($this->agency->id));

        // blank = back to the platform default
        $this->actingAs($this->co)->post($url, ['fica_link_expiry_days' => ''])->assertSessionHasNoErrors();
        $this->assertSame(14, FicaWindows::linkExpiryDays($this->agency->id));
        $this->assertNull(Agency::withoutGlobalScopes()->find($this->agency->id)->fica_link_expiry_days);

        // bounded
        $this->actingAs($this->co)->post($url, ['fica_link_expiry_days' => '0'])->assertSessionHasErrors('fica_link_expiry_days');
        $this->actingAs($this->co)->post($url, ['fica_expiring_soon_days' => '400'])->assertSessionHasErrors('fica_expiring_soon_days');
        $this->actingAs($this->co)->post($url, ['fica_current_months' => 'abc'])->assertSessionHasErrors('fica_current_months');
    }

    public function test_only_a_compliance_officer_role_may_save_and_another_agency_is_untouched(): void
    {
        $other = Agency::create(['name' => 'Other ' . uniqid(), 'slug' => 'o-' . uniqid()]);
        \App\Models\Role::create(['name' => 'viewer_only', 'label' => 'Viewer only', 'agency_id' => $this->agency->id]);
        \App\Models\RolePermission::create(['role' => 'viewer_only', 'permission_key' => 'leases.view', 'scope' => 'all', 'agency_id' => $this->agency->id]);
        \App\Models\Role::create(['name' => 'super_admin', 'label' => 'Super admin', 'agency_id' => $this->agency->id]);
        \App\Models\RolePermission::create(['role' => 'super_admin', 'permission_key' => 'manage_compliance_officer', 'scope' => 'all', 'agency_id' => $this->agency->id]);
        \App\Services\PermissionService::clearCache();
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->agency->id, 'role' => 'viewer_only']);

        $this->actingAs($agent)->post(route('corex.settings.fica-windows.save'), ['fica_link_expiry_days' => '60'])->assertForbidden();
        $this->assertSame(14, FicaWindows::linkExpiryDays($this->agency->id));

        $this->actingAs($this->co)->post(route('corex.settings.fica-windows.save'), ['fica_link_expiry_days' => '60']);
        $this->assertSame(60, FicaWindows::linkExpiryDays($this->agency->id));
        $this->assertSame(14, FicaWindows::linkExpiryDays($other->id));
    }

    public function test_the_settings_page_carries_the_windows_with_the_standard_values_and_settings_search_finds_them(): void
    {
        // (the whole Settings page is not rendered here: it is heavy and unrelated — the block and the search keywords are checked at source)
        $blade = file_get_contents(dirname(__DIR__, 3) . '/resources/views/corex/settings.blade.php');

        $this->assertStringContainsString('data-qa="fica-windows-settings"', $blade);
        $this->assertStringContainsString("route('corex.settings.fica-windows.save')", $blade);
        $this->assertStringContainsString('$agency->{$fkey} ?? $fdefault', $blade, 'shows what the agency saved, else the standard');
        $this->assertStringContainsString('FicaWindows::WINDOWS[$fkey]', $blade);
        $this->assertStringContainsString("fica time windows link expiry expires valid validity months expiring soon current", $blade, 'settings search keywords');
        foreach (['fica_link_expiry_days', 'fica_current_months', 'fica_validity_months', 'fica_expiring_soon_days'] as $key) {
            $this->assertStringContainsString("'{$key}' =>", $blade, $key);
        }
        $this->assertTrue(\Illuminate\Support\Facades\Route::has('corex.settings.fica-windows.save'));
    }

    public function test_every_window_reaches_the_setup_wizard_with_its_default_and_its_saver(): void
    {
        $config = config('agency-onboarding-copy');
        $keys = [];
        array_walk_recursive($config, function ($v, $k) use (&$keys) {
            if ($k === 'key') {
                $keys[] = $v;
            }
        });
        foreach (FicaWindows::WINDOWS as $key => [$default]) {
            $this->assertContains($key, $keys, "{$key} reaches the Setup Wizard (non-negotiable #10a)");
        }

        $controls = [];
        array_walk_recursive($config, function () {});
        $collect = function (array $node) use (&$collect, &$controls) {
            if (isset($node['key'], $node['source'])) {
                $controls[$node['key']] = $node;
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $collect($child);
                }
            }
        };
        $collect($config);
        foreach (FicaWindows::WINDOWS as $key => [$default, $min, $max]) {
            $this->assertSame($default, $controls[$key]['default'] ?? null, "{$key} wizard default = the standard");
            $this->assertSame($min, $controls[$key]['min'] ?? null);
            $this->assertSame($max, $controls[$key]['max'] ?? null);
        }
    }
}
