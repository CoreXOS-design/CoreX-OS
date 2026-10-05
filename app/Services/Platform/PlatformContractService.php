<?php

namespace App\Services\Platform;

use App\Events\Platform\AgencyContractDeclined;
use App\Events\Platform\AgencyContractSent;
use App\Events\Platform\AgencyContractSigned;
use App\Mail\Platform\PlatformContractInviteMail;
use App\Mail\Platform\PlatformContractSignedMail;
use App\Models\Agency;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\PlatformContractAttachment;
use App\Models\Platform\PlatformContractEnvelope;
use App\Models\Platform\PlatformContractEvent;
use App\Models\Platform\PlatformContractTemplate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dev-side e-sign of CoreX's own contract. Spec: agency-timeline-and-platform-esign.md §6.
 *
 * Evidence kept per signature: typed name, consent text, IP, user agent,
 * timestamp, optional drawn signature, SHA-256 of the sealed PDF, and an
 * append-only event log.
 */
class PlatformContractService
{
    public const CONSENT = 'I confirm that I am authorised to sign this agreement on behalf of the agency named above, that I have read it in full, and that typing my name and submitting this form is my electronic signature.';

    public const MERGE_FIELDS = [
        'agency_name', 'agency_trading_name', 'agency_reg_no', 'agency_vat_no', 'agency_address',
        'signatory_name', 'signatory_email', 'today', 'go_live_date', 'billing_start_date',
    ];

    public function __construct(private AgencyTimelineService $timelines)
    {
    }

    // ── Merge ──────────────────────────────────────────────────────────────

    /** @return array<string,string> field => value ('' when unknown) */
    public function mergeValues(Agency $agency, string $signatoryName, string $signatoryEmail): array
    {
        $timeline = AgencyTimeline::where('agency_id', $agency->id)->first();
        $live = $timeline ? $this->timelines->goLive($timeline) : null;
        $liveDate = $live ? ($live['expected'] ?? $live['planned']) : null;

        return [
            'agency_name'         => (string) $agency->name,
            'agency_trading_name' => (string) ($agency->trading_name ?: $agency->name),
            'agency_reg_no'       => (string) $agency->reg_no,
            'agency_vat_no'       => (string) $agency->vat_no,
            'agency_address'      => (string) $agency->address,
            'signatory_name'      => $signatoryName,
            'signatory_email'     => $signatoryEmail,
            'today'               => now()->format('j F Y'),
            'go_live_date'        => $liveDate ? $liveDate->format('j F Y') : '',
            'billing_start_date'  => $liveDate ? $liveDate->copy()->addDay()->format('j F Y') : '',
        ];
    }

    /**
     * Merge the template into frozen HTML. A field that is unknown OR has no value
     * is a refusal naming the field — a contract must never carry a blank.
     *
     * @throws \DomainException
     */
    public function renderBody(string $templateBody, array $values): string
    {
        $merged = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($values) {
            $key = $m[1];
            if (!in_array($key, self::MERGE_FIELDS, true)) {
                throw new \DomainException("The template uses an unknown field {{{$key}}}.");
            }
            if (trim((string) ($values[$key] ?? '')) === '') {
                throw new \DomainException("The field {{{$key}}} has no value for this agency. Fill it in on the agency (or start its timeline for go-live dates), or remove it from the template.");
            }

            return $values[$key];
        }, $templateBody);

