<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\Role;
use App\Models\User;
use App\Services\Compliance\FicaTrustAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
use Tests\TestCase;

/**
 * The FICA form told every tenant and landlord to pay "to the Reos trust account" - one agency's name hardcoded in the wording.
 * It is now the agency's own setting (Settings -> Profile & Account -> FICA, and the Setup Wizard's Compliance step), rendered
 * per agency, with neutral wording when an agency has not set one. Every test here reads the SECOND agency.
 */
final class FicaTrustAccountSettingTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function agency(string $name, ?string $trust = null): Agency
    {
        $a = Agency::create(['name' => $name, 'slug' => Str::slug($name) . '-' . uniqid()]);
        if ($trust !== null) {
            $a->forceFill(['rental_trust_account_name' => $trust])->save();
        }

        return $a->fresh();
    }

    private function admin(Agency $agency): User
    {
        $branch = Branch::firstOrCreate(['agency_id' => $agency->id, 'name' => 'Main']);
        foreach (['admin' => 'Administrator', 'branch_manager' => 'Branch Manager', 'agent' => 'Agent'] as $name => $label) {
            Role::create(['name' => $name, 'label' => $label, 'agency_id' => $agency->id]);
        }
        Role::clearCache();

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
    }

    private function formFor(Agency $agency): string
    {
        $branch = Branch::firstOrCreate(['agency_id' => $agency->id, 'name' => 'Main']);
        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Thandi', 'last_name' => 'Ngcobo', 'email' => 'thandi@example.test']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $submission = FicaSubmission::create([
            'contact_id' => $contact->id, 'agency_id' => $agency->id, 'branch_id' => $branch->id, 'requested_by' => $agent->id,
            'status' => 'draft', 'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);

        return $this->get(route('fica.form', $submission->token))->assertOk()->getContent();
    }

    public function test_the_form_names_each_agencys_own_trust_account_and_never_reos(): void
    {
        $karoo = $this->agency('Karoo Lettings', 'Karoo Lettings Trust Account');
        $cape = $this->agency('Cape Rentals', 'Cape Rentals Client Account');

        $karooHtml = $this->formFor($karoo);
        $capeHtml = $this->formFor($cape);

        $this->assertStringContainsString('trustAccount: "the Karoo Lettings Trust Account"', $karooHtml);
        $this->assertStringNotContainsString('Cape Rentals Client Account', $karooHtml);
        $this->assertStringContainsString('trustAccount: "the Cape Rentals Client Account"', $capeHtml);
        foreach ([$karooHtml, $capeHtml] as $html) {
            $this->assertStringNotContainsStringIgnoringCase('reos', $html, 'no agency name is hardcoded in the wording');
            $this->assertStringContainsString("this.trustAccount + ' — cash is never accepted", $html, 'the tenant sentence uses the setting');
        }
    }

    public function test_an_agency_with_no_setting_gets_neutral_wording(): void
    {
        $agency = $this->agency('Plain Agency');
        $html = $this->formFor($agency);

        $this->assertSame("the agency's trust account", FicaTrustAccount::phraseFor($agency->id));
        $this->assertStringContainsString("trustAccount: \"the agency\\u0027s trust account\"", $html);
        $this->assertStringNotContainsStringIgnoringCase('reos', $html);
    }

    public function test_a_typed_leading_the_is_not_doubled_and_blank_means_neutral(): void
    {
        $this->assertSame('the Smith Trust Account', FicaTrustAccount::phraseFor($this->agency('A', 'the Smith Trust Account')->id));
        $this->assertSame('the Smith Trust Account', FicaTrustAccount::phraseFor($this->agency('B', 'Smith Trust Account')->id));
        $this->assertSame("the agency's trust account", FicaTrustAccount::phraseFor($this->agency('C', '   ')->id));
        $this->assertSame("the agency's trust account", FicaTrustAccount::phraseFor(null));
    }

    public function test_the_wizard_compliance_step_shows_saves_and_reopens_the_setting(): void
    {
        $agency = $this->agency('Karoo Lettings');
        $admin = $this->admin($agency);

        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'compliance']))
            ->assertOk()->assertSee('name="rental_trust_account_name"', false)->assertSee('Trust account name (rent and deposits)');

        $fields = array_replace($this->browserFormFields($admin, 'compliance'), ['rental_trust_account_name' => 'Karoo Lettings Trust Account']);
        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'compliance']), $fields)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Karoo Lettings Trust Account', $agency->fresh()->rental_trust_account_name);
        $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'compliance']))->assertOk()->assertSee('Karoo Lettings Trust Account');
    }

    public function test_a_post_that_never_rendered_the_field_leaves_it_alone_and_blank_clears_it(): void
    {
        $agency = $this->agency('Karoo Lettings', 'Karoo Lettings Trust Account');
        $admin = $this->admin($agency);

        $fields = $this->browserFormFields($admin, 'compliance');
        unset($fields['rental_trust_account_name']);
        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'compliance']), $fields)->assertRedirect();
        $this->assertSame('Karoo Lettings Trust Account', $agency->fresh()->rental_trust_account_name, 'absent = leave it alone');

        $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'compliance']), array_replace($fields, ['rental_trust_account_name' => '']))->assertRedirect();
        $this->assertNull($agency->fresh()->rental_trust_account_name, 'blank = back to the neutral wording');
    }

    public function test_the_settings_page_saver_writes_the_same_column_for_its_own_agency_only(): void
    {
        $karoo = $this->agency('Karoo Lettings');
        $cape = $this->agency('Cape Rentals', 'Cape Rentals Client Account');
        $admin = $this->admin($karoo);

        $this->actingAs($admin)->post(route('corex.settings.fica-windows.save'), ['rental_trust_account_name' => '  Karoo Client Account '])->assertRedirect();

        $this->assertSame('Karoo Client Account', $karoo->fresh()->rental_trust_account_name);
        $this->assertSame('Cape Rentals Client Account', $cape->fresh()->rental_trust_account_name, 'another agency is untouched');
    }
}
