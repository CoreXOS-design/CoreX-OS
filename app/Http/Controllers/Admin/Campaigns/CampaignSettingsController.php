<?php

namespace App\Http\Controllers\Admin\Campaigns;

use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Http\Controllers\Controller;
use App\Models\Campaigns\Campaign;
use App\Models\Campaigns\CampaignSender;
use App\Services\Campaigns\CampaignHtmlRenderer;
use App\Services\Campaigns\CampaignInboxChecker;
use App\Services\Campaigns\CampaignSendService;
use App\Services\Campaigns\CampaignSettings;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * AT-534 — Campaigns → Settings: the defaults (§4) and the CoreX sending addresses with their signatures (§3.0, D4). Owner-only and
 * DELIBERATELY no permission key. Passwords are write-only: never sent back to the browser, blank on edit keeps the saved one.
 */
class CampaignSettingsController extends Controller
{
    use GuardsCampaigns;

    public function __construct(private CampaignSettings $settings)
    {
    }

    public function index(Request $request): View
    {
        $this->guard($request);
        $showArchived = $request->query('archived') === '1';

        return view('admin.campaigns.settings', [
            'settings' => $this->settings->all(),
            'senders' => CampaignSender::orderByDesc('is_default')->orderBy('email')->get(),
            'archivedSenders' => $showArchived ? CampaignSender::onlyTrashed()->orderBy('email')->get() : collect(),
            'showArchived' => $showArchived,
            'archivedCount' => CampaignSender::onlyTrashed()->count(),
            'maxDailyLimit' => (int) config('campaigns.max_daily_limit'),
            'defaultSmtpHost' => (string) config('campaigns.defaults.smtp_host'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->guard($request);
        $max = (int) config('campaigns.max_daily_limit');
        $request->merge(['opt_out_footer' => trim((string) $request->input('opt_out_footer'))]);
        $data = $request->validate([
            'daily_limit' => ['required', 'integer', 'min:1', 'max:' . $max],
            'send_days' => ['required', 'array', 'min:1'],
            'send_days.*' => ['integer', 'between:1,7'],
            'send_from' => ['required', 'date_format:H:i'],
            'send_to' => ['required', 'date_format:H:i', 'after:send_from'],
            'opt_out_footer' => ['required', 'string', 'max:500'],
            'min_day_gap' => ['required', 'integer', 'min:1', 'max:30'],
        ], [
            'daily_limit.min' => 'The daily limit must be at least 1.',
            'daily_limit.max' => 'The daily limit can be at most ' . $max . '.',
            'send_days.required' => 'Choose at least one send day.',
            'send_from.date_format' => 'Enter a time like 08:00.',
            'send_to.date_format' => 'Enter a time like 16:00.',
            'send_to.after' => 'The end time must be after the start time.',
            'opt_out_footer.required' => 'Enter the opt-out line every email ends with.',
            'min_day_gap.min' => 'The minimum gap is at least 1 day.',
            'min_day_gap.max' => 'The minimum gap can be at most 30 days.',
        ]);
        $this->settings->save($data);

        return back()->with('success', 'Settings saved. They apply to campaigns you create from now on.');
    }

    // ── Sending addresses ────────────────────────────────────────────────────────────────────

    public function storeSender(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $this->validateSender($request, null, 'newSender');
        $newImage = $this->storeSignatureImage($request);
        if ($newImage !== null) {
            $data['signature_image_path'] = $newImage;
        }
        DB::transaction(function () use ($data, $request) {
            $first = ! CampaignSender::exists();
            $sender = CampaignSender::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'is_default' => false]);
            if ($first || $request->boolean('is_default')) {
                $this->setDefault($sender);
            }
        });

        return back()->with('success', 'Sending address added. Use Test to check it can send.');
    }

    public function updateSender(Request $request, int $sender): RedirectResponse
    {
        $this->guard($request);
        $sender = CampaignSender::findOrFail($sender);
        $data = $this->validateSender($request, $sender, "sender_{$sender->id}");
        if (($data['encrypted_password'] ?? '') === '') {
            unset($data['encrypted_password']); // blank = keep the saved password
        }
        $oldImage = $sender->signature_image_path;
        $newImage = $this->storeSignatureImage($request);
        if ($newImage !== null) {
            $data['signature_image_path'] = $newImage;
        } elseif ($request->boolean('remove_signature_image')) {
            $data['signature_image_path'] = null;
        }
        $sender->fill($data + ['updated_by' => $request->user()->id, 'last_send_error' => null, 'last_inbox_error' => null])->save();
        if ($oldImage && $oldImage !== $sender->signature_image_path && str_starts_with($oldImage, CampaignSender::SIGNATURE_IMAGE_DIR . '/')) {
            Storage::disk('local')->delete($oldImage); // the replaced / removed picture; nothing else points at it
        }

        return back()->with('success', 'Sending address saved.');
    }

    /** Owner-only preview of the saved signature picture (the files are private; there is no public URL). */
    public function signatureImage(Request $request, int $sender)
    {
        $this->guard($request);
        $path = CampaignSender::withTrashed()->findOrFail($sender)->signatureImageFullPath();
        abort_if($path === null, 404);

        return response()->file($path, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=300']);
    }

    /** Saves an uploaded signature picture (already validated) under a random name; returns its storage path or null. */
    private function storeSignatureImage(Request $request): ?string
    {
        $file = $request->file('signature_image');
        if (! $file || ! $file->isValid()) {
            return null;
        }
        $ext = strtolower((string) $file->guessExtension());

        return $file->storeAs(CampaignSender::SIGNATURE_IMAGE_DIR, Str::random(40) . '.' . $ext, 'local') ?: null;
    }

    public function archiveSender(Request $request, int $sender): RedirectResponse
    {
        $this->guard($request);
        $sender = CampaignSender::findOrFail($sender);
        $running = Campaign::where('campaign_sender_id', $sender->id)->where('status', Campaign::STATUS_RUNNING)->value('name');
        if ($running) {
            return back()->with('error', 'Campaign "' . $running . '" is sending from this address. Pause it or choose another address first.');
        }
        DB::transaction(function () use ($sender, $request) {
            $wasDefault = $sender->is_default;
            $sender->forceFill(['is_default' => false, 'updated_by' => $request->user()->id])->save();
            $sender->delete();
            if ($wasDefault && ($next = CampaignSender::orderBy('email')->first())) {
                $this->setDefault($next);
            }
        });

        return back()->with('success', 'Sending address archived.');
    }

    public function restoreSender(Request $request, int $sender): RedirectResponse
    {
        $this->guard($request);
        $sender = CampaignSender::onlyTrashed()->findOrFail($sender);
        if (CampaignSender::where('email', $sender->email)->exists()) {
            return back()->with('error', $sender->email . ' already exists as a sending address.');
        }
        $sender->restore();
        if (! CampaignSender::where('is_default', true)->exists()) {
            $this->setDefault($sender);
        }

        return back()->with('success', 'Sending address restored.');
    }

    public function makeDefault(Request $request, int $sender): RedirectResponse
    {
        $this->guard($request);
        $this->setDefault(CampaignSender::findOrFail($sender));

        return back()->with('success', 'Default sending address changed. New campaigns start with it.');
    }

    /** Test connection: one test email to the address itself, plus an inbox login — each reported on its own (AT-395 §6 pattern). */
    public function testSender(Request $request, int $sender, PerMailboxMailTransportBuilder $transport, CampaignInboxChecker $inbox, CampaignSendService $sends): RedirectResponse
    {
        $this->guard($request);
        $sender = CampaignSender::findOrFail($sender);

        $mail = new class($sender) extends Mailable {
            public function __construct(private CampaignSender $s)
            {
            }

            public function build(): static
            {
                $text = "This is a connection test from CoreX Campaigns. It is safe to delete.\n";
                $mail = $this->from($this->s->email, $this->s->from_name)
                    ->subject('CoreX campaigns test — ' . now()->format('Y-m-d H:i'))
                    ->text('mail.campaigns.step-text', ['bodyText' => $text]);

                // With a signature picture the test shows the real look: text, signature, picture.
                if (($image = $this->s->signatureImageFullPath()) !== null) {
                    $mail->view('mail.campaigns.step-html', [
                        'bodyHtml' => app(CampaignHtmlRenderer::class)->render(rtrim($text), trim((string) $this->s->signature) ?: null, '', ''),
                        'signatureImagePath' => $image,
                        'fromName' => $this->s->from_name,
                    ]);
                }

                return $mail;
            }
        };
        $mail->to($sender->email); // only ever the address itself

        $sendError = null;
        try {
            $transport->send($sender->toTransientMailbox(), $mail);
            $sender->forceFill(['last_sent_at' => now(), 'last_send_error' => null, 'last_send_error_at' => null])->save();
        } catch (OutgoingMailboxSendFailedException $e) {
            $sendError = $e->sanitisedReason;
            $sender->forceFill(['last_send_error' => $sendError, 'last_send_error_at' => now()])->save();
        }

        $inboxError = $inbox->testInbox($sender);
        $sender->forceFill($inboxError ? ['last_inbox_error' => $inboxError] : ['last_inbox_error' => null])->save();

        return back()->with('sender_test', [
            'sender_id' => $sender->id,
            'send_ok' => $sendError === null,
            'send_text' => $sendError === null ? 'Test email sent to ' . $sender->email . '. Check that inbox.' : 'Could not send: ' . $sends->friendlyReason($sendError) . '.',
            'inbox_ok' => $inboxError === null,
            'inbox_text' => $inboxError === null ? 'Inbox opened — replies and bounces can be read.' : 'Inbox: ' . CampaignInboxChecker::friendly($inboxError),
        ]);
    }

    private function setDefault(CampaignSender $sender): void
    {
        DB::transaction(function () use ($sender) {
            CampaignSender::whereKeyNot($sender->id)->where('is_default', true)->update(['is_default' => false]);
            $sender->forceFill(['is_default' => true])->save();
        });
    }

    private function validateSender(Request $request, ?CampaignSender $sender, string $bag): array
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        $request->merge([
            'email' => $email,
            'from_name' => trim((string) $request->input('from_name')),
            'signature' => rtrim(str_replace("\r\n", "\n", (string) $request->input('signature'))),
            'smtp_host' => trim((string) $request->input('smtp_host')),
            'username' => trim((string) $request->input('username')) !== '' ? trim((string) $request->input('username')) : $email,
            'imap_host' => trim((string) $request->input('imap_host')),
        ]);

        $data = $request->validateWithBag($bag, [
            'email' => ['required', 'email', 'max:254', Rule::unique('campaign_senders', 'email')->whereNull('deleted_at')->ignore($sender?->id)],
            'from_name' => ['required', 'string', 'max:150'],
            'signature' => ['nullable', 'string', 'max:2000'],
            'signature_image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif', 'max:1024', 'dimensions:max_width=1200,max_height=600'],
            'smtp_host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'smtp_port' => ['required', 'integer', 'between:1,65535'],
            'smtp_encryption' => ['required', Rule::in(['tls', 'ssl', 'none'])],
            'username' => ['required', 'string', 'max:255'],
            'password' => [$sender ? 'nullable' : 'required', 'string', 'max:255'],
            'imap_host' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9.\-]+$/'],
            'imap_port' => ['required', 'integer', 'between:1,65535'],
            'imap_encryption' => ['required', Rule::in(['ssl', 'tls', 'none'])],
        ], [
            'email.required' => 'Enter the sending email address.',
            'email.email' => 'That is not a valid email address.',
            'email.unique' => 'That address is already a sending address.',
            'from_name.required' => 'Enter the name people see the email from.',
            'smtp_host.required' => 'Enter the mail server.',
            'smtp_host.regex' => 'Enter a server name like mail.corexos.co.za.',
            'imap_host.regex' => 'Enter a server name like mail.corexos.co.za.',
            'password.required' => 'Enter the mailbox password.',
            'signature_image.mimes' => 'The signature picture must be a JPG, PNG or GIF file.',
            'signature_image.max' => 'The signature picture must be 1 MB or smaller.',
            'signature_image.dimensions' => 'The signature picture must be no bigger than 1200 pixels wide and 600 pixels high.',
            'signature_image.uploaded' => 'The picture could not be uploaded. Use a JPG, PNG or GIF under 1 MB.',
        ]);

        return [
            'email' => $data['email'],
            'from_name' => $data['from_name'],
            'signature' => ($data['signature'] ?? '') !== '' ? $data['signature'] : null,
            'smtp_host' => $data['smtp_host'],
            'smtp_port' => (int) $data['smtp_port'],
            'smtp_encryption' => $data['smtp_encryption'],
            'username' => $data['username'],
            'encrypted_password' => (string) ($data['password'] ?? ''),
            'imap_host' => ($data['imap_host'] ?? '') !== '' ? $data['imap_host'] : null,
            'imap_port' => (int) $data['imap_port'],
            'imap_encryption' => $data['imap_encryption'],
        ];
    }
}
