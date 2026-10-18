<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Screen rules found on the 9 Oct 2026 walk of the inspections screens:
 *  - the inspection screens never use the browser's confirm() / alert() / prompt() — the app's own dialog only;
 *  - an inspection that is cancelled or archived does not offer a public-link button the server would refuse.
 */
final class RentalInspectionScreenRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_inspection_view_or_script_uses_a_browser_dialog(): void
    {
        $files = [
            resource_path('views/corex/properties/partials/rental-inspection-recording.blade.php'),
            resource_path('views/corex/properties/partials/rental-inspection-wetink-form.blade.php'),
            resource_path('views/corex/properties/partials/rental-inspection-refusal-form.blade.php'),
            resource_path('views/corex/properties/partials/rental-inspection-item-cell.blade.php'),
            resource_path('views/rental-inspections/public/show.blade.php'),
            resource_path('views/rental-inspections/public/partials/sign-section.blade.php'),
            public_path('js/corex-photo-batch-uploader.js'),
            resource_path('js/rental-inspection-signing.js'),
        ];
        foreach (glob(resource_path('views/corex/rental-inspections/{,partials/}*.blade.php'), GLOB_BRACE) as $f) {
            $files[] = $f;
        }

        $offenders = [];
        foreach ($files as $file) {
            $this->assertFileExists($file);
            foreach (file($file) as $i => $line) {
                if (preg_match('/(?<![A-Za-z_.])(?:window\.)?(?:confirm|alert|prompt)\(/', $line)
                    && ! preg_match('/^\s*(\{\{--|\*|\/\/)/', $line)
                    && ! str_contains($line, 'corexConfirm')) {
                    $offenders[] = basename($file) . ':' . ($i + 1) . '  ' . trim(mb_substr($line, 0, 110));
                }
            }
        }

        $this->assertSame([], $offenders, "browser dialogs left on the inspection screens:\n" . implode("\n", $offenders));
    }

    private function inspection(string $status): array
    {
        $agency = Agency::create(['name' => 'Screen Rules Agency', 'slug' => 'sr-' . uniqid()]);
        $branch = Branch::forceCreate(['agency_id' => $agency->id, 'name' => 'Main']);
        RentalInspectionSetting::updateOrCreate(['agency_id' => $agency->id], ['empty_checklist_blocks_signing' => false, 'routine_follows_full_checks' => false]);
        $admin = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $admin->id,
            'title' => '5 Screen Rules Road', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $lease = Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => '2026-09-01',
            'created_by_user_id' => $admin->id, 'source' => 'manual',
        ]);
        $contact = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Tess', 'last_name' => 'T' . uniqid(), 'email' => 't-' . uniqid() . '@example.test']);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $contact->id, 'is_primary' => true]);
        $inspection = RentalInspection::create([
            'agency_id' => $agency->id, 'lease_id' => $lease->id, 'type' => 'in', 'status' => $status, 'created_by_user_id' => $admin->id,
        ]);

        return [$admin, $inspection];
    }

    public function test_a_draft_inspection_offers_generate_link(): void
    {
        [$admin, $inspection] = $this->inspection('draft');

        $html = $this->actingAs($admin)->get(route('corex.rental-inspections.show', $inspection))->assertOk()->getContent();

        $this->assertStringContainsString('Generate link', $html);
        $this->assertStringNotContainsString('No link is offered', $html);
    }

    public function test_a_cancelled_inspection_does_not_offer_a_link_button_the_server_would_refuse(): void
    {
        [$admin, $inspection] = $this->inspection('cancelled');

        $html = $this->actingAs($admin)->get(route('corex.rental-inspections.show', $inspection))->assertOk()->getContent();

        $this->assertStringNotContainsString('Generate link', $html);
        $this->assertStringNotContainsString('Regenerate link', $html);
        $this->assertStringContainsString('No link is offered while this inspection is cancelled', $html);

        // and the server really does refuse it, so the missing button hides nothing that worked
        $this->actingAs($admin)->post(route('corex.rental-inspections.public-link.generate', $inspection))->assertSessionHasErrors('rental_inspection');
        $this->assertNull($inspection->fresh()->public_token);
    }
}
