<?php

namespace Tests\Feature\RentalFaultFlow;

use App\Mail\Rentals\LeaseAgreementStatusMail;
use App\Mail\Rentals\RentalDisputeSentBackMail;
use App\Mail\Rentals\RentalJobCardCrewCompletedLandlordMail;
use App\Mail\Rentals\RentalJobCardCrewLinkMail;
use App\Mail\Rentals\RentalLandlordDisputeMail;
use App\Mail\Rentals\RentalTenantCompletionCheckMail;
use App\Mail\Rentals\RentalWorkOrderAppointmentMail;
use App\Mail\Rentals\RentalWorkOrderOwnerMail;
use App\Mail\Rentals\RentalWorkOrderSupplierMail;
use App\Mail\Rentals\RentalWorkOrderTenantMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Models\User;
use App\Services\Rentals\RentalDocumentPdfService;
use App\Services\Rentals\RentalPortalOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-452 - the half-filled trading name ("Johan and Elize Properties T/A") must never reach a customer from ANY rentals email, page or PDF.
 * ONE helper builds the agency's display name - {@see Agency::displayNameFor()} - and nothing in the rentals code builds it by hand.
 * Section 22.14 of .ai/specs/rentals-faults-work-orders.md.
 */
final class AgencyDisplayNameClassTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Walk Agency', 'trading_name' => 'Acme Properties T/A', 'slug' => 'dn-' . uniqid()]);
    }

    // ── the rule, as a scan: no rentals file builds the agency name by hand ──────────────────────

    /** @return list<string> every rentals file that renders or builds a customer-facing agency name */
    private function rentalsFiles(): array
    {
        $roots = [
            'app/Mail/Rentals', 'app/Services/Rentals', 'app/Http/Controllers/CoreX', 'app/Http/Controllers/Api/V1',
            'resources/views/emails/rentals', 'resources/views/rentals', 'resources/views/corex',
        ];
        $files = [];
        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $path = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $f->getPathname());
                $name = basename($path);
                $isRentals = str_starts_with($name, 'Rental') || str_starts_with($name, 'Lease') || str_starts_with($name, 'Crew') || str_starts_with($name, 'Contractor')
                    || str_contains($path, '/rental') || str_contains($path, '/rentals/') || str_contains($path, 'emails/rentals') || str_contains($path, 'Mail/Rentals') || str_contains($path, 'Services/Rentals');
                if ($f->isFile() && $isRentals && preg_match('/\.php$/', $path)) {
                    $files[] = $path;
                }
            }
        }
        foreach (['app/Http/Controllers/RentalOwnerNoticeController.php', 'app/Http/Controllers/RentalFaultSettingsDisputeController.php', 'app/Http/Controllers/ContractorSecureLinkController.php',
            'app/Http/Controllers/CrewJobLinkController.php', 'app/Http/Controllers/CrewPageController.php', 'app/Models/RentalApplicationDeclineEmailSetting.php', 'app/Mail/Signatures/BaseSignatureMail.php'] as $extra) {
            $files[] = $extra;
        }

        return array_values(array_unique($files));
    }

    public function test_no_rentals_template_or_class_builds_the_agency_name_by_hand(): void
    {
        $forbidden = [
            '/->trading_name\b/' => 'reads trading_name',
            '/agency\??->name\b(?!_)/' => 'reads ->agency->name directly',
            '/\$agency\??->name\b/' => 'reads $agency->name directly',
            '/publicBrandingFor\([^)]*\)\[\'name\'\]\s*\?\?/' => 'builds a fallback around the branding name',
        ];
        $hits = [];
        $scanned = 0;
        foreach ($this->rentalsFiles() as $rel) {
            $abs = base_path($rel);
            if (! is_file($abs)) {
                continue;
            }
            $scanned++;
            foreach (file($abs) as $n => $line) {
                foreach ($forbidden as $re => $why) {
                    if (preg_match($re, $line) && ! str_contains($line, 'displayNameFor')) {
                        $hits[] = "{$rel}:" . ($n + 1) . " ({$why}): " . trim($line);
                    }
                }
            }
        }
        $this->assertGreaterThan(40, $scanned, 'the scan really looked at the rentals files');
        $this->assertSame([], $hits, "these build the agency's name by hand - use Agency::displayNameFor():\n" . implode("\n", $hits));
    }

    // ── the helper ──────────────────────────────────────────────────────────────────────────────

    public function test_the_helper_is_the_agencys_own_name_with_one_fallback_chain(): void
    {
        $this->assertSame('Walk Agency', Agency::displayNameFor($this->agency));
        $this->assertSame('Walk Agency', Agency::publicBrandingFor($this->agency->id)['name'], 'the portal branding reads the same helper');
        $this->assertSame('Your agency', Agency::displayNameFor(null));
        $this->assertSame('CoreX', Agency::displayNameFor(null, 'CoreX'));
        $this->assertSame('our agency', Agency::publicBrandingFor(999999)['name']);
        $this->assertSame('Only Trading', Agency::displayNameFor(new Agency(['name' => '  ', 'trading_name' => 'Only Trading'])));
    }

    // ── behaviour: no T/A reaches a customer ────────────────────────────────────────────────────

    private function workOrder(): RentalWorkOrder
    {
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main', 'code' => 'M-' . $this->agency->id, 'is_active' => true]);
        $agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'name' => 'Agnes Agent']);
        $property = Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id, 'title' => 'DN Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $wo = new RentalWorkOrder();
        $wo->forceFill(['id' => 1, 'agency_id' => $this->agency->id, 'property_id' => $property->id, 'title' => 'Tap', 'description' => 'Drips']);
        $wo->setRelation('property', $property->setRelation('agency', $this->agency));
        $wo->setRelation('agency', $this->agency);

        return $wo;
    }

    public function test_the_work_order_mails_name_the_agency_without_the_half_filled_trading_name(): void
    {
        $wo = $this->workOrder();
        foreach ([
            new RentalWorkOrderTenantMail($wo, 'Tina'),
            new RentalWorkOrderOwnerMail($wo, 'quote', 'Lenny'),
            new RentalWorkOrderSupplierMail($wo),
            new RentalWorkOrderAppointmentMail($wo, 'Tina'),
        ] as $mail) {
            $this->assertSame('Walk Agency', $mail->agencyName, class_basename($mail));
        }
    }

    public function test_the_mails_with_their_own_agency_name_method_use_the_helper(): void
    {
        $wo = $this->workOrder();
        foreach ([RentalDisputeSentBackMail::class, RentalLandlordDisputeMail::class, RentalTenantCompletionCheckMail::class] as $class) {
            $mail = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            $mail->workOrder = $wo;
            $m = new \ReflectionMethod($class, 'agencyName');
            $m->setAccessible(true);
            $this->assertSame('Walk Agency', $m->invoke($mail), class_basename($class));
        }
        $lease = new Lease();
        $lease->setRelation('agency', $this->agency);
        $status = (new \ReflectionClass(LeaseAgreementStatusMail::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($status, 'lease'))->setValue($status, $lease);
        $m = new \ReflectionMethod(LeaseAgreementStatusMail::class, 'agencyName');
        $m->setAccessible(true);
        $this->assertSame('Walk Agency', $m->invoke($status));
    }

    public function test_the_crew_mails_show_the_helper_name_in_their_view_data_and_subject(): void
    {
        $card = new RentalJobCard();
        $card->forceFill(['title' => 'Fix the gate']);
        $card->setRelation('agency', $this->agency);
        $card->setRelation('property', null);
        $card->setRelation('crew', null);

        $link = (new \ReflectionClass(RentalJobCardCrewLinkMail::class))->newInstanceWithoutConstructor();
        $link->jobCard = $card;
        $link->url = 'https://example.invalid/x';
        $link->expiresOn = null;
        $this->assertSame('Walk Agency', $link->content()->with['agencyName']);

        $done = (new \ReflectionClass(RentalJobCardCrewCompletedLandlordMail::class))->newInstanceWithoutConstructor();
        $done->jobCard = $card;
        $done->landlordName = 'Lenny';
        $done->signedByName = 'Crew';
        $done->completedOn = '13 Oct 2026';
        $this->assertSame('Walk Agency', $done->content()->with['agencyName']);
        $this->assertStringNotContainsString('T/A', json_encode($done->content()->with) . json_encode($link->content()->with));
    }

    public function test_the_portal_overview_and_the_pdf_service_use_the_helper(): void
    {
        $src = file_get_contents(base_path('app/Services/Rentals/RentalPortalOverviewService.php'));
        $this->assertStringContainsString('Agency::displayNameFor($agency)', $src);
        $pdf = file_get_contents(base_path('app/Services/Rentals/' . class_basename(RentalDocumentPdfService::class) . '.php'));
        $this->assertSame(9, substr_count($pdf, "displayNameFor("), 'every PDF the rentals module makes reads the agency name through the helper');
        $this->assertStringNotContainsString('->agency?->name', $pdf);
    }
}
