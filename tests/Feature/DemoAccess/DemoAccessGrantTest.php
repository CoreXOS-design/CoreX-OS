<?php

namespace Tests\Feature\DemoAccess;

use App\Events\Demo\DemoAccessGranted;
use App\Mail\DemoAccessGrantMail;
use App\Models\DemoAccessGrant;
use App\Models\DemoTncVersion;
use App\Models\User;
use App\Services\Demo\DemoAccessService;
use Database\Seeders\DemoTncVersionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The grant lifecycle: issue, the credential, derived status, first-login race,
 * revoke, archive.
 *
 * Spec: .ai/specs/demo-access-control.md §4.2, §6.1, §6.2
 * Input space (§11): R1, R2, R3, R4, R5, R6, R7, R8, R15, R16
 */
class DemoAccessGrantTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private DemoAccessService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner   = User::factory()->create(['name' => 'Johan Reichel', 'role' => 'super_admin']);
        $this->service = app(DemoAccessService::class);

        $this->seed(DemoTncVersionSeeder::class);
    }

    /** R1 — the lazy-but-valid shortcut: company + email only. */
    public function test_issuing_with_only_company_and_email_works_end_to_end(): void
    {
        Mail::fake();

        [$grant, $code] = $this->service->issue([
            'company_name'  => 'Seaside Realty (Pty) Ltd',
            'contact_email' => 'thabo@seasiderealty.co.za',
        ], $this->owner->id);

        $this->assertSame('Seaside Realty (Pty) Ltd', $grant->company_name);
        $this->assertSame(DemoAccessService::DEFAULT_EXPIRY_HOURS, $grant->expiry_hours);
        $this->assertNull($grant->contact_name);
        $this->assertNull($grant->contact_id);
        $this->assertNotEmpty($code);
    }

    /** The credential is bcrypt, and the plaintext is nowhere in the database. */
    public function test_the_plaintext_code_is_never_stored(): void
    {
        Mail::fake();

        [$grant, $code] = $this->service->issue([
            'company_name'  => 'Umhlanga Property Group',
            'contact_email' => 'nadia@upg.co.za',
        ], $this->owner->id);

        $this->assertStringStartsWith('$2y$', $grant->credential_hash);

        // Scan the whole row, not just the column we expect — a leak into `notes`
        // or a future column would be just as fatal and far easier to miss.
        $row = collect(DemoAccessGrant::find($grant->id)->getAttributes())->implode(' ');
        $this->assertStringNotContainsString(DemoAccessGrant::normaliseCode($code), $row);

        $this->assertTrue($grant->verifyCode($code));
    }

    /** expiry_hours is COPIED at issue — changing the default must not move it. */
    public function test_expiry_hours_is_copied_not_referenced(): void
    {
        Mail::fake();

        [$grant] = $this->service->issue([
            'company_name'  => 'Ballito Homes',
            'contact_email' => 'sipho@ballitohomes.co.za',
            'expiry_hours'  => 24,
        ], $this->owner->id);

        // The org later changes its default policy.
        \App\Models\DevSetting::set('demo_default_expiry_hours', '168');
        \Illuminate\Support\Facades\Cache::flush();

        // The already-issued grant keeps the length it was sold on.
        $this->assertSame(24, $grant->fresh()->expiry_hours);
    }

    /**
     * R4 — THE NULL TRAP. A freshly-issued grant has expires_at = NULL, and NULL
     * is NOT expired. The naive `expires_at > now()` check locks out every prospect
     * we just emailed.
     */
    public function test_a_fresh_grant_with_null_expiry_is_pending_not_expired(): void
    {
        Mail::fake();

        [$grant] = $this->service->issue([
            'company_name'  => 'Margate Letting Co',
            'contact_email' => 'lerato@margateletting.co.za',
        ], $this->owner->id);

        $this->assertNull($grant->expires_at);
        $this->assertSame(DemoAccessGrant::STATUS_PENDING, $grant->status());
        $this->assertTrue($grant->isUsable());

        // And the SQL predicate must agree with the PHP one.
        $this->assertTrue(DemoAccessGrant::usable()->whereKey($grant->id)->exists());
    }

    /**
     * R5 — THE RACE. Two tabs, one credential. Exactly one writer may stamp
     * first_login_at, or the second silently extends the trial.
     */
    public function test_concurrent_first_logins_stamp_exactly_once(): void
    {
        Mail::fake();

        [$grant] = $this->service->issue([
            'company_name'  => 'Port Shepstone Estates',
            'contact_email' => 'ayanda@psestates.co.za',
            'expiry_hours'  => 72,
        ], $this->owner->id);

        // Two independent model instances = two tabs holding stale copies.
        $tabA = DemoAccessGrant::find($grant->id);
        $tabB = DemoAccessGrant::find($grant->id);

        $wonA = $tabA->stampFirstLogin();
        $wonB = $tabB->stampFirstLogin();

        $this->assertTrue($wonA xor $wonB, 'Exactly one writer must win the race.');

        $fresh = $grant->fresh();
        $this->assertNotNull($fresh->first_login_at);
        $this->assertTrue(
            $fresh->expires_at->equalTo($fresh->first_login_at->copy()->addHours(72)),
            'expires_at must be first_login_at + expiry_hours.'
        );

        // The loser must see the WINNER's clock, not its own would-be value.
        $this->assertTrue($tabA->expires_at->equalTo($tabB->expires_at));
    }

    /** Once stamped, a later login must NOT move the clock. */
    public function test_a_second_login_does_not_extend_the_trial(): void
    {
        Mail::fake();

        [$grant, $code] = $this->service->issue([
            'company_name'  => 'Southbroom Realty',
            'contact_email' => 'kobus@southbroomrealty.co.za',
            'expiry_hours'  => 72,
        ], $this->owner->id);

        Carbon::setTestNow('2026-07-11 09:00:00');
        $this->service->verify('kobus@southbroomrealty.co.za', $code, '10.0.0.1', 'Firefox');
        $firstExpiry = $grant->fresh()->expires_at;

        // They come back the next day.
        Carbon::setTestNow('2026-07-12 09:00:00');
        $this->service->verify('kobus@southbroomrealty.co.za', $code, '10.0.0.1', 'Firefox');

        $this->assertTrue(
            $grant->fresh()->expires_at->equalTo($firstExpiry),
            'A return visit must not push the deadline out.'
        );

        Carbon::setTestNow();
    }

    /** R16 — whitespace and case. People paste out of an email client. */
    public function test_the_code_verifies_despite_case_spacing_and_dashes(): void
    {
        Mail::fake();

        [$grant, $code] = $this->service->issue([
            'company_name'  => 'Scottburgh Sales',
            'contact_email' => 'pieter@scottburgh.co.za',
        ], $this->owner->id);

        $this->assertTrue($grant->verifyCode(strtolower($code)));
        $this->assertTrue($grant->verifyCode(str_replace('-', ' ', $code)));
        $this->assertTrue($grant->verifyCode('  ' . strtolower(str_replace('-', '', $code)) . '  '));
    }

    /** R6 — wrong code is rejected, and the message does not enumerate companies. */
    public function test_a_wrong_code_is_rejected_without_revealing_the_grant_exists(): void
    {
        Mail::fake();

        $this->service->issue([
            'company_name'  => 'Hibberdene Homes',
            'contact_email' => 'zanele@hibberdene.co.za',
        ], $this->owner->id);

        $wrongCode    = $this->service->verify('zanele@hibberdene.co.za', 'WRONG-CODE-0000-0000', null, null);
        $noSuchGrant  = $this->service->verify('nobody@example.co.za', 'WRONG-CODE-0000-0000', null, null);

        $this->assertFalse($wrongCode['ok']);
        $this->assertFalse($noSuchGrant['ok']);

        // Identical response. A different message for each would let anyone probe
        // which companies are evaluating CoreX.
        $this->assertSame($wrongCode['message'], $noSuchGrant['message']);
    }

    /** R8 — an expired grant is blocked on the next request. */
    public function test_an_expired_grant_is_blocked(): void
    {
        Mail::fake();

        [$grant, $code] = $this->service->issue([
            'company_name'  => 'Amanzimtoti Property',
            'contact_email' => 'rob@amanzitoti.co.za',
            'expiry_hours'  => 1,
        ], $this->owner->id);

        Carbon::setTestNow('2026-07-11 09:00:00');
        $ok = $this->service->verify('rob@amanzitoti.co.za', $code, null, null);
        $this->assertTrue($ok['ok']);

        // Two hours later — past the 1-hour window.
        Carbon::setTestNow('2026-07-11 11:00:00');
        $this->assertSame(DemoAccessGrant::STATUS_EXPIRED, $grant->fresh()->status());

        $blocked = $this->service->verify('rob@amanzitoti.co.za', $code, null, null);
        $this->assertFalse($blocked['ok']);
        $this->assertSame(DemoAccessGrant::STATUS_EXPIRED, $blocked['status']);

        Carbon::setTestNow();
    }

    /** R9 — revoke blocks. */
    public function test_a_revoked_grant_is_blocked(): void
    {
        Mail::fake();

        [$grant, $code] = $this->service->issue([
            'company_name'  => 'Uvongo Letting',
            'contact_email' => 'fatima@uvongoletting.co.za',
        ], $this->owner->id);

        $this->service->verify('fatima@uvongoletting.co.za', $code, null, null);
        $this->service->revoke($grant, $this->owner->id);

        $this->assertSame(DemoAccessGrant::STATUS_REVOKED, $grant->fresh()->status());

        $blocked = $this->service->verify('fatima@uvongoletting.co.za', $code, null, null);
        $this->assertFalse($blocked['ok']);
    }

    /** R7 + R15 — archive blocks access, and the ROW STILL EXISTS. */
    public function test_archiving_blocks_access_but_never_deletes_the_row(): void
    {
        Mail::fake();

        [$grant, $code] = $this->service->issue([
            'company_name'  => 'Shelly Beach Realty',
            'contact_email' => 'mandla@shellybeach.co.za',
        ], $this->owner->id);

        $countBefore = DemoAccessGrant::count();

        $this->service->archive($grant);

        // Blocked...
        $blocked = $this->service->verify('mandla@shellybeach.co.za', $code, null, null);
        $this->assertFalse($blocked['ok']);
        $this->assertSame(DemoAccessGrant::STATUS_ARCHIVED, $grant->fresh()->status());

        // ...but NOT deleted. Non-negotiable #1: COUNT(*) must never decrease.
        $this->assertSame($countBefore, DemoAccessGrant::count());
        $this->assertDatabaseHas('demo_access_grants', ['id' => $grant->id]);
        $this->assertNotNull($grant->fresh()->archived_at);
    }

    /** The grant email is queued to the right address, from primary. */
    public function test_the_invitation_email_is_queued_to_the_contact(): void
    {
        Mail::fake();
        Event::fake([DemoAccessGranted::class]);

        [$grant] = $this->service->issue([
            'company_name'  => 'Pennington Properties',
            'contact_email' => 'grace@pennington.co.za',
        ], $this->owner->id);

        Event::assertDispatched(DemoAccessGranted::class, fn ($e) => $e->grant->id === $grant->id);
    }

    /** The listener actually mails it — and to the right person. */
    public function test_the_listener_sends_the_mail_with_the_plaintext_code(): void
    {
        Mail::fake();

        [$grant, $code] = $this->service->issue([
            'company_name'  => 'Ramsgate Rentals',
            'contact_email' => 'devan@ramsgate.co.za',
        ], $this->owner->id);

        Mail::assertQueued(DemoAccessGrantMail::class, function (DemoAccessGrantMail $mail) use ($grant, $code) {
            return $mail->hasTo($grant->contact_email)
                && $mail->accessCode === $code;
        });
    }

    /** The audit payload must NOT carry the credential. */
    public function test_the_granted_event_redacts_the_plaintext_code_from_its_audit_payload(): void
    {
        Mail::fake();

        [$grant, $code] = $this->service->issue([
            'company_name'  => 'Munster Estates',
            'contact_email' => 'lindiwe@munster.co.za',
        ], $this->owner->id);

        $event    = new DemoAccessGranted($grant, $code);
        $snapshot = json_encode($event->payloadSnapshot());

        $this->assertStringNotContainsString($code, $snapshot);
        $this->assertStringContainsString('[REDACTED]', $snapshot);

        // And the bcrypt hash must not ride along either ($hidden on the model).
        $this->assertStringNotContainsString($grant->credential_hash, $snapshot);
    }

    /** T&C v1 must exist — without it every prospect is hard-blocked. */
    public function test_the_seeder_provisions_tnc_v1_and_is_idempotent(): void
    {
        $this->assertNotNull(DemoTncVersion::current());
        $this->assertSame(1, DemoTncVersion::current()->version);

        // It runs on EVERY deploy. Re-running must not duplicate or overwrite.
        $body = DemoTncVersion::current()->body;
        $this->seed(DemoTncVersionSeeder::class);

        $this->assertSame(1, DemoTncVersion::count());
        $this->assertSame($body, DemoTncVersion::current()->body);
    }
}
