<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Mail\Rentals\RentalLandlordDecisionNeededMail;
use App\Mail\Rentals\RentalOwnerQuoteMail;
use App\Mail\Rentals\RentalOwnerVariationMail;
use App\Mail\Rentals\RentalTenantCompletionCheckMail;
use App\Mail\Rentals\RentalTenantStatusChangeMail;
use App\Mail\Rentals\RentalWorkOrderAppointmentMail;
use App\Models\Contact;
use App\Models\LeaseTenant;
use App\Models\RentalFaultReport;
use App\Models\RentalInspection;
use App\Models\RentalInspectionNotification;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderVariation;
use App\Services\Rentals\RentalInspectionNotificationService;
use App\Services\Rentals\RentalPortalAccessService;
use App\Services\Rentals\RentalPortalScopeService;
use App\Support\PortalLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * 8 Oct 2026 - "Email addressed to Ayanda (tenant), clicking the link opens and shows Signed in as Siyabonga (owner)". Every rental mail
 * built `url('/portal')` with no recipient on it, so the link opened whoever was already signed in on that device. Now ONE helper
 * (App\Support\PortalLink) builds every portal link: a signed recipient reference, the side (tenant / owner) the mail is written for,
 * and the record it is about. Proven here: (1) no Mailable / Notification / rentals service builds a portal URL any other way; (2) the
 * helper's reference is signed and a tampered one is ignored; (3) each rentals mail that links into the portal is built, its link is
 * read back, and it resolves - to THIS recipient, in the RIGHT side, onto a record that recipient may open; (4) one person who is both
 * tenant and owner gets a link for each side.
 */
