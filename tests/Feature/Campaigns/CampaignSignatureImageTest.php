<?php

declare(strict_types=1);

namespace Tests\Feature\Campaigns;

use App\Models\Campaigns\Campaign;
use App\Models\Campaigns\CampaignLead;
use App\Models\Campaigns\CampaignList;
use App\Models\Campaigns\CampaignSend;
use App\Models\Campaigns\CampaignSender;
use App\Models\Campaigns\CampaignStep;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Role;
use App\Models\User;
use App\Services\Campaigns\CampaignSendService;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-534 — image signature for a Campaigns sending address (.ai/specs/campaigns.md 9.3). No socket is ever opened: the per-mailbox
 * transport is replaced by a recorder that also builds the real MIME through an in-memory transport.
 */
final class CampaignSignatureImageTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{mailable:Mailable, mime:string}> */
    private array $sent = [];

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $test = $this;
        $this->app->instance(PerMailboxMailTransportBuilder::class, new class($test) extends PerMailboxMailTransportBuilder {
            public function __construct(private $t)
            {
            }

            public function send(CommunicationMailbox $mailbox, Mailable $mailable): string
            {
                return $this->t->record($mailable);
            }
        });

        $role = Role::create(['name' => 'super_admin', 'label' => 'System Owner']);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();
        $this->owner = User::factory()->create(['agency_id' => null, 'branch_id' => null, 'role' => 'super_admin']);
    }

    public function record(Mailable $mailable): string
    {
        $mailer = new Mailer('t', app('view'), new ArrayTransport());
        $mime = (string) $mailer->send($mailable)?->toString();
        $this->sent[] = ['mailable' => $mailable, 'mime' => $mime];

        return $mime;
    }

    private function png(int $w = 200, int $h = 60): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 20, 90, 160));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function senderWith(?string $imagePath): CampaignSender
    {
        return CampaignSender::create([
            'email' => 'andre@corexos.co.za', 'from_name' => 'Andre — CoreX OS', 'signature' => "Andre Roets\nCoreX OS",
            'signature_image_path' => $imagePath,
            'smtp_host' => 'mail.corexos.co.za', 'smtp_port' => 587, 'smtp_encryption' => 'tls', 'username' => 'andre@corexos.co.za',
            'encrypted_password' => 'Pw-Zq81xKv', 'is_default' => true,
        ]);
    }

    private function runningCampaign(CampaignSender $sender, string $firstName = 'Thandi'): Campaign
    {
        $list = CampaignList::create(['name' => 'Agencies']);
        CampaignLead::create(['campaign_list_id' => $list->id, 'first_name' => $firstName, 'company' => 'Co1', 'email' => 'can.assurance+1@gmail.com']);
        $campaign = Campaign::create([
            'name' => 'Test', 'campaign_list_id' => $list->id, 'campaign_sender_id' => $sender->id, 'status' => 'draft',
            'send_days' => [1, 2, 3, 4, 5], 'send_from' => '08:00', 'send_to' => '16:00', 'daily_limit' => 60,
        ]);
        CampaignStep::create(['campaign_id' => $campaign->id, 'step_number' => 1, 'subject' => 'Hello {company}', 'body' => "Hi {first_name},\nSee https://corexos.co.za/demo.", 'day_gap' => 0]);
        app(CampaignSendService::class)->start($campaign, $this->owner->id);

        return $campaign->fresh();
    }

    public function test_a_sender_with_a_picture_sends_html_with_the_picture_embedded_and_a_plain_text_copy(): void
    {
        Storage::disk('local')->put('campaign-signatures/sig.png', $this->png());
        $campaign = $this->runningCampaign($this->senderWith('campaign-signatures/sig.png'));

        $this->assertSame(1, app(CampaignSendService::class)->runBatch($campaign, Carbon::parse('2027-01-04 09:00'))['sent']);

        $send = CampaignSend::firstOrFail();
        $this->assertNotNull($send->body_html, 'the HTML version is snapshotted on the send row');
        $this->assertStringContainsString('Andre Roets', $send->body, 'the plain-text copy still carries the signature text');
        $this->assertStringContainsString('Andre Roets', $send->body_html);
        $this->assertStringContainsString('href="https://corexos.co.za/demo"', $send->body_html, 'URLs become links, trailing full stop kept outside');

        $mime = $this->sent[0]['mime'];
        $this->assertStringContainsString('multipart/alternative', $mime);
        $this->assertStringContainsString('text/plain', $mime);
        $this->assertStringContainsString('text/html', $mime);
        $this->assertStringContainsString('image/png', $mime, 'the picture travels inside the email');
        $this->assertMatchesRegularExpression('/<img src="cid:[^"]+"/', quoted_printable_decode($mime), 'html points at the embedded picture');
        $this->assertStringNotContainsString('[[CAMPAIGN_SIGNATURE_IMAGE]]', $mime);
        $this->assertStringContainsString('List-Unsubscribe-Post', $mime);
    }

    public function test_merge_field_values_are_escaped_in_the_html_version(): void
    {
        Storage::disk('local')->put('campaign-signatures/sig.png', $this->png());
        $campaign = $this->runningCampaign($this->senderWith('campaign-signatures/sig.png'), '<script>alert(1)</script>');
        app(CampaignSendService::class)->runBatch($campaign, Carbon::parse('2027-01-04 09:00'));

        $html = CampaignSend::firstOrFail()->body_html;
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_sender_without_a_picture_stays_plain_text_only(): void
    {
        $campaign = $this->runningCampaign($this->senderWith(null));
        app(CampaignSendService::class)->runBatch($campaign, Carbon::parse('2027-01-04 09:00'));

        $this->assertNull(CampaignSend::firstOrFail()->body_html);
        $this->assertStringNotContainsString('text/html', $this->sent[0]['mime']);
        $this->assertStringNotContainsString('multipart/alternative', $this->sent[0]['mime']);
    }

    public function test_a_picture_that_has_gone_missing_drops_the_picture_but_still_sends(): void
    {
        $campaign = $this->runningCampaign($this->senderWith('campaign-signatures/gone.png'));
        app(CampaignSendService::class)->runBatch($campaign, Carbon::parse('2027-01-04 09:00'));

        $this->assertNull(CampaignSend::firstOrFail()->body_html, 'no usable file: plain text, exactly as before');
        $this->assertCount(1, $this->sent);
    }

    public function test_owner_can_upload_replace_and_remove_the_picture(): void
    {
        $this->actingAs($this->owner);
        $fields = [
            'email' => 'andre@corexos.co.za', 'from_name' => 'Andre', 'password' => 'Pw-Zq81xKv', 'smtp_host' => 'mail.corexos.co.za',
            'smtp_port' => 587, 'smtp_encryption' => 'tls', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        ];

        $this->post(route('admin.campaigns.senders.store'), $fields + ['signature_image' => UploadedFile::fake()->createWithContent('logo.png', $this->png())])
            ->assertSessionHasNoErrors();
        $sender = CampaignSender::firstOrFail();
        $first = $sender->signature_image_path;
        $this->assertStringStartsWith('campaign-signatures/', $first);
        Storage::disk('local')->assertExists($first);

        $this->get(route('admin.campaigns.senders.image', $sender->id))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('admin.campaigns.settings'))->assertOk()->assertSee('data-signature-preview', false);

        // replace: old file is deleted, new one saved
        $this->put(route('admin.campaigns.senders.update', $sender->id), $fields + ['signature_image' => UploadedFile::fake()->createWithContent('new.png', $this->png(300, 80))])
            ->assertSessionHasNoErrors();
        $second = $sender->fresh()->signature_image_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        // saving with no new file keeps the picture
        $this->put(route('admin.campaigns.senders.update', $sender->id), $fields)->assertSessionHasNoErrors();
        $this->assertSame($second, $sender->fresh()->signature_image_path);

        // remove
        $this->put(route('admin.campaigns.senders.update', $sender->id), $fields + ['remove_signature_image' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($sender->fresh()->signature_image_path);
        Storage::disk('local')->assertMissing($second);
        $this->get(route('admin.campaigns.senders.image', $sender->id))->assertNotFound();
    }

    public function test_wrong_file_types_and_files_over_1mb_are_refused_but_pixel_size_is_not_limited(): void
    {
        $this->actingAs($this->owner);
        $fields = [
            'email' => 'andre@corexos.co.za', 'from_name' => 'Andre', 'password' => 'Pw-Zq81xKv', 'smtp_host' => 'mail.corexos.co.za',
            'smtp_port' => 587, 'smtp_encryption' => 'tls', 'imap_port' => 993, 'imap_encryption' => 'ssl',
        ];

        foreach ([
            UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('notes.txt', 'hello'),
            UploadedFile::fake()->image('big.png', 100, 100)->size(1500),
        ] as $bad) {
            $this->post(route('admin.campaigns.senders.store'), $fields + ['signature_image' => $bad])->assertSessionHasErrors('signature_image', null, 'newSender');
        }
        $this->assertSame(0, CampaignSender::count(), 'nothing is saved when the picture is refused');
        $this->assertSame([], Storage::disk('local')->files('campaign-signatures'));

        // a very large picture (in pixels) under 1 MB is accepted: no pixel limit any more
        $this->post(route('admin.campaigns.senders.store'), $fields + ['signature_image' => UploadedFile::fake()->createWithContent('wide.png', $this->png(3000, 1500))])
            ->assertSessionHasNoErrors();
        $this->assertNotNull(CampaignSender::firstOrFail()->signature_image_path);
    }

    public function test_step_preview_shows_the_email_as_a_recipient_sees_it_with_and_without_a_picture(): void
    {
        $this->actingAs($this->owner);
        Storage::disk('local')->put('campaign-signatures/sig.png', $this->png());
        $campaign = $this->runningCampaign($this->senderWith('campaign-signatures/sig.png'), 'Thandi');
        $step = CampaignStep::firstOrFail();

        $this->get(route('admin.campaigns.show', ['campaign' => $campaign->id, 'tab' => 'steps']))->assertOk()->assertSee('data-step-preview', false);

        $res = $this->get(route('admin.campaigns.steps.preview', [$campaign->id, $step->id]))->assertOk();
        $res->assertSee('Hello Co1');                                   // subject with the merge field filled
        $res->assertSee('Hi Thandi,');                                  // body merged for the first person on the list
        $res->assertSee('Andre Roets', false);                          // signature text
        $res->assertSee('can.assurance+1@gmail.com', false);            // To
        $res->assertSee('Formatted email with your signature picture');
        $res->assertSee(route('admin.campaigns.senders.image', $campaign->sender->id), false); // the picture itself
        $res->assertSee('a personal unsubscribe link is added for each person');
        $this->assertSame(0, CampaignSend::count(), 'previewing never sends or saves anything');

        // no picture -> shown honestly as plain text
        $campaign->sender->update(['signature_image_path' => null]);
        $this->get(route('admin.campaigns.steps.preview', [$campaign->id, $step->id]))->assertOk()
            ->assertSee('Plain text - no signature picture')->assertDontSee('Formatted email with your signature picture');
    }

    public function test_sender_preview_uses_sample_text_and_is_owner_only(): void
    {
        Storage::disk('local')->put('campaign-signatures/sig.png', $this->png());
        $sender = $this->senderWith('campaign-signatures/sig.png');

        $this->actingAs($this->owner)->get(route('admin.campaigns.settings'))->assertOk()->assertSee('data-sender-preview', false);
        $this->get(route('admin.campaigns.senders.preview', $sender->id))->assertOk()
            ->assertSee('Hi Thandi,')->assertSee('Formatted email with your signature picture');

        $this->assertContains($this->actingAs(User::factory()->create())->get(route('admin.campaigns.senders.preview', $sender->id))->status(), [403, 404]);
        $campaign = $this->runningCampaign($sender);
        $step = CampaignStep::firstOrFail();
        $this->assertContains($this->get(route('admin.campaigns.steps.preview', [$campaign->id, $step->id]))->status(), [403, 404]);
    }

    public function test_the_picture_is_owner_only(): void
    {
        Storage::disk('local')->put('campaign-signatures/sig.png', $this->png());
        $sender = $this->senderWith('campaign-signatures/sig.png');

        $this->assertContains($this->actingAs(User::factory()->create())->get(route('admin.campaigns.senders.image', $sender->id))->status(), [403, 404]);
        auth()->logout();
        $this->assertContains($this->get(route('admin.campaigns.senders.image', $sender->id))->status(), [302, 401, 403, 404]);
    }
}
