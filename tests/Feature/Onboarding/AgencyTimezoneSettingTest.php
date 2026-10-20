<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use App\Services\Features\AgencyTypeFeatureSeeder;
use App\Support\AppDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
use Tests\TestCase;

/**
 * Audit 2026-10-20 (L7): the agency's OWN timezone. `agencies.timezone` (an IANA name, null = the application's) is what Agency::outreachTimezone() and AppDate::agencyTimezone()
 * read; it is set on Company Settings and in the Setup Wizard identity step (guarded saver - an absent box never wipes it). Rentals spec 30.8, wizard spec 5.
 */
final class AgencyTimezoneSettingTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    private function agencyWithAdmin(): array
    {
        $agency = Agency::create(['name' => 'Cape Rentals ' . uniqid(), 'slug' => 'cape-' . uniqid(), 'agency_type' => 'rentals',
            'sidebar_color' => '#0ea5e9', 'icon_color' => '#0ea5e9', 'default_color' => '#0b2a4a', 'button_color' => '#0ea5e9']);
        app(AgencyTypeFeatureSeeder::class)->seedNewAgency($agency);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Head Office']);
        $agency->update(['default_branch_id' => $branch->id]);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);

        return [$agency, $admin];
    }

    public function test_the_helper_reads_the_agencys_own_timezone_and_falls_back_to_the_application_timezone(): void
    {
        [$agency] = $this->agencyWithAdmin();

        $this->assertNull($agency->timezone);
        $this->assertSame(config('app.timezone'), $agency->outreachTimezone(), 'no setting = the application timezone (never a literal UTC or a place name)');
        $this->assertSame(config('app.timezone'), AppDate::agencyTimezone($agency));
        $this->assertSame(config('app.timezone'), AppDate::agencyTimezone(null));

        $agency->update(['timezone' => 'Europe/London']);
        $this->assertSame('Europe/London', $agency->fresh()->outreachTimezone());
        $this->assertSame('Europe/London', AppDate::agencyTimezone($agency->fresh()));

        // a value that is not a timezone (a hand edit) never crashes a public page: it is ignored
        \Illuminate\Support\Facades\DB::table('agencies')->where('id', $agency->id)->update(['timezone' => 'Mars/Olympus']);
        $this->assertSame(config('app.timezone'), $agency->fresh()->outreachTimezone());

        // and times are SHOWN in it: 10:00 app time on a day it is 09:00 in London
        $agency->update(['timezone' => 'Pacific/Auckland']);
        $at = \Illuminate\Support\Carbon::parse('2026-10-14 10:00:00', config('app.timezone'));
        $this->assertNotSame(AppDate::dateTime($at), AppDate::dateTime($at, AppDate::agencyTimezone($agency->fresh())));
    }

    public function test_the_setup_wizard_identity_step_saves_the_timezone_and_an_absent_box_never_wipes_it(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();
        $fields = $this->browserFormFields($admin, 'identity');
        $this->assertArrayHasKey('timezone', $fields, 'the identity step renders the control');
        $this->assertSame('', $fields['timezone'], 'a new agency starts on the system default');

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'identity']), array_replace($fields, ['timezone' => 'Africa/Nairobi']))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Africa/Nairobi', $agency->fresh()->timezone);

        // the control is shown with the saved value
        $this->assertSame('Africa/Nairobi', $this->browserFormFields($admin, 'identity')['timezone']);

        // a post that never carried the box (the same saver serves other forms) leaves it alone
        $without = $fields;
        unset($without['timezone']);
        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'identity']), $without)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Africa/Nairobi', $agency->fresh()->timezone, 'an absent box is not a blank box');

        // choosing the system default clears it; nonsense is refused
        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'identity']), array_replace($fields, ['timezone' => '']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($agency->fresh()->timezone);
        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'identity']), array_replace($fields, ['timezone' => 'Not/AZone']))->assertSessionHasErrors('timezone');
        $this->assertNull($agency->fresh()->timezone);
    }

    public function test_company_settings_shows_the_field_and_saves_it_only_when_the_form_posts_it(): void
    {
        [$agency, $admin] = $this->agencyWithAdmin();

        $this->actingAs($admin)->get(route('admin.company-settings'))->assertOk()->assertSee('data-agency-timezone', false)->assertSee('Africa/Johannesburg');

        $base = ['trading_name' => 'Cape Rentals'];
        $this->actingAs($admin)->put(route('admin.company-settings.update', $agency), $base + ['timezone' => 'Europe/London'])->assertSessionHasNoErrors();
        $this->assertSame('Europe/London', $agency->fresh()->timezone);

        $this->actingAs($admin)->put(route('admin.company-settings.update', $agency), $base)->assertSessionHasNoErrors();
        $this->assertSame('Europe/London', $agency->fresh()->timezone, 'a sibling form that never posts the box does not touch it');

        $this->actingAs($admin)->put(route('admin.company-settings.update', $agency), $base + ['timezone' => 'Nowhere/Land'])->assertSessionHasErrors('timezone');
        $this->assertSame('Europe/London', $agency->fresh()->timezone);
    }
}
