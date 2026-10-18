<?php

declare(strict_types=1);

namespace Tests\Feature\Branding;

use App\Mail\Contracts\StaffFacingMail;
use App\Models\Agency;
use App\Services\PublicLinks\PublicLinkUnavailableResponder;
use App\Support\ClientBrand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Johan, 14 Oct 2026: anything a CLIENT (tenant, landlord, buyer, seller, applicant, contractor) sees carries the AGENCY's name, never
 * "CoreX" / "CoreX OS". Staff screens keep CoreX. There is no "Powered by CoreX" line and no agency setting for one.
 *
 * Two halves:
 *  1. RENDER - the client-facing pages and mails that can be rendered without a long fixture are rendered for a SECOND agency (not the
 *     agency whose name is in env/config) with `app.name` and `mail.from.name` both set to "CoreX OS", and must say nothing of CoreX.
 *     With no agency known they say a neutral label, never CoreX.
 *  2. SCAN - every client-facing view / mail / PDF source file is scanned (comments that never reach the browser are ignored) for the
 *     words CoreX / COREX / corexos and for `config('app.name')` / `mail.from.name` used as a fallback (both resolve to CoreX's own name).
 *     A new client-facing file under one of the listed folders is checked automatically.
 */
final class ClientFacingNeverSaysCoreXTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['app.name' => 'CoreX OS', 'mail.from.name' => 'CoreX OS']);
        $this->agency = Agency::create(['name' => 'Cape Town Rentals', 'slug' => 'ctr-' . uniqid(), 'email' => 'hello@ctr.test', 'phone' => '021 555 0100', 'address' => '9 Kloof Street']);
    }

    /** What a person reads: no styles, scripts or comments. Attributes (alt, title, content, href) stay in. */
    private function visible(string $html): string
    {
        $html = preg_replace('#<style\b.*?</style>#is', '', $html) ?? $html;
        $html = preg_replace('#<script\b.*?</script>#is', '', $html) ?? $html;

        $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;

        // link targets are not read (a staff mail may link to /corex/... paths); alt / title / content / visible text are
        return preg_replace('/\s(href|src|action|data-[a-z-]*url)=("[^"]*"|\'[^\']*\')/i', '', $html) ?? $html;
    }

    private function assertNoCoreX(string $html, string $what): void
    {
        $text = $this->visible($html);
        self::assertDoesNotMatchRegularExpression('/core\s*x|corexos/i', $text, "{$what} says CoreX to a client");
    }

    // ── 1. render ─────────────────────────────────────────────────────────────────────

    public function test_the_error_pages_and_maintenance_page_name_nobody_and_never_say_corex(): void
    {
        foreach (['errors.404-guest', 'errors.403-guest', 'errors.maintenance', 'errors.429', 'errors.404', 'errors.403'] as $view) {
            $html = view($view, ['meta' => []])->render();
            $this->assertNoCoreX($html, $view);
        }
        self::assertStringContainsString(ClientBrand::NEUTRAL, view('errors.404-guest')->render());
        self::assertStringNotContainsString('KZN South Coast', view('errors.maintenance', ['meta' => []])->render(), 'no one agency\'s region on a multi-agency page');
    }

    public function test_the_esign_gateway_and_consent_pages_carry_the_agency_and_no_powered_by_line(): void
    {
        $common = ['agencyName' => 'Cape Town Rentals', 'agencyLogo' => null, 'agencyColor' => '#123456', 'documentName' => 'Lease agreement', 'errors' => new \Illuminate\Support\ViewErrorBag()];
        $gateway = view('docuperfect.signatures.external.gateway', $common + ['message' => null, 'request' => (object) ['token' => 'tok', 'party_role' => 'tenant', 'signer_name' => 'Thandi', 'template' => null]])->render();
        $consent = view('docuperfect.signatures.external.consent', $common + ['idLastFour' => '1234', 'signerName' => 'Thandi', 'token' => 'tok'])->render();

        foreach ([$gateway, $consent] as $html) {
            $this->assertNoCoreX($html, 'e-sign page');
            self::assertStringContainsString('Cape Town Rentals', $html);
            self::assertStringContainsString('Secure document signing', $html);
        }
    }

    public function test_the_public_company_footer_and_dead_end_page_use_the_agency_else_a_neutral_label(): void
    {
        $with = view('public.shared._company-footer', ['agency' => $this->agency])->render();
        $without = view('public.shared._company-footer', ['agency' => null])->render();

        $this->assertNoCoreX($with, 'company footer');
        $this->assertNoCoreX($without, 'company footer (no agency)');
        self::assertStringContainsString('Cape Town Rentals', $with);
        self::assertStringContainsString(ClientBrand::NEUTRAL, $without);

        $dead = app(PublicLinkUnavailableResponder::class)->respond(null, 'Gone', 'This link is no longer active.', null, status: 410);
        $this->assertNoCoreX($dead->getContent(), 'dead-end page (no agency)');
        $deadAgency = app(PublicLinkUnavailableResponder::class)->respond($this->agency->id, 'Gone', 'This link is no longer active.', null, status: 410);
        $this->assertNoCoreX($deadAgency->getContent(), 'dead-end page (agency)');
        self::assertStringContainsString('Cape Town Rentals', $deadAgency->getContent());
    }

    public function test_the_otp_mail_is_the_agencys_own_or_neutral(): void
    {
        $with = (new \App\Mail\OtpMail('123456', 10, 'Your verification code', null, $this->agency))->render();
        $without = (new \App\Mail\OtpMail('123456', 10))->render();

        $this->assertNoCoreX($with, 'OTP mail (agency)');
        $this->assertNoCoreX($without, 'OTP mail (no agency)');
        self::assertStringContainsString('Cape Town Rentals', $with);
        self::assertStringContainsString(ClientBrand::NEUTRAL, $without);

        $envelope = (new \App\Mail\OtpMail('123456', 10))->envelope();
        self::assertSame(ClientBrand::NEUTRAL, $envelope->from->name);
        self::assertStringNotContainsStringIgnoringCase('corex', (string) $envelope->subject);
    }

    public function test_the_presentation_mail_and_the_matches_digest_footers(): void
    {
        $presentation = view('emails.presentations.send-presentation', [
            'agencyName' => 'Cape Town Rentals', 'agentName' => 'Pat Agent', 'bodyText' => 'Here is your analysis.', 'agencyDisclaimer' => null,
            'subject' => 'Your analysis', 'viewUrl' => 'https://example.test/p/1',
        ])->render();
        $anonymous = view('emails.presentations.send-presentation', ['agencyName' => null, 'agentName' => null, 'bodyText' => 'Hi', 'agencyDisclaimer' => null, 'subject' => 's', 'viewUrl' => 'https://example.test/p/1'])->render();
        $digest = view('emails.matches.digest', ['dateLine' => '14 Oct', 'greeting' => 'Pat', 'matchCount' => 1, 'contactCount' => 1, 'groups' => []])->render();

        $this->assertNoCoreX($presentation, 'presentation mail');
        $this->assertNoCoreX($anonymous, 'presentation mail (no agency)');
        $this->assertNoCoreX($digest, 'matches digest');
        self::assertStringContainsString('Cape Town Rentals', $presentation);
        self::assertStringContainsString(ClientBrand::NEUTRAL, $anonymous);
    }

    public function test_the_seller_info_mails_and_the_certificate_of_compliance_request(): void
    {
        foreach ([1, 2, 3] as $tier) {
            $html = view("emails.compliance.seller-info.tier{$tier}", ['agency' => $this->agency, 'sellerName' => 'Sam Seller'])->render();
            $this->assertNoCoreX($html, "seller-info tier {$tier}");
        }

        $coc = view('documents.coc-request', [
            'agencyName' => 'Cape Town Rentals', 'agentName' => 'Pat', 'agentEmail' => 'pat@ctr.test', 'agentPhone' => '021', 'agentDesignation' => 'Agent', 'agentFfc' => 'F1',
            'deal' => (object) ['reference' => 'D-1'], 'property' => (object) ['address' => '1 Test Rd', 'erf_number' => '12', 'suburb' => 'Sea Point'],
            'generatedAt' => now(), 'ownerNames' => 'Owner', 'providerCompany' => 'Spark', 'providerEmail' => 's@x.test', 'providerName' => 'Sparky', 'specialtyLabel' => 'Electrical',
        ])->render();
        $this->assertNoCoreX($coc, 'certificate of compliance request');
        self::assertStringContainsString('Cape Town Rentals', $coc);
    }

    public function test_the_sales_document_return_pages_use_the_senders_agency(): void
    {
        $send = (object) ['agency_id' => $this->agency->id, 'document' => null, 'sender' => null];
        $recipient = (object) ['token' => 't', 'name' => 'Client', 'id_number' => '1', 'email' => 'c@x.test'];
        foreach (['expired', 'already-returned'] as $v) {
            $html = view("sales-documents.{$v}", ['send' => $send, 'recipient' => $recipient])->render();
            $this->assertNoCoreX($html, "sales-documents.{$v}");
            self::assertStringContainsString('Cape Town Rentals', $html);
        }
        $none = view('sales-documents.expired', [])->render();
        $this->assertNoCoreX($none, 'sales-documents.expired (no agency)');
        self::assertStringContainsString(ClientBrand::NEUTRAL, $none);
    }

    public function test_client_brand_never_falls_back_to_the_configured_corex_names(): void
    {
        self::assertSame('Cape Town Rentals', ClientBrand::name($this->agency));
        self::assertSame(ClientBrand::NEUTRAL, ClientBrand::name(null));
        self::assertSame(ClientBrand::NEUTRAL_RENTALS, ClientBrand::name(null, ClientBrand::NEUTRAL_RENTALS));
        self::assertSame('Cape Town Rentals', ClientBrand::nameForId($this->agency->id));
        self::assertSame(ClientBrand::NEUTRAL, ClientBrand::nameForId(null));
        self::assertStringNotContainsStringIgnoringCase('corex', ClientBrand::NEUTRAL . ClientBrand::NEUTRAL_RENTALS);
    }

    // ── 2. scan ───────────────────────────────────────────────────────────────────────

    /** Client-facing source files (globs under the repo root). Staff screens, CoreX-to-agency mail and demo pages are deliberately NOT here. */
    private const CLIENT_FACING = [
        'resources/views/errors/*.blade.php',
        'resources/views/docuperfect/signatures/external/*.blade.php',
        'resources/views/public/auctions/*.blade.php',
        'resources/views/public/agency-properties/*.blade.php',
        'resources/views/public/shared/*.blade.php',
        'resources/views/layouts/public.blade.php',
        'resources/views/seller-outreach/{landing,opt-out,unsubscribe,_landing-active,_landing-generic}.blade.php',
        'resources/views/seller-link/{live,revoked,unavailable}.blade.php',
        'resources/views/buyer-portal/{show,revoked,_property-card}.blade.php',
        'resources/views/shared/*.blade.php',
        'resources/views/rentals/portal/*.blade.php',
        'resources/views/rentals/secure-link/*.blade.php',
        'resources/views/rentals/crew-link/*.blade.php',
        'resources/views/rental-applications/public/*.blade.php',
        'resources/views/rental-inspections/public/*.blade.php',
        'resources/views/rental-inspections/public/partials/*.blade.php',
        'resources/views/rental-inventories/public/*.blade.php',
        'resources/views/fica/*.blade.php',
        'resources/views/sales-documents/*.blade.php',
        'resources/views/documents/*.blade.php',
        'resources/views/tv/*.blade.php',
        'resources/views/title-deeds/*.blade.php',
        'resources/views/presentations/seller-live.blade.php',
        'resources/views/corex/properties/live-preview.blade.php',
        'resources/views/corex/agents/live-preview.blade.php',
        'resources/views/compliance/fica/{pdf,completion-report}.blade.php',
        'resources/views/tools/evaluation-certificate/pdf.blade.php',
        'resources/views/proforma/pdf.blade.php',
        'resources/views/deposit-interest-calculator/pdf.blade.php',
        'resources/views/emails/{signatures,sales,title-deeds,distribution,seller-outreach,presentations,matches,otp,client-auth,fica-request,proforma,deals,partials,rentals}/{,*/}*.blade.php',
        'resources/views/emails/{fica-request,otp,auction-reminder,rental-application-*}.blade.php',
        'resources/views/emails/compliance/seller-info/*.blade.php',
        'app/Mail/{Signatures,Rentals,Presentations,Matches}/*.php',
        'app/Mail/{OtpMail,ClientAuthOtpMail,FicaRequestMail,RentalApplication*}.php',
        'app/Services/Rentals/RentalDocumentPdfService.php',
        'app/Services/ViewingPack/{*.php,Concerns/*.php}',
        'app/Services/Properties/PropertyBrochureService.php',
        'app/Services/Presentations/{PresentationDeliveryService,PresentationPdfService}.php',
        'app/Services/Matching/CoreMatchListPdfService.php',
        'app/Services/DealV2/CocRequestGenerator.php',
        'app/Services/CommercialEvaluation/CommercialEvaluationPdfService.php',
        'app/Services/PublicLinks/PublicLinkUnavailableResponder.php',
        'app/Http/Controllers/{RentalFaultSettingsDisputeController,RentalOwnerNoticeController}.php',
    ];

    /**
     * The staff-facing exemption, by RULE: a mail whose recipient is a staff user (an agent, a manager, an admin) reads CoreX screens, so it
     * may say CoreX. A mailable declares that by implementing App\Mail\Contracts\StaffFacingMail; its Blade view(s) and its own class file
     * are then not client-facing. There is no list of file names here - a new staff mail implements the interface, a new client mail does not
     * and is scanned strictly by the registry below.
     *
     * @return array<string,string> absolute path => absolute path
     */
    private function staffFacingFiles(): array
    {
        $out = [];
        $root = base_path('app/Mail') . '/';
        foreach (glob($root . '{,*/,*/*/}*.php', GLOB_BRACE) ?: [] as $file) {
            $class = 'App\\Mail\\' . str_replace(['/', '.php'], ['\\', ''], substr($file, strlen($root)));
            if (! class_exists($class) || ! is_subclass_of($class, StaffFacingMail::class)) {
                continue;
            }
            $out[$file] = $file;
            preg_match_all('/\b(?:view|markdown|text)\s*(?::|\()\s*[\'"]([\w.\-]+)[\'"]/', (string) file_get_contents($file), $m);
            foreach ($m[1] as $view) {
                $viewFile = base_path('resources/views/' . str_replace('.', '/', $view) . '.blade.php');
                $out[$viewFile] = $viewFile;
            }
        }

        return $out;
    }

    /** @return array<int,string> */
    private function clientFiles(): array
    {
        $files = [];
        foreach (self::CLIENT_FACING as $glob) {
            foreach (glob(base_path($glob), GLOB_BRACE) ?: [] as $f) {
                $files[$f] = $f;
            }
        }

        return array_values(array_diff_key($files, $this->staffFacingFiles()));
    }

    /** Source with the parts that never reach a client removed: Blade comments, PHP comments, and code identifiers that happen to contain the word. */
    private function stripNonDisplay(string $src, string $path): string
    {
        $src = preg_replace('/\{\{--.*?--\}\}/s', '', $src) ?? $src;
        if (str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
            $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
        }
        $src = preg_replace('#^\s*(//|\*|\#).*$#m', '', $src) ?? $src;
        $src = preg_replace('#(?<=\s)//\s.*$#m', '', $src) ?? $src;   // trailing "// comment"
        // identifiers / namespaces / route names / JS globals: window.CoreXDisclosure, CoreXAgentEdit, App\Http\Controllers\CoreX\..., corex.route.names
        $src = preg_replace('/\bCoreX[A-Za-z_]+|\\\\CoreX\\\\|\bCorex[A-Z][A-Za-z]+|window\.CoreX\b|\bcorex[.\-_\/][A-Za-z0-9_.\/-]*|[\'"]corex[\'"]|layouts\.corex[\w.-]*|\bcorex\.[A-Za-z.]+/', '', $src) ?? $src;
        $src = preg_replace('/@corexos\.co\.za/i', '', $src) ?? $src;   // the OTP sender mailbox, infrastructure not branding

        return $src;
    }

    public function test_no_client_facing_source_file_says_corex_or_falls_back_to_a_corex_name(): void
    {
        $files = $this->clientFiles();
        self::assertGreaterThan(150, count($files), 'the client-facing registry must actually cover the client-facing files');

        $violations = [];
        foreach ($files as $file) {
            $rel = str_replace(base_path() . '/', '', $file);
            $src = $this->stripNonDisplay((string) file_get_contents($file), $file);
            foreach (preg_split('/\R/', $src) as $i => $line) {
                if (preg_match('/core\s*x\b|corexos|\bCOREX\b/i', $line)) {
                    $violations[] = "{$rel}: " . trim(mb_substr($line, 0, 120));
                }
                if (preg_match('/config\(\s*[\'"](app\.name|mail\.from\.name)[\'"]/', $line)) {
                    $violations[] = "{$rel}: uses a CoreX name as a client-facing fallback - " . trim(mb_substr($line, 0, 100));
                }
            }
        }

        self::assertSame([], $violations, "client-facing files that still say CoreX:\n" . implode("\n", array_slice($violations, 0, 40)));
    }

    public function test_only_mails_to_staff_are_exempt_and_the_client_version_of_the_inspection_mail_stays_strict(): void
    {
        // The exemption is derived from the recipient (the interface), so it is not empty, every exempt view exists, and it covers the agent's copy.
        $exempt = $this->staffFacingFiles();
        self::assertNotEmpty($exempt);
        foreach ($exempt as $file) {
            self::assertFileExists($file, 'a staff-facing mail names a view that does not exist - the exemption would silently cover nothing');
        }
        self::assertContains(base_path('resources/views/emails/rentals/inspection-agent-copy.blade.php'), $exempt);
        self::assertNotContains(base_path('resources/views/emails/rentals/inspection-signing-link.blade.php'), $exempt, 'the tenant/owner mail is a client mail');

        // A client's signing-link mail is scanned strictly and renders clean; the agent's own copy is the one that says CoreX.
        $args = ['recipientName' => 'Thandi', 'propertyAddress' => '1 Kloof St', 'inspectionLabel' => 'Move-in inspection', 'signingUrl' => 'https://example.test/s/1', 'expiresOn' => '30 Oct 2026', 'canSign' => true, 'agentName' => 'Pat'];
        $client = new \App\Mail\Rentals\RentalInspectionSigningLinkMail(...$args);
        $this->assertNoCoreX($client->render(), 'inspection signing-link mail (client)');
        self::assertNotInstanceOf(StaffFacingMail::class, $client);

        $agent = new \App\Mail\Rentals\RentalInspectionAgentCopyMail(...array_merge($args, ['canSign' => false, 'openInCoreXUrl' => 'https://example.test/i/1']));
        self::assertInstanceOf(StaffFacingMail::class, $agent);
        self::assertMatchesRegularExpression('/core\s*x/i', $this->visible($agent->render()));
    }

    public function test_there_is_no_powered_by_corex_setting_to_switch_on(): void
    {
        self::assertSame([], glob(base_path('database/migrations/*powered_by*')) ?: [], 'a "Powered by" agency setting does not exist; none is added without Johan');
        self::assertStringNotContainsString('powered_by', (string) file_get_contents(base_path('database/schema/mysql-schema.sql')));
    }
}
