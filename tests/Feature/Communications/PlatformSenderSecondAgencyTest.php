<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Mail\Signatures\PartySignedNotificationMail;
use App\Mail\TitleDeeds\TitleDeedNoticeMail;
use App\Models\Agency;
use App\Models\OutboundMailGuardCapture;
use App\Models\User;
use App\Support\PlatformSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Multi-agency, always: when neither the agent nor their agency has a mailbox to send through, mail leaves from the PLATFORM's
 * neutral sender as "<agent> via <agency>", replying to the agent - and that sender is never an agency's domain. QA1 had
 * MAIL_FROM_ADDRESS=system@hfcoastal.co.za, so a new second agency's mail went out from Home Finders' domain.
 */
final class PlatformSenderSecondAgencyTest extends TestCase
{
    use RefreshDatabase;

    private User $capeAgent;

    protected function setUp(): void
    {
        parent::setUp();
        PlatformSender::flush();

        // The decoy: a real HFC agency owning hfcoastal.co.za, and an install whose .env still names that domain as the default sender.
        Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-coastal'])->forceFill(['email' => 'admin@hfcoastal.co.za'])->save();
        config([
            'mail.from.address' => 'system@hfcoastal.co.za', 'mail.from.name' => 'Home Finders Coastal',
            'mail.platform_from.address' => null, 'mail.mailers.corex.from_address' => 'mail@corexos.co.za',
        ]);

        $cape = Agency::create(['name' => 'ZZ Cape Agency (throwaway)', 'slug' => 'zz-cape-' . uniqid()]);
        $cape->forceFill(['email' => 'hello@caperentals.test'])->save();
        // A personal address (not the agency's domain) and no mailbox row: exactly the fallback path.
        $this->capeAgent = User::factory()->create(['agency_id' => $cape->id, 'branch_id' => null, 'role' => 'agent', 'name' => 'ZZ Cape Agent', 'email' => 'capeagent@personal.test']);
    }

    private function mailFor(User $agent): TitleDeedNoticeMail
    {
        return (new TitleDeedNoticeMail('Tenant', '1 Long Street', null))->fromAgent($agent);
    }

    public function test_a_second_agencys_mail_with_no_mailbox_never_carries_another_agencys_domain(): void
    {
        $envelope = $this->mailFor($this->capeAgent)->envelope();

        $this->assertSame('mail@corexos.co.za', $envelope->from->address);
        $this->assertSame('ZZ Cape Agent via ZZ Cape Agency (throwaway)', $envelope->from->name);
        $this->assertStringNotContainsString('hfcoastal', strtolower($envelope->from->address . $envelope->from->name));
        $this->assertCount(1, $envelope->replyTo);
        $this->assertSame('capeagent@personal.test', $envelope->replyTo[0]->address, 'replies go to the agent');
    }

    public function test_an_agent_with_no_address_replies_to_their_own_agency(): void
    {
        $this->capeAgent->forceFill(['email' => ''])->saveQuietly();

        $envelope = $this->mailFor($this->capeAgent->fresh())->envelope();

        $this->assertSame('hello@caperentals.test', $envelope->replyTo[0]->address);
    }

    public function test_the_explicit_platform_sender_wins_when_it_is_not_an_agency_domain(): void
    {
        config(['mail.platform_from.address' => 'noreply@corexos.co.za']);

        $this->assertSame('noreply@corexos.co.za', $this->mailFor($this->capeAgent)->envelope()->from->address);
    }

    public function test_a_default_sender_that_is_not_an_agency_domain_is_used_as_is(): void
    {
        config(['mail.from.address' => 'mail@platform.test']);

        $this->assertSame('mail@platform.test', PlatformSender::address());
    }

    public function test_no_agencys_domain_is_ever_the_platform_sender_not_even_the_sending_agencys_own(): void
    {
        config(['mail.platform_from.address' => 'hello@caperentals.test']); // the Cape agency's own domain

        $this->assertSame('mail@corexos.co.za', PlatformSender::address());
    }

    public function test_an_unusable_configuration_falls_back_to_the_platforms_own_address(): void
    {
        config(['mail.from.address' => 'not-an-email', 'mail.mailers.corex.from_address' => null]);

        $this->assertSame(PlatformSender::LAST_RESORT, PlatformSender::address());
    }

    public function test_the_no_agent_case_and_the_agent_footer_use_the_neutral_sender_too(): void
    {
        $mail = new TitleDeedNoticeMail('Tenant', '1 Long Street', null);

        $this->assertSame('mail@corexos.co.za', $mail->envelope()->from->address);
        $this->assertSame('mail@corexos.co.za', $mail->content()->with['agentFooter']['email']);
    }

    public function test_the_party_signed_notice_uses_the_neutral_sender(): void
    {
        $envelope = (new PartySignedNotificationMail('Agent', 'seller', 'Seller', 'Doc', 'https://x.test'))->envelope();

        $this->assertSame('mail@corexos.co.za', $envelope->from->address);
    }

    public function test_the_outbound_guard_still_holds_the_second_agencys_mail_and_records_the_neutral_sender(): void
    {
        config([
            'app.env' => 'staging', 'app.url' => 'https://qatesting1.corexos.co.za',
            'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.corex.host' => '127.0.0.1', 'mail.mailers.otp.host' => '127.0.0.1',
            'mail.guard.sink_host' => '127.0.0.1',
        ]);
        Cache::flush();

        Mail::to('tenant@example.test')->send($this->mailFor($this->capeAgent));

        $this->assertSame(1, OutboundMailGuardCapture::count(), 'held by the guard, not sent');
        $raw = OutboundMailGuardCapture::first()->raw_mime;
        $this->assertStringContainsString('mail@corexos.co.za', $raw);
        $this->assertStringNotContainsString('hfcoastal', strtolower($raw));
    }
}
