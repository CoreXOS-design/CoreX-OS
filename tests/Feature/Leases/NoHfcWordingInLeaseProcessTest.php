<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use Tests\TestCase;

/**
 * leases.md §15.12.4 / §15.17 (Build L0) — CoreX ships NO lease document, so no wording, fee or name
 * belonging to one agency may appear in anything the lease e-sign series creates: code, config, views,
 * migrations. An agency's own lease carries its own wording; this keeps the shared code neutral. The
 * second agency must never see another agency's name, brand or fee scheme.
 *
 * Scans every file the series creates (those that exist so far — later builds' files are listed too and
 * are checked the moment they land). Tests are not scanned: a fixture may carry one agency's own field
 * names as data.
 */
final class NoHfcWordingInLeaseProcessTest extends TestCase
{
    private const FORBIDDEN = [
        'Home Finders' => '/home\s+finders/i',
        'HFC' => '/\bHFC\b/i',
        "Let's Assist" => '/let[\'’]?s[\s_]*assist/i',
    ];

    /** @return array<int,string> repo-relative paths and globs */
    private static function seriesFiles(): array
    {
        return [
            'config/lease-agreement-fields.php',
            'app/Models/LeaseAgreementTerms.php',
            'app/Services/Rentals/LeaseCaptureService.php',
            'app/Services/Rentals/LeaseSigningLauncher.php',
            'app/Services/Rentals/LeaseAgreementDocumentValues.php',
            'app/Services/Rentals/LeaseAgreementValuesReader.php',
            'app/Services/Rentals/LeaseAgreementHarvest.php',
            'app/Services/Rentals/LeaseAgreementCheck.php',
            'app/Services/Rentals/LeaseAgreementTemplateGuard.php',
            'app/Services/Rentals/PreviousTermValuesReader.php',
            'app/Services/Rentals/LeaseSigningStateService.php',
            'app/Mail/Rentals/LeaseAgreementStatusMail.php',
            'resources/views/emails/rentals/lease-agreement-status.blade.php',
            'app/Events/Docuperfect/SignatureEnvelope*.php',
            'app/Events/Docuperfect/TemplateAgencyAssigned.php',
            'app/Events/Rentals/LeaseAgreement*.php',
            'app/Listeners/Rentals/UpdateLeaseSigningState.php',
            'app/Console/Commands/AssignTemplateToAgency.php',
            'app/Console/Commands/ReconcileLeaseSigning.php',
            'app/Http/Requests/CoreX/LeaseCaptureRequest.php',
            'app/Http/Controllers/Concerns/HandlesLeaseCapture.php',
            'app/Exceptions/Rentals/*.php',
            'app/Http/Middleware/EnsureLeaseAgreementConfirmed.php',
            'app/Http/Controllers/CoreX/LeaseAgreementConfirmController.php',
            'app/Http/Controllers/Api/V1/LeaseCaptureApiController.php',
            'database/migrations/2026_10_12_1000*.php',
            'resources/views/corex/leases/capture.blade.php',
            'resources/views/corex/leases/_agreement-fields.blade.php',
            'resources/views/corex/leases/_signing-checklist.blade.php',
            'resources/views/corex/leases/_signers-panel.blade.php',
            'resources/views/corex/leases/_agreement-card.blade.php',
            'resources/views/corex/rental-lease-templates/_field-map.blade.php',
            'resources/views/agency-setup/steps/rentals-lease-agreement.blade.php',
        ];
    }

    public function test_nothing_the_series_creates_names_one_agency_or_its_fees(): void
    {
        $scanned = [];
        $offences = [];

        foreach (self::seriesFiles() as $pattern) {
            foreach (glob(base_path($pattern)) ?: [] as $file) {
                $scanned[] = $file;
                $text = (string) file_get_contents($file);
                foreach (self::FORBIDDEN as $label => $regex) {
                    if (preg_match($regex, $text, $m, PREG_OFFSET_CAPTURE)) {
                        $line = substr_count(substr($text, 0, $m[0][1]), "\n") + 1;
                        $offences[] = str_replace(base_path() . '/', '', $file) . ":{$line} — \"{$label}\"";
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(20, count($scanned), 'the scan must actually cover the series\' files');
        $this->assertSame([], $offences, "Shared lease code must name no agency or fee scheme:\n  " . implode("\n  ", $offences));
    }

    public function test_the_scan_really_catches_each_forbidden_phrase(): void
    {
        foreach (['Home Finders Coastal', 'home  finders', 'HFC', "Let's Assist fee", 'lets_assists_fee', 'Lets Assist'] as $sample) {
            $hit = false;
            foreach (self::FORBIDDEN as $regex) {
                $hit = $hit || preg_match($regex, $sample) === 1;
            }
            $this->assertTrue($hit, "\"$sample\" should be caught");
        }
        foreach (['Cape Rentals', 'Other deduction', 'agent_service_fee'] as $sample) {
            foreach (self::FORBIDDEN as $regex) {
                $this->assertSame(0, preg_match($regex, $sample), "\"$sample\" must not be flagged");
            }
        }
    }
}