        return PlainDocRenderer::render($merged);
    }

    // ── Send ───────────────────────────────────────────────────────────────

    /**
     * @param  UploadedFile[]  $attachments
     * @throws \DomainException  on an unmergeable template
     */
    public function createAndSend(Agency $agency, PlatformContractTemplate $template, array $data, array $attachments, ?int $userId): PlatformContractEnvelope
    {
        $values = $this->mergeValues($agency, $data['signatory_name'], $data['signatory_email']);
        $html = $this->renderBody($template->body, $values);

        $envelope = DB::transaction(function () use ($agency, $template, $data, $html, $userId, $attachments) {
            $env = PlatformContractEnvelope::create([
                'agency_id'          => $agency->id,
                'template_id'        => $template->id,
                'template_version'   => $template->version,
                'title'              => $data['title'] ?: $template->name,
                'body_html_snapshot' => $html,
                'signatory_name'     => $data['signatory_name'],
                'signatory_email'    => $data['signatory_email'],
                'signatory_role'     => $data['signatory_role'] ?: 'Principal',
                'token'              => Str::random(48),
                'token_expires_at'   => now()->addDays((int) ($data['expiry_days'] ?? 14))->endOfDay(),
                'status'             => 'sent',
                'sent_at'            => now(),
                'created_by'         => $userId,
            ]);
            $this->logEvent($env, 'created', 'Created from "' . $template->name . '" v' . $template->version, $userId);

            foreach ($attachments as $file) {
                $path = $file->storeAs('platform-contracts/' . $env->id, Str::random(16) . '.pdf', 'local');
                PlatformContractAttachment::create([
                    'envelope_id'   => $env->id,
                    'original_name' => $file->getClientOriginalName(),
                    'stored_path'   => $path,
                    'sha256'        => hash_file('sha256', $file->getRealPath()),
                ]);
            }

            return $env;
        });

        $this->mailInvite($envelope, $userId, 'sent');
        event(new AgencyContractSent($agency->id, $envelope->id, $userId));

        return $envelope;
    }

    /** New link + fresh expiry; the old link dies. */
    public function resend(PlatformContractEnvelope $env, ?int $userId, int $expiryDays = 14): void
    {
        if (!in_array($env->status, ['sent', 'viewed', 'expired'], true)) {
            throw new \DomainException('Only an unsigned, un-voided contract can be re-sent.');
        }
        $env->update([
            'token'            => Str::random(48),
            'token_expires_at' => now()->addDays($expiryDays)->endOfDay(),
            'status'           => 'sent',
            'sent_at'          => now(),
        ]);
        $this->mailInvite($env, $userId, 'resent');
    }

    public function void(PlatformContractEnvelope $env, string $reason, ?int $userId): void
    {
        if (in_array($env->status, ['signed', 'voided'], true)) {
            throw new \DomainException('A signed or already-voided contract cannot be voided.');
        }
        $env->update([
            'status' => 'voided', 'voided_at' => now(), 'voided_by' => $userId, 'void_reason' => $reason,
            'token' => Str::random(48), // the old link can never resolve again
        ]);
        $this->logEvent($env, 'voided', $reason, $userId);
    }

    private function mailInvite(PlatformContractEnvelope $env, ?int $userId, string $event): void
    {
        try {
            Mail::mailer('corex')->to($env->signatory_email)->send(new PlatformContractInviteMail($env));
            $this->logEvent($env, $event, 'Emailed to ' . $env->signatory_email, $userId);
        } catch (\Throwable $e) {
            Log::error('Platform contract invite email failed.', ['envelope_id' => $env->id, 'error' => $e->getMessage()]);
            $this->logEvent($env, 'email_failed', 'Email to ' . $env->signatory_email . ' failed — use Resend', $userId);
        }
    }

    // ── Signing (public) ───────────────────────────────────────────────────

    /** Resolve a token to an envelope; null when unknown. Marks lapsed links expired. */
    public function resolve(string $token): ?PlatformContractEnvelope
    {
        $env = PlatformContractEnvelope::where('token', $token)->first();
        if ($env && in_array($env->status, ['sent', 'viewed'], true) && $env->token_expires_at && $env->token_expires_at->isPast()) {
            $env->update(['status' => 'expired']);
        }

        return $env;
    }

    public function recordView(PlatformContractEnvelope $env, ?string $ip): void
    {
        if ($env->status === 'sent') {
            $env->update(['status' => 'viewed', 'first_viewed_at' => $env->first_viewed_at ?? now()]);
        }
        $this->logEvent($env, 'viewed', null, null, $ip);
    }

    /**
     * @throws \DomainException when the link cannot be signed (already used, lapsed, voided)
     */
    public function sign(string $token, string $typedName, ?string $signatureDataUri, ?string $ip, ?string $userAgent): PlatformContractEnvelope
    {
        $env = DB::transaction(function () use ($token, $typedName, $signatureDataUri, $ip, $userAgent) {
            // Row lock so a double-click / two tabs cannot sign twice.
            $env = PlatformContractEnvelope::where('token', $token)->lockForUpdate()->first();
            if (!$env || !$env->isSignable()) {
                throw new \DomainException('This signing link is no longer active.');
            }

            $env->fill([
                'status'                => 'signed',
                'signed_at'             => now(),
                'signed_typed_name'     => $typedName,
                'signature_image'       => $this->cleanSignature($signatureDataUri),
                'signed_ip'             => $ip,
                'signed_user_agent'     => Str::limit((string) $userAgent, 480, ''),
                'consent_text_snapshot' => self::CONSENT,
            ])->save();

            $pdf = $this->sealedPdf($env);
            $path = 'platform-contracts/' . $env->id . '/signed-' . $env->id . '.pdf';
            Storage::disk('local')->put($path, $pdf);
            $env->update(['sealed_pdf_path' => $path, 'document_hash' => hash('sha256', $pdf)]);
            $this->logEvent($env, 'signed', 'Signed by ' . $typedName, null, $ip);

            return $env;
        });

        $this->mailSignedCopy($env);
        event(new AgencyContractSigned($env->agency_id, $env->id, null));

        return $env;
    }

    public function decline(string $token, string $reason, ?string $ip): PlatformContractEnvelope
    {
        $env = DB::transaction(function () use ($token, $reason, $ip) {
            $env = PlatformContractEnvelope::where('token', $token)->lockForUpdate()->first();
            if (!$env || !$env->isSignable()) {
                throw new \DomainException('This signing link is no longer active.');
            }
            $env->update(['status' => 'declined', 'declined_at' => now(), 'decline_reason' => $reason]);
            $this->logEvent($env, 'declined', $reason, null, $ip);

            return $env;
        });
        event(new AgencyContractDeclined($env->agency_id, $env->id, null));

        return $env;
    }

    /** Accept only a real PNG data-URI of sane size; anything else is dropped (typed name still signs). */
    private function cleanSignature(?string $uri): ?string
    {
        if (!$uri || !str_starts_with($uri, 'data:image/png;base64,') || strlen($uri) > 400_000) {
            return null;
        }
        $bin = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);

        return ($bin !== false && @getimagesizefromstring($bin) !== false) ? $uri : null;
    }

    private function sealedPdf(PlatformContractEnvelope $env): string
    {
        return Pdf::loadView('admin.agency-contracts.pdf', [
            'env'     => $env->load('agency'),
            'consent' => self::CONSENT,
        ])->setPaper('a4')->output();
    }

    private function mailSignedCopy(PlatformContractEnvelope $env): void
    {
        $recipients = array_filter([$env->signatory_email]);
        $sender = $env->created_by ? \App\Models\User::withoutGlobalScopes()->where('id', $env->created_by)->value('email') : null;
        if ($sender) {
            $recipients[] = $sender;
        }
        foreach (array_unique($recipients) as $to) {
            try {
                Mail::mailer('corex')->to($to)->send(new PlatformContractSignedMail($env));
            } catch (\Throwable $e) {
                Log::error('Platform contract signed-copy email failed.', ['envelope_id' => $env->id, 'to' => $to, 'error' => $e->getMessage()]);
            }
        }
    }

    // ── Audit ──────────────────────────────────────────────────────────────

    public function logEvent(PlatformContractEnvelope $env, string $event, ?string $detail, ?int $userId = null, ?string $ip = null): void
    {
        PlatformContractEvent::create([
            'envelope_id' => $env->id, 'event' => $event,
            'detail' => $detail ? Str::limit($detail, 490, '') : null,
            'actor_user_id' => $userId, 'ip' => $ip,
        ]);
    }
}
