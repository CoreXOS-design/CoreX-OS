<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.31 / CLAUDE.md non-negotiable #10a — the two supplier-invoice upload limits
 * (`invoice_max_file_mb`, `invoice_allowed_file_types`) each have a control on Settings → Rental Work Orders AND on the Setup
 * Wizard's Rentals step, saved by ONE narrow has()-guarded saver, shown back with their saved values, isolated per agency, and
 * a wizard post that omits one leaves it alone (onboarding spec §6.1).
 */
final class SupplierInvoiceSettingsWizardTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    private const KEYS = ['invoice_max_file_mb', 'invoice_allowed_file_types'];

    private function agency(string $name = 'Coastal Realty'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => 'coastal-' . uniqid()]);
    }

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
    }

    private function saveStep(User $admin, Agency $agency, array $overrides, array $remove = []): \Illuminate\Testing\TestResponse
    {
        $fields = array_replace($this->browserFormFields($admin, 'leases'), $this->alpineListRows($agency), $overrides);
        foreach ($remove as $key) {
            unset($fields[$key]);
        }

        return $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $fields);
    }

    public function test_an_agency_that_never_set_anything_gets_neutral_defaults(): void
    {
        $agency = $this->agency();

        $this->assertSame(10, RentalWorkOrderSetting::invoiceMaxFileMbFor($agency->id));
        $this->assertSame('pdf_images', RentalWorkOrderSetting::invoiceAllowedFileTypesFor($agency->id));
        $this->assertSame(['pdf', 'jpg', 'jpeg', 'png', 'webp'], RentalWorkOrderSetting::invoiceAllowedExtensionsFor($agency->id));
    }

    public function test_the_settings_page_shows_both_controls_with_their_current_values(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $agency->id], ['invoice_max_file_mb' => 23, 'invoice_allowed_file_types' => 'pdf']);

        $html = $this->actingAs($admin)->get(route('corex.settings.rental-work-orders.edit'))->assertOk()->getContent();

        foreach (self::KEYS as $key) {
            $this->assertStringContainsString('name="' . $key . '"', $html, $key);
        }
        $this->assertStringContainsString('value="23"', $html);
        $this->assertMatchesRegularExpression('/<option value="pdf" selected/', $html);
        $this->assertStringContainsString(route('corex.settings.rental-work-orders.invoice-limits'), $html);
    }

    public function test_each_control_is_in_the_wizard_with_an_explanation_and_a_concrete_consequence(): void
    {
        $controls = collect(config('agency-onboarding-copy.leases.controls'))->where('source', 'rental_work_orders')->keyBy('key');

        foreach (self::KEYS as $key) {
            $control = $controls->get($key);
            $this->assertNotNull($control, "{$key} must be a wizard control");
            $this->assertGreaterThan(60, strlen((string) $control['explain']), "{$key}: explain is a full sentence");
            $this->assertGreaterThan(60, strlen((string) $control['affects']), "{$key}: affects names a concrete consequence");
            $this->assertStringNotContainsStringIgnoringCase('home finders', $control['explain'] . $control['affects'], 'no agency-specific wording');
        }
        $this->assertSame(10, $controls['invoice_max_file_mb']['default']);
        $this->assertSame('pdf_images', $controls['invoice_allowed_file_types']['default']);
        $this->assertSame(array_keys(RentalWorkOrderSetting::INVOICE_TYPE_EXTENSIONS), array_keys($controls['invoice_allowed_file_types']['options']));
    }

    public function test_the_wizard_step_renders_the_controls_saves_them_and_reopens_with_the_saved_values(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $html = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']))->assertOk()->getContent();
        foreach (self::KEYS as $key) {
            $this->assertStringContainsString('name="' . $key . '"', $html, "{$key} is on the wizard page");
        }

        $this->saveStep($admin, $agency, ['invoice_max_file_mb' => '18', 'invoice_allowed_file_types' => 'pdf_images_heic'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(18, RentalWorkOrderSetting::invoiceMaxFileMbFor($agency->id));
        $this->assertSame('pdf_images_heic', RentalWorkOrderSetting::invoiceAllowedFileTypesFor($agency->id));

        $html = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="invoice_max_file_mb"[^>]*value="18"|value="18"[^>]*name="invoice_max_file_mb"/', $html);
        $this->assertMatchesRegularExpression('/<option value="pdf_images_heic"[^>]*selected/', $html);
    }

    public function test_a_wizard_post_that_omits_a_field_does_not_wipe_it_and_a_bad_value_saves_nothing(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $agency->id], ['invoice_max_file_mb' => 21, 'invoice_allowed_file_types' => 'pdf']);

        $this->saveStep($admin, $agency, [], self::KEYS)->assertSessionHasNoErrors();
        $this->assertSame(21, RentalWorkOrderSetting::invoiceMaxFileMbFor($agency->id), 'absent means leave alone');
        $this->assertSame('pdf', RentalWorkOrderSetting::invoiceAllowedFileTypesFor($agency->id));

        $this->saveStep($admin, $agency, ['invoice_max_file_mb' => '99'])->assertSessionHasErrors('invoice_max_file_mb');
        $this->assertSame(21, RentalWorkOrderSetting::invoiceMaxFileMbFor($agency->id));
    }

    public function test_a_second_agency_keeps_its_own_values(): void
    {
        $home = $this->agency('Home Lettings');
        $cape = $this->agency('Cape Town Rentals');
        $capeAdmin = $this->admin($cape);

        $this->actingAs($capeAdmin)->post(route('corex.settings.rental-work-orders.invoice-limits'), ['invoice_max_file_mb' => 40, 'invoice_allowed_file_types' => 'pdf'])->assertSessionHasNoErrors();

        $this->assertSame(40, RentalWorkOrderSetting::invoiceMaxFileMbFor($cape->id));
        $this->assertSame(10, RentalWorkOrderSetting::invoiceMaxFileMbFor($home->id));
        $this->assertSame('pdf_images', RentalWorkOrderSetting::invoiceAllowedFileTypesFor($home->id));
    }
}
