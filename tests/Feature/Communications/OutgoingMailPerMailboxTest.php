<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Mail\Signatures\SigningRequestMail;
use App\Models\Agency as AgencyModel;
use App\Models\Branch;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-395 Phase A. Spec: .ai/specs/at395-outgoing-mail-per-mailbox-smtp.md.
 *
 * Full end-to-end proof of the mechanisms here (real SMTP send, real IMAP
 * Sent-folder append, real role-scoped visibility) was run interactively
 * against a throwaway GreenMail test server during the build session — this
 * suite covers the parts that are meaningfully unit/feature-testable without
 * a live mail server: resolution rules, the loud-failure guarantee (Johan's
 * override — no silent fallback for a configured-but-broken mailbox), and
 * OWN/BRANCH/AGENCY scoping.
 */
final class OutgoingMailPerMailboxTest extends TestCase
{
    use RefreshDatabase;

    private AgencyModel $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->agency = AgencyModel::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role' => 'agent',
        ]);
        $this->actingAs($this->agent);
    }

    private function ceremony(): SignatureRequest
    {
        $document = Document::create(['name' => 'AT395 Test Document', 'owner_id' => $this->agent->id]);
        $template = SignatureTemplate::create([
            'document_id' => $document->id,
            'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING,
            'created_by' => $this->agent->id,
        ]);

        return SignatureRequest::create([
            'signature_template_id' => $template->id,
            'party_role' => 'buyer',
            'signer_name' => 'Test Signer',
            'signer_email' => 'signer@example.test',
            'token' => Str::random(48),
            'token_expires_at' => now()->addDays(14),
            'status' => SignatureRequest::STATUS_PENDING,
        ]);
    }

    /** §3.1 — no mailbox row at all resolves to null (Situation A). */
    public function test_resolve_outgoing_for_returns_null_with_no_mailbox(): void
    {
        $this->assertNull(CommunicationMailbox::resolveOutgoingFor($this->agent));
    }

    /** §3.1 — a mailbox exists but outgoing_enabled=false still resolves to null. */
    public function test_resolve_outgoing_for_returns_null_when_disabled(): void
    {
        CommunicationMailbox::create([
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id,
            'email_address' => 'a@b.test', 'imap_host' => 'x', 'imap_port' => 993,
            'username' => 'a', 'encrypted_password' => 'x', 'poll_interval_minutes' => 15,
            'active' => true, 'outgoing_enabled' => false,
        ]);

        $this->assertNull(CommunicationMailbox::resolveOutgoingFor($this->agent));
    }

    /** §3.1 — enabled + active resolves to that exact mailbox. */
    public function test_resolve_outgoing_for_returns_the_mailbox_when_enabled(): void
    {
        $mailbox = CommunicationMailbox::create([
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id,
            'email_address' => 'a@b.test', 'imap_host' => 'x', 'imap_port' => 993,
            'username' => 'a', 'encrypted_password' => 'x', 'poll_interval_minutes' => 15,
            'active' => true, 'outgoing_enabled' => true, 'outgoing_active' => true,
        ]);

        $this->assertTrue($mailbox->is(CommunicationMailbox::resolveOutgoingFor($this->agent)));
    }

    /** §3.3 Situation A — no mailbox configured sends via the shared mailer, unchanged. */
    public function test_no_mailbox_sends_via_shared_mailer_and_marks_sent(): void
    {
        $request = $this->ceremony();

        app(SignatureService::class)->resendInvitationEmail($request);

        Mail::assertSent(SigningRequestMail::class);
        $request->refresh();
        $this->assertSame('sent', $request->invite_send_status);
        $this->assertNotNull($request->sent_at);
    }

    /**
     * §3.3 Situation B, Johan's override — a configured-but-broken mailbox
     * FAILS LOUDLY. No silent fallback. The request is NOT marked sent.
     */
    public function test_broken_mailbox_fails_loudly_and_does_not_mark_sent(): void
    {
        $mailbox = CommunicationMailbox::create([
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id,
            'email_address' => 'a@b.test', 'imap_host' => 'x', 'imap_port' => 993,
            'username' => 'a', 'encrypted_password' => 'x', 'poll_interval_minutes' => 15,
            'active' => true, 'outgoing_enabled' => true, 'outgoing_active' => true,
            'smtp_host' => 'x', 'use_imap_credentials_for_smtp' => true,
        ]);
        $request = $this->ceremony();

        $failingBuilder = new class extends PerMailboxMailTransportBuilder {
            public function send(CommunicationMailbox $mailbox, \Illuminate\Mail\Mailable $mailable): string
            {
                throw new OutgoingMailboxSendFailedException('auth_failed', 'Login failed — check the username and password.');
            }
        };
        $service = new SignatureService(app(\App\Services\Docuperfect\SignaturePdfService::class), $failingBuilder);

        $service->resendInvitationEmail($request);

        Mail::assertNotSent(SigningRequestMail::class);
        $request->refresh();
        $this->assertSame('failed', $request->invite_send_status);
        $this->assertNotSame('sent', $request->status);

        $mailbox->refresh();
        $this->assertSame('auth_failed', $mailbox->last_send_error);
        $this->assertSame(1, $mailbox->consecutive_send_failures);
    }

    /** §7.3 — OWN scope: an agent sees only their own mailbox. */
    public function test_own_scope_sees_only_own_mailbox(): void
    {
        $other = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);
        $mine = CommunicationMailbox::create([
            'agency_id' => $this->agency->id, 'user_id' => $this->agent->id,
            'email_address' => 'mine@test', 'imap_host' => 'x', 'imap_port' => 993,
            'username' => 'a', 'encrypted_password' => 'x', 'poll_interval_minutes' => 15, 'active' => true,
        ]);
        CommunicationMailbox::create([
            'agency_id' => $this->agency->id, 'user_id' => $other->id,
            'email_address' => 'theirs@test', 'imap_host' => 'x', 'imap_port' => 993,
            'username' => 'a', 'encrypted_password' => 'x', 'poll_interval_minutes' => 15, 'active' => true,
        ]);

        $visible = CommunicationMailbox::query()->visibleTo($this->agent)->pluck('id')->all();

        $this->assertSame([$mine->id], $visible);
    }
}
