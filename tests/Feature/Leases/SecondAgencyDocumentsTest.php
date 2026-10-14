<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Mail\OtpMail;
use App\Mail\RentalApplicationInviteMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use App\Support\Docuperfect\DocumentAgencyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Multi-agency, always: a SECOND agency's documents, letterhead, mail and portal header say THAT agency — never Home
 * Finders Coastal. The shared letterhead used to fall back to "the agency whose slug is hfc-coastal" whenever nobody was
 * signed in (every public signing page, every portal / queue / PDF render), and the shipped lease / mandate / rental
 * application templates printed Home Finders' name, e-mail and phone as literal text.
 *
 * A real HFC row (slug hfc-coastal, with a logo and contact details) exists in every test — it is the decoy that proves
 * nothing falls back to it.
 */
final class SecondAgencyDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $hfc;
    private Agency $cape;
    private Branch $capeBranch;
    private User $capeAdmin;

    /** What must never appear on a second agency's documents. */
    private const HFC_MARKERS = ['Home Finders', 'HOME FINDERS', 'hfcoastal', 'Johan and Elize', '039 315 0857', 'logos/hfc-logo.png', 'Shelly Beach'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Storage::fake('public');
        DocumentAgencyContext::flush();

        Storage::disk('public')->put('logos/hfc-logo.png', 'png');
        Storage::disk('public')->put('logos/cape-logo.png', 'png');

        $this->hfc = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-coastal']);
        $this->hfc->forceFill([
            'trading_name' => 'Johan and Elize Properties T/A', 'address' => 'Shop 5, Shelly Beach', 'phone' => '039 315 0857',
            'email' => 'admin@hfcoastal.co.za', 'reg_no' => '2017/431318/07', 'vat_no' => '4630287821', 'logo_path' => 'logos/hfc-logo.png',
            'website_url' => 'https://hfcoastal.co.za',
        ])->save();

        $this->cape = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-rentals-' . uniqid()]);
        $this->cape->forceFill([
            'trading_name' => 'Cape Rentals (Pty) Ltd', 'address' => '12 Long Street, Cape Town', 'phone' => '021 555 0100',
            'email' => 'hello@caperentals.test', 'reg_no' => 'CPT/2020/778899', 'vat_no' => '4990011223', 'logo_path' => 'logos/cape-logo.png',
            'website_url' => 'https://caperentals.test',
        ])->save();
        $this->capeBranch = Branch::create(['agency_id' => $this->cape->id, 'name' => 'Main']);
        $this->capeAdmin = User::factory()->create(['agency_id' => $this->cape->id, 'branch_id' => null, 'role' => 'admin']);
    }

    protected function tearDown(): void
    {
        DocumentAgencyContext::flush();
        parent::tearDown();
    }

    private function header(array $data = []): string
    {
        return view('docuperfect.web-templates.components.company-header', $data)->render();
    }

    private function noHfc(string $html, string $where): void
    {
        foreach (self::HFC_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $html, "{$where} shows '{$marker}' — Home Finders leaked onto another agency's document");
        }
    }

    // ── the shared letterhead ────────────────────────────────────────────────────────────────────

    public function test_with_nobody_to_name_the_letterhead_prints_nothing_never_home_finders(): void
    {
        $this->assertSame('', trim($this->header()));
    }

    public function test_the_letterhead_of_a_document_is_that_documents_agency_with_its_logo_and_contact_details(): void
    {
        $html = DocumentAgencyContext::with($this->cape->id, null, fn () => $this->header());

        foreach (['Cape Rentals (Pty) Ltd', '12 Long Street, Cape Town', 'hello@caperentals.test', '021 555 0100', 'CPT/2020/778899', '4990011223', 'logos/cape-logo.png'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
        $this->noHfc($html, 'letterhead');
    }

    public function test_a_signed_in_users_own_agency_is_the_letterhead_when_they_have_no_branch(): void
    {
        $this->actingAs($this->capeAdmin);
        $html = $this->header();

        $this->assertStringContainsString('Cape Rentals (Pty) Ltd', $html);
        $this->noHfc($html, 'letterhead of a branch-less user');
    }

    public function test_the_documents_agency_wins_over_the_viewers_own(): void
    {
        $hfcUser = User::factory()->create(['agency_id' => $this->hfc->id, 'role' => 'admin']);
        $this->actingAs($hfcUser);

        $html = DocumentAgencyContext::with($this->cape->id, null, fn () => $this->header());

        $this->assertStringContainsString('Cape Rentals (Pty) Ltd', $html);
        $this->noHfc($html, "letterhead of Cape's document opened by an HFC user");
    }

    public function test_a_branchs_own_details_override_and_a_branch_of_another_agency_never_decorates(): void
    {
        $this->capeBranch->forceFill(['phone' => '021 999 0000', 'address' => 'Branch Rd, Claremont'])->save();
        $hfcBranch = Branch::create(['agency_id' => $this->hfc->id, 'name' => 'Shelly', 'phone' => '039 000 1111']);

        $own = DocumentAgencyContext::with($this->cape->id, $this->capeBranch->id, fn () => $this->header());
        $this->assertStringContainsString('021 999 0000', $own);
        $this->assertStringContainsString('Branch Rd, Claremont', $own);

        $foreign = DocumentAgencyContext::with($this->cape->id, $hfcBranch->id, fn () => $this->header());
        $this->assertStringContainsString('021 555 0100', $foreign);
        $this->assertStringNotContainsString('039 000 1111', $foreign);
    }

    // ── the documents themselves ────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string}> */
    public static function agencyWordingTemplates(): array
    {
        return [
            'lease agreement' => ['lease-agreement-popi-v8'],
            'commercial lease' => ['commercial-lease-agreement-v5'],
            'marketing permission (sales)' => ['marketing-permission-v6'],
            'marketing permission (letting)' => ['letting-marketing-permission-v7'],
            'letting mandate' => ['letting-mandate-v5'],
            'letting mandatory disclosure' => ['letting-mandatory-disclosure-v7'],
            'rental application' => ['rental-application-v8'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('agencyWordingTemplates')]
    public function test_every_shipped_template_says_the_documents_own_agency(string $template): void
    {
        $html = DocumentAgencyContext::with($this->cape->id, null, fn () => view('docuperfect.web-templates.' . $template, [])->render());

        $this->assertStringContainsString('Cape Rentals', $html, "{$template} does not name the agency");
        $this->noHfc($html, $template);
    }

    public function test_the_lease_names_the_agency_as_the_agent_in_its_clauses(): void
    {
        $html = DocumentAgencyContext::with($this->cape->id, null, fn () => view('docuperfect.web-templates.lease-agreement-popi-v8', [])->render());
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

        $this->assertStringContainsString('2.3 The Estate Agent being: The firm: Cape Rentals', $text);
        $this->assertStringContainsString('Cape Rentals is the Agent, not the Landlord', $text);
    }

    public function test_the_rental_application_directs_applicants_to_the_agencys_own_email_phone_and_website(): void
    {
        $html = DocumentAgencyContext::with($this->cape->id, null, fn () => view('docuperfect.web-templates.rental-application-v8', [])->render());
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)));

        $this->assertStringContainsString('TO: hello@caperentals.test', $text);
        $this->assertStringContainsString('on: 021 555 0100 or email: hello@caperentals.test', $text);
        $this->assertStringContainsString('https://caperentals.test', $text);
        $this->assertStringContainsString('The Cape Rentals Rental Team', $text);
    }

    public function test_the_baked_letterhead_in_a_stored_document_is_refreshed_to_the_documents_own_agency(): void
    {
        // A snapshot taken while HFC's context was in force carries HFC's letterhead...
        $baked = DocumentAgencyContext::with($this->hfc->id, null, fn () => $this->header());
        $this->assertStringContainsString('Shelly Beach', $baked);
        $this->assertStringContainsString('logos/hfc-logo.png', $baked);

        // ...and serving it for Cape's document (nobody signed in) must swap in Cape's, not HFC's.
        $served = \App\Services\Docuperfect\LetterheadRefresher::refresh('<div>' . $baked . '<p>body</p></div>', $this->cape->id, null);

        $this->assertStringContainsString('Cape Rentals (Pty) Ltd', $served);
        $this->assertStringContainsString('logos/cape-logo.png', $served);
        $this->noHfc($served, 'refreshed signing page');
    }

    // ── mail + portal header ─────────────────────────────────────────────────────────────────────

    private function application(): RentalApplication
    {
        $contact = Contact::create(['agency_id' => $this->cape->id, 'branch_id' => $this->capeBranch->id, 'first_name' => 'Thandi', 'last_name' => 'Ngcobo', 'email' => 'thandi@example.test']);

        return RentalApplication::create([
            'agency_id' => $this->cape->id, 'branch_id' => $this->capeBranch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->capeAdmin->id, 'status' => 'draft', 'current_generation' => 1,
            'token' => 'tok-' . uniqid(), 'token_expires_at' => now()->addDays(14),
        ]);
    }

    public function test_the_application_and_verification_mails_are_the_second_agencys_own(): void
    {
        $inviteMail = new RentalApplicationInviteMail($this->application()->load(['contact', 'agency']));
        $invite = $inviteMail->render();
        $this->assertStringContainsString('Cape Rentals', (string) $inviteMail->envelope()->subject);
        $this->noHfc($invite . $inviteMail->envelope()->subject, 'rental application invite mail');

        $otp = (new OtpMail('481516', 10, 'Verify it is you', null, $this->cape))->render();
        $this->assertStringContainsString('Cape Rentals', $otp);
        $this->assertStringContainsString('hello@caperentals.test', $otp);
        $this->noHfc($otp, 'verification-code mail');
    }

    public function test_the_portal_header_is_the_second_agencys_name_and_logo(): void
    {
        $b = Agency::publicBrandingFor($this->cape->id);

        $this->assertSame('Cape Rentals', $b['name']);
        $this->assertStringContainsString('logos/cape-logo.png', (string) $b['logoUrl']);
        $this->noHfc(json_encode($b), 'portal header');
    }

    // ── the guard: nothing shipped names an agency as literal text ──────────────────────────────

    public function test_no_shipped_document_template_or_letterhead_component_hardcodes_an_agency(): void
    {
        $dir = resource_path('views/docuperfect/web-templates');
        $files = array_merge(
            glob($dir . '/*.blade.php') ?: [],
            [resource_path('views/components/corex-document.blade.php'), resource_path('views/tv/company.blade.php')]
        );
        $this->assertGreaterThan(8, count($files));

        $bad = [];
        foreach ($files as $file) {
            $src = (string) file_get_contents($file);
            // comments are fine; printed text and lookups are not
            $src = preg_replace('/\{\{--.*?--\}\}|<!--.*?-->/s', '', $src);
            if (preg_match('/hfc-coastal|hfcoastal|home\s+finders/i', $src, $m)) {
                $bad[] = basename($file) . " ('{$m[0]}')";
            }
        }

        $this->assertSame([], $bad, 'These shipped files still hardcode Home Finders: ' . implode(', ', $bad));
    }
}