final class PortalMailLinkTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
    }

    /** @return array<string, string> */
    private function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

        return $q;
    }

    private function sendableEmail(Contact $c): string
    {
        return strtolower((string) $c->email);
    }

    // ── 1. nothing builds a portal URL by hand ───────────────────────────────

    public function test_no_mail_notification_or_rentals_service_builds_a_portal_url_except_through_the_helper(): void
    {
        $dirs = ['app/Mail', 'app/Notifications', 'app/Services/Rentals', 'app/Jobs/Rentals', 'app/Listeners', 'app/Http/Controllers/CoreX', 'resources/views/emails'];
        $offenders = [];
        foreach ($dirs as $dir) {
            $root = base_path($dir);
            if (! is_dir($root)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! preg_match('/\.php$/', $file->getFilename())) {
                    continue;
                }
                foreach (file($file->getPathname()) as $n => $line) {
                    $trim = ltrim($line);
                    if (str_starts_with($trim, '*') || str_starts_with($trim, '//') || str_starts_with($trim, '/*') || str_starts_with($trim, '{{--')) {
                        continue;
                    }
                    // url('/portal'), route('portal...'), asset('/portal'), "/portal?..." - any hand-written way into the tenant/owner portal
                    if (preg_match('~(url|asset)\(\s*[\'"]/?portal[\'"/?)]|[\'"]/portal[\'"?/]|/portal\?~', $line)) {
                        $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($n + 1) . '  ' . trim($line);
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "A portal link must be built by App\\Support\\PortalLink - these are not:\n" . implode("\n", $offenders));
    }

    // ── 2. the helper ────────────────────────────────────────────────────────

    public function test_the_recipient_reference_is_signed_carries_no_readable_address_and_a_tampered_one_is_ignored(): void
    {
        $url = PortalLink::forContact($this->tenant, PortalLink::VIEW_TENANT, ['fault' => 12, 'junk' => 5]);
        $q = $this->query($url);

        $this->assertSame('tenant', $q['as']);
        $this->assertSame('12', $q['fault']);
        $this->assertArrayNotHasKey('junk', $q, 'only known targets are written');
        $this->assertArrayNotHasKey('email', $q);
        $this->assertStringNotContainsString(rawurlencode($this->tenant->email), $url);
        $this->assertStringNotContainsString($this->tenant->email, $url, 'no readable address in the URL');
        $this->assertSame(['contact_id' => $this->tenant->id, 'email' => $this->sendableEmail($this->tenant)], PortalLink::parse($q['r']));

        [$payload, $sig] = explode('.', $q['r']);
        $forged = rtrim(strtr(base64_encode(json_encode(['c' => $this->tenant->id, 'e' => 'someone.else@example.invalid'])), '+/', '-_'), '=');
        foreach ([$forged . '.' . $sig, $payload . '.' . strrev($sig), $payload, '', 'a.b.c', str_repeat('x', 700)] as $bad) {
            $this->assertNull(PortalLink::parse($bad), 'a forged or malformed reference is never trusted');
        }
        $this->assertNull(PortalLink::parse(null));
    }

    public function test_the_page_prefills_the_signed_recipient_and_ignores_a_forged_one(): void
    {
        $q = $this->query(PortalLink::forContact($this->landlord, PortalLink::VIEW_OWNER, ['wo' => 3]));

        $this->get('/portal?' . http_build_query($q))->assertOk()->assertViewHas('linkedEmail', $this->sendableEmail($this->landlord));
        $this->get('/portal?r=' . rawurlencode(strrev($q['r'])) . '&as=owner')->assertOk()->assertViewHas('linkedEmail', null);
        $this->get('/portal?email=' . rawurlencode(strtoupper($this->tenant->email)))->assertOk()->assertViewHas('linkedEmail', $this->sendableEmail($this->tenant));   // the lease screen's copy link still works
        $this->get('/portal')->assertOk()->assertViewHas('linkedEmail', null);
    }

    // ── 3. each rentals mail that links into the portal ──────────────────────

    /** @return array<string, array{0:string, 1:string, 2:string, 3:string}> label => [url, recipient email, view, target key] */
    private function everyMailLink(): array
    {
        $fault = $this->faultReport(['owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now()]);
        $wo = $this->externalJob();
        $quote = $wo->quotes()->create(['agency_id' => $this->agency->id, 'amount' => 900, 'quote_date' => now()->toDateString(), 'is_selected' => true, 'detail_text' => 'x']);
        $variation = new RentalWorkOrderVariation(['baseline_amount' => 900, 'extra_amount' => 100, 'new_total' => 1000, 'term_text' => '']);
        $round = new RentalWorkCompletionRound();
        $viewData = function (object $mail): array {
            $m = new \ReflectionMethod($mail, 'viewData');
            $m->setAccessible(true);

            return $m->invoke($mail);
        };

        return [
            'owner: decision needed on a fault' => [(new RentalLandlordDecisionNeededMail($fault, 'Pieter', $this->landlord->email))->portalUrl, $this->landlord->email, 'owner', 'fault:' . $fault->id],
            'owner: decision needed on a work order' => [(new RentalLandlordDecisionNeededMail($wo, 'Pieter', $this->landlord->email))->portalUrl, $this->landlord->email, 'owner', 'wo:' . $wo->id],
            'owner: quote' => [$viewData(new RentalOwnerQuoteMail($wo, $quote, 'Pieter', true, null, null, '', null, $this->landlord))['portalUrl'], $this->landlord->email, 'owner', 'wo:' . $wo->id],
            'owner: extra work' => [$viewData(new RentalOwnerVariationMail($variation, $wo, 'Pieter', null, null, false, null, $this->landlord))['portalUrl'], $this->landlord->email, 'owner', 'wo:' . $wo->id],
            'tenant: fault status change' => [(new RentalTenantStatusChangeMail($fault, 'Thandi', $this->tenant))->portalUrl, $this->tenant->email, 'tenant', 'fault:' . $fault->id],
            'tenant: appointment' => [(new RentalWorkOrderAppointmentMail($wo, 'Thandi', false, $this->tenant))->portalUrl, $this->tenant->email, 'tenant', 'wo:' . $wo->id],
            'tenant: check the finished work' => [(new RentalTenantCompletionCheckMail($round, $wo, 'Thandi', 'https://example.invalid/check', null, $this->tenant))->content()->with['portalUrl'], $this->tenant->email, 'tenant', 'wo:' . $wo->id],
        ];
    }

    public function test_each_mail_that_links_into_the_portal_names_who_it_is_for_the_side_and_the_record(): void
    {
        foreach ($this->everyMailLink() as $label => [$url, $email, $view, $target]) {
            $q = $this->query($url);
            [$key, $id] = explode(':', $target);

            $this->assertSame(strtolower($email), PortalLink::parse($q['r'] ?? null)['email'] ?? null, "{$label}: the link names its recipient");
            $this->assertSame($view, $q['as'] ?? null, "{$label}: the side the mail is written for");
            $this->assertSame($id, $q[$key] ?? null, "{$label}: the record it is about");
            $this->assertStringStartsWith(url('/portal') . '?', $url);

            // it resolves: the page opens for exactly that person
            $this->get($url)->assertOk()->assertViewHas('linkedEmail', strtolower($email));
        }
    }

    public function test_every_target_in_those_links_is_a_record_the_recipient_may_open_and_somebody_else_may_not(): void
    {
        $scope = app(RentalPortalScopeService::class);
        $fault = $this->faultReport(['owner_approval_status' => RentalFaultReport::APPROVAL_PENDING, 'sent_to_owner_at' => now()]);
        $wo = $this->externalJob();
        $stranger = $this->makeContact($this->agency);

        $this->assertNotNull($scope->landlordFaultReport($this->landlord, $fault->id), 'owner link -> fault');
        $this->assertNull($scope->landlordFaultReport($stranger, $fault->id));
        $this->assertNotNull($scope->tenantFaultReport($this->tenant, $fault->id), 'tenant link -> fault');
        $this->assertNull($scope->tenantFaultReport($stranger, $fault->id));
        $this->assertNotNull($scope->landlordWorkOrder($this->landlord, $wo->id), 'owner link -> work order');
        $this->assertNull($scope->landlordWorkOrder($this->tenant, $wo->id), 'a tenant is not shown the owner side of a work order');
        $this->assertNotNull($scope->tenantWorkOrder($this->tenant, $wo->id), 'tenant link -> work order');
        $this->assertNull($scope->tenantWorkOrder($this->landlord, $wo->id), 'an owner is not shown the tenant side of a work order');
    }

    public function test_inspection_mails_link_each_party_to_their_own_side_and_the_inspection(): void
    {
        $inspection = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id,
            'type' => RentalInspection::TYPE_IN, 'created_by_user_id' => $this->admin->id,
            'status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()->subDay(),
        ]);
        $svc = app(RentalInspectionNotificationService::class);

        foreach ([[RentalInspectionNotification::PARTY_TENANT, $this->tenant, 'tenant'], [RentalInspectionNotification::PARTY_LANDLORD, $this->landlord, 'owner']] as [$role, $contact, $view]) {
            $url = $svc->linkFor(['role' => $role, 'name' => 'x', 'email' => $contact->email], $inspection);
            $q = $this->query($url);
            $this->assertSame($view, $q['as']);
            $this->assertSame((string) $inspection->id, $q['insp']);
            $this->assertSame(strtolower($contact->email), PortalLink::parse($q['r'])['email']);
            $this->get($url)->assertOk()->assertViewHas('linkedEmail', strtolower($contact->email));
        }
        $this->assertStringContainsString('/corex/rental-inspections/', $svc->linkFor(['role' => RentalInspectionNotification::PARTY_INSPECTOR, 'name' => 'x', 'email' => 'staff@example.invalid'], $inspection), 'the inspector is staff: the office screen');

        $scope = app(RentalPortalScopeService::class);
        $this->assertTrue($scope->tenantInspections($this->tenant)->contains('id', $inspection->id));
        $this->assertTrue($scope->landlordInspections($this->landlord)->contains('id', $inspection->id));
    }

    public function test_the_portal_invite_and_the_lease_copy_link_carry_the_person_and_the_side(): void
    {
        $access = app(RentalPortalAccessService::class);
        $this->agency->forceFill([])->save();

        // the lease screen's copy link: the readable personal link, plus the side
        $copy = $this->query($access->portalUrl($this->landlord->email, PortalLink::VIEW_OWNER));
        $this->assertSame(strtolower($this->landlord->email), $copy['email']);
        $this->assertSame('owner', $copy['as']);

        // the invite mail goes out through the dispatcher fake: its button is a signed link for that person and side
        $access->sendInvite($this->landlord, RentalPortalAccessService::ROLE_LANDLORD, $this->admin, $this->lease);
        $sent = $this->mailer->sent;
        $this->assertNotEmpty($sent, 'the invite was sent');
        $url = $sent[array_key_last($sent)][1]->url;
        $q = $this->query($url);
        $this->assertSame(strtolower($this->landlord->email), PortalLink::parse($q['r'])['email']);
        $this->assertSame('owner', $q['as']);
    }

    // ── 4. one person who is both tenant and owner ───────────────────────────

    public function test_a_person_who_is_tenant_and_owner_gets_a_link_for_the_side_each_mail_is_written_for(): void
    {
        $both = $this->makeContact($this->agency, ['first_name' => 'Ayanda', 'email' => 'both.' . uniqid() . '@example.invalid']);
        LeaseTenant::create(['lease_id' => $this->lease->id, 'contact_id' => $both->id, 'is_primary' => false]);
        $this->property->contacts()->attach($both->id, ['role' => 'landlord']);
        $fault = $this->faultReport();
        $wo = $this->externalJob();

        $asTenant = (new RentalTenantStatusChangeMail($fault, 'Ayanda', $both))->portalUrl;
        $asOwner = (new RentalLandlordDecisionNeededMail($fault, 'Ayanda', $both->email))->portalUrl;

        $this->assertSame('tenant', $this->query($asTenant)['as']);
        $this->assertSame('owner', $this->query($asOwner)['as']);
        $this->assertSame(PortalLink::parse($this->query($asTenant)['r'])['email'], PortalLink::parse($this->query($asOwner)['r'])['email'], 'same person');
        $this->assertNotSame($asTenant, $asOwner);

        $scope = app(RentalPortalScopeService::class);
        $this->assertNotNull($scope->tenantWorkOrder($both, $wo->id), 'they hold the tenant side');
        $this->assertNotNull($scope->landlordWorkOrder($both, $wo->id), 'and the owner side');
    }
}
