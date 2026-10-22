<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\RmcpSection;
use App\Models\Compliance\RmcpVersion;
use App\Services\Compliance\CompliancePdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Compliance\Policy\PolicyTestHelpers;
use Tests\TestCase;

/**
 * The "PDF" buttons on the RMCP, the agency policies and the two staff-acknowledgement registers
 * must hand over a REAL PDF download — not the on-screen HTML page they used to return (Staging,
 * 13 Oct 2026: /corex/compliance/rmcp-manager/1/pdf showed the viewer page and nothing downloaded).
 */
final class CompliancePdfDownloadTest extends TestCase
{
    use RefreshDatabase, PolicyTestHelpers;

    /** Self-contained on purpose (Staging has no RequiresChromiumPdf helper): skip unless Chromium + puppeteer are here. */
    private function requireChromiumPdf(): void
    {
        if (! is_file('/usr/bin/chromium')) {
            $this->markTestSkipped('No Chromium on this box to render the PDF.');
        }
        if (! is_dir(base_path('node_modules/puppeteer'))) {
            $this->markTestSkipped('The puppeteer node module is not installed in this checkout (no node_modules).');
        }
        config(['services.pdf.puppeteer_browser_path' => '/usr/bin/chromium']);
    }

    private int $agencyA;
    private int $agencyB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyA = $this->seedAgency();
        $this->agencyB = $this->seedAgency();
        \DB::table('agencies')->where('id', $this->agencyA)->update(['name' => 'Ramsgate Realty']);
    }

    private function rmcp(int $agencyId, int $number, string $status, ?string $approvedOn = null): RmcpVersion
    {
        $approver = $this->makeUser($agencyId);
        $v = RmcpVersion::create([
            'agency_id' => $agencyId, 'version_number' => $number, 'title' => 'Risk Management and Compliance Programme',
            'status' => $status,
            'approved_by' => $approvedOn ? $approver->id : null, 'approved_at' => $approvedOn,
            'approver_title' => $approvedOn ? 'Compliance Officer' : null,
            'effective_from' => $approvedOn ? '2026-04-22' : null, 'next_review_due' => $approvedOn ? '2027-04-21' : null,
        ]);
        foreach ([1 => 'Purpose and scope', 2 => 'Customer due diligence', 3 => 'Record keeping'] as $order => $title) {
            RmcpSection::create([
                'agency_id' => $agencyId, 'rmcp_version_id' => $v->id, 'section_type' => 'section', 'display_order' => $order,
                'section_number' => (string) $order, 'title' => $title, 'body_html' => "<p>Body of {$title} for {{agency.name}}.</p>",
                'requires_acknowledgement' => true,
            ]);
        }

        return $v;
    }

    private function pdfText(string $bytes): ?string
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'cpdf') . '.pdf';
        file_put_contents($tmp, $bytes);
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null');
        @unlink($tmp);

        return $text;
    }

    public function test_the_rmcp_pdf_is_a_real_named_download_for_every_version_not_the_html_page(): void
    {
        $this->requireChromiumPdf();
        $admin  = $this->makeUser($this->agencyA);
        $active = $this->rmcp($this->agencyA, 1, 'active', '2026-04-21 10:00:00');
        $draft  = $this->rmcp($this->agencyA, 2, 'draft');

        $r = $this->actingAs($admin)->get(route('compliance.rmcp.pdf', $active));
        $r->assertOk();
        self::assertSame('application/pdf', $r->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="rmcp-v1-ramsgate-realty-approved-2026-04-21.pdf"', (string) $r->headers->get('Content-Disposition'));
        $bytes = $r->getContent();
        self::assertStringStartsWith('%PDF', $bytes);

        if (($text = $this->pdfText($bytes)) !== null) {
            foreach (['Ramsgate Realty', 'Version 1', 'Active', '21 April 2026', 'Compliance Officer', 'Next review due', 'Purpose and scope', 'Customer due diligence', 'Record keeping', 'Body of Record keeping for Ramsgate Realty'] as $needle) {
                self::assertStringContainsString($needle, $text, "missing in the PDF: {$needle}");
            }
            // AT-506: the RMCP footer reads "<agency> · RMCP v1 · page 1 of N · Initials ____".
            self::assertMatchesRegularExpression('/Ramsgate Realty\s+·\s+RMCP v1\s+·\s+page 1 of \d+\s+·\s+Initials/', $text, 'page numbers + initials in the footer');
            self::assertStringNotContainsString('RMCP List', $text, 'the app screen must not be in the PDF');
        }

        // Not only id 1: the draft version is a different file, labelled as a draft.
        $d = $this->actingAs($admin)->get(route('compliance.rmcp.pdf', $draft));
        $d->assertOk();
        self::assertStringContainsString('filename="rmcp-v2-ramsgate-realty-draft-', (string) $d->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', $d->getContent());
        if (($text = $this->pdfText($d->getContent())) !== null) {
            self::assertStringContainsString('Draft', $text);
        }
    }

    public function test_another_agencys_rmcp_cannot_be_downloaded_and_no_permission_is_a_403(): void
    {
        $theirs = $this->rmcp($this->agencyB, 1, 'active', '2026-04-21 10:00:00');
        $adminA = $this->makeUser($this->agencyA);

        $this->actingAs($adminA)->get(route('compliance.rmcp.pdf', $theirs))->assertNotFound();

        $mine = $this->rmcp($this->agencyA, 1, 'active', '2026-04-21 10:00:00');
        $printer = $this->partialMock(CompliancePdf::class);
        $printer->shouldReceive('render')->once()->andReturn('%PDF-1.4 test');

        // Staff may READ the RMCP (every agent holds access_rmcp), so they may download it: same rule as the screen.
        $agent = $this->makeUser($this->agencyA, 'agent');
        $ok = $this->actingAs($agent)->get(route('compliance.rmcp.pdf', $mine));
        $ok->assertOk();
        self::assertSame('application/pdf', $ok->headers->get('Content-Type'));

        // A user who holds no RMCP grant is stopped before anything is printed (render() is expected exactly once, above).
        $assistant = $this->makeUser($this->agencyA, 'agent');
        \DB::table('users')->where('id', $assistant->id)->update(['is_assistant' => 1]);
        $this->actingAs($assistant->fresh())->get(route('compliance.rmcp.pdf', $mine))->assertForbidden();
    }

    public function test_when_the_printer_fails_the_user_is_sent_back_with_a_message_never_the_html_page(): void
    {
        $admin = $this->makeUser($this->agencyA);
        $v     = $this->rmcp($this->agencyA, 1, 'active', '2026-04-21 10:00:00');

        $this->partialMock(CompliancePdf::class)->shouldReceive('render')->andReturn(null);

        $r = $this->actingAs($admin)->get(route('compliance.rmcp.pdf', $v));
        $r->assertRedirect(route('compliance.rmcp.show', $v));
        $r->assertSessionHas('error');
    }

    public function test_the_print_document_stands_alone(): void
    {
        $admin = $this->makeUser($this->agencyA);
        $v     = $this->rmcp($this->agencyA, 1, 'active', '2026-04-21 10:00:00')->load('sections', 'approver');
        $agency = \App\Models\Agency::findOrFail($this->agencyA);

        $html = app(CompliancePdf::class)->versionHtml('Financial Intelligence Centre Act 38 of 2001', null, $v, $agency, ['agency.name' => 'Ramsgate Realty'], 'RMCP');

        self::assertStringNotContainsString('var(--', $html, 'no app CSS variables — the printer has no app stylesheet');
        self::assertStringNotContainsString('<link', $html);
        self::assertStringNotContainsString('src="http', $html);
        self::assertStringNotContainsString('sidebar', strtolower($html));
        foreach (['Purpose and scope', 'Customer due diligence', 'Record keeping', 'Approved on:', '21 April 2026'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertSame('rmcp-v3-ramsgate-realty-approved-2026-04-21.pdf', CompliancePdf::fileName('rmcp', 3, 'Ramsgate Realty', '2026-04-21', 'active'));
    }

    public function test_the_policy_pdf_is_a_real_download_named_after_the_policy(): void
    {
        $this->requireChromiumPdf();
        // Another agency's policy first, before anyone is signed in (the model stamps the signed-in agency).
        $other  = $this->makePolicy($this->agencyB, 'comms', 'Communication Policy');
        $theirs = $this->makeVersion($other, 1, 'active');
        $admin   = $this->makeUser($this->agencyA);
        $policy  = $this->makePolicy($this->agencyA, 'comms', 'Communication Policy');
        $version = $this->makeVersion($policy, 2, 'active');

        $r = $this->actingAs($admin)->get(route('compliance.policy.pdf', $version));
        $r->assertOk();
        self::assertSame('application/pdf', $r->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename="communication-policy-v2-ramsgate-realty-', (string) $r->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', $r->getContent());
        if (($text = $this->pdfText($r->getContent())) !== null) {
            foreach (['Communication Policy', 'Version 2', 'Section 1', 'Body 1', 'Acknowledgement'] as $needle) {
                self::assertStringContainsString($needle, $text);
            }
            self::assertMatchesRegularExpression('/Page 1 of \d+/', $text);
        }

        $this->actingAs($admin)->get(route('compliance.policy.pdf', $theirs))->assertNotFound();
    }

    public function test_both_acknowledgement_registers_are_pdf_downloads(): void
    {
        $this->requireChromiumPdf();
        $admin = $this->makeUser($this->agencyA);
        $this->rmcp($this->agencyA, 1, 'active', '2026-04-21 10:00:00');
        $policy = $this->makePolicy($this->agencyA, 'comms', 'Communication Policy');
        $this->makeVersion($policy, 1, 'active');

        $rmcp = $this->actingAs($admin)->get(route('compliance.rmcp.dashboard.report'));
        $rmcp->assertOk();
        self::assertSame('application/pdf', $rmcp->headers->get('Content-Type'));
        self::assertStringContainsString('filename="rmcp-compliance-register-ramsgate-realty-', (string) $rmcp->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', $rmcp->getContent());
        if (($text = $this->pdfText($rmcp->getContent())) !== null) {
            self::assertStringContainsString('RMCP Compliance Register', $text);
            self::assertStringContainsString('Ramsgate Realty', $text);
        }

        $pol = $this->actingAs($admin)->get(route('compliance.policy.dashboard.report', ['policy' => 'comms']));
        $pol->assertOk();
        self::assertSame('application/pdf', $pol->headers->get('Content-Type'));
        self::assertStringContainsString('filename="comms-acknowledgement-register-ramsgate-realty-', (string) $pol->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', $pol->getContent());
        if (($text = $this->pdfText($pol->getContent())) !== null) {
            self::assertStringContainsString('Policy Compliance Register', $text);
            self::assertStringContainsString('Communication Policy', $text);
        }
    }
}
