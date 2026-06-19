<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Events\SellerOutreach\PitchSent;
use App\Mail\SellerOutreach\OutreachEmail;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Support\SellerOutreach\OutreachContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Records a seller-outreach send.
 *
 * The actual WhatsApp / email delivery is done by the agent's own client
 * after the controller redirects them to the wa.me or mailto URL this
 * service helps build. The send-record creation captures full body /
 * facts / channel / recipient snapshots for PPRA defensibility.
 */
final class SellerOutreachSenderService
{
    private const SHORT_CODE_LENGTH = 6;
    /** Alphabet of 56 characters — excludes 0/O/1/I/l for human readability. */
    private const SHORT_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    private const MAX_GENERATION_ATTEMPTS = 10;

    /**
     * Self-service opt-out token length (AT-49). 48-char base62 from Str::random,
     * matching SnapshotLinkService — entropy far beyond enumeration. Unlike the
     * 6-char tracking code this is the credential for an irreversible action, so
     * it must be unguessable, not merely human-typable.
     */
    private const OPT_OUT_TOKEN_LENGTH = 48;

    public function send(OutreachContext $context): SellerOutreachSend
    {
        if (!$context->isSendable()) {
            $reasons = $context->validationIssues;
            if ($context->optOutBlocks) {
                $reasons['opt_out_blocks'] = 'Contact has messaging_opt_out_at set.';
            }
            throw new \DomainException(
                'Outreach context is not sendable: ' . json_encode($reasons)
            );
        }

        $shortCode = $this->generateUniqueShortCode($context->agencyId);
        $trackingUrl = $this->buildTrackingUrl($shortCode);

        // AT-49: per-send self-service opt-out token + public URL. Like the
        // tracking link, the composer leaves `{opt_out_link}` literal so the
        // agent sees the merge token; the real URL is substituted here at send
        // time and frozen into body_snapshot.
        $optOutToken = $this->generateUniqueOptOutToken();
        $optOutUrl = $this->buildOptOutUrl($optOutToken);
        // The opt-in link reuses the same per-send token — it resolves the same
        // send/contact; only the route (and the action) differs.
        $optInUrl = $this->buildOptInUrl($optOutToken);

        // Final substitution: the composer leaves `{tracking_link}` /
        // `{opt_out_link}` / `{opt_in_link}` literal in the body so the agent
        // can see/edit them. Replace them with the real URLs now, at send time.
        $search = ['{tracking_link}', '{opt_out_link}', '{opt_in_link}'];
        $replace = [$trackingUrl, $optOutUrl, $optInUrl];
        $finalBody = str_replace($search, $replace, $context->renderedBody);
        $finalSubject = $context->renderedSubject
            ? str_replace($search, $replace, $context->renderedSubject)
            : null;

        $factsSnapshot = $context->factsSnapshot;
        if (isset($factsSnapshot['merge_fields']) && is_array($factsSnapshot['merge_fields'])) {
            $factsSnapshot['merge_fields']['tracking_link'] = $trackingUrl;
            $factsSnapshot['merge_fields']['opt_out_link'] = $optOutUrl;
            $factsSnapshot['merge_fields']['opt_in_link'] = $optInUrl;
        }

        $send = DB::transaction(function () use ($context, $shortCode, $optOutToken, $finalBody, $finalSubject, $factsSnapshot) {
            return SellerOutreachSend::create([
                'agency_id' => $context->agencyId,
                'contact_id' => $context->contact->id,
                // AT-61 — null in address-only mode (no Property linked). The
                // address context is recorded below so the send log stays
                // complete and auditable without a Property row.
                'property_id' => $context->property?->id,
                'agent_id' => $context->agent->id,
                'template_id' => $context->template?->id,
                'channel' => $context->channel,
                'subject_snapshot' => $finalSubject,
                'body_snapshot' => $finalBody,
                'facts_snapshot' => $factsSnapshot,
                'tracking_short_code' => $shortCode,
                'opt_out_token' => $optOutToken,
                'recipient_phone_snapshot' => $context->recipientPhone,
                'recipient_email_snapshot' => $context->recipientEmail,
                // AT-61 — composed address + suburb the pitch referenced. Set in
                // every mode (property or address-only) so a send is always
                // self-describing even if the linked Property is later archived.
                'address_snapshot' => $context->address->displayAddress(),
                'suburb_snapshot' => $context->address->suburb,
                'sent_at' => now(),
                'outcome' => SellerOutreachSend::OUTCOME_SENT,
            ]);
        });

        event(new PitchSent(
            send: $send,
            actorUserId: $context->agent->id,
            agencyId: $context->agencyId,
        ));

        // EMAIL channel — the system sends the branded HTML email (reusing the
        // e-sign signature wrapper). WhatsApp stays click-to-chat (wa.me). The
        // body already has the per-send links substituted ($finalBody). A mail
        // failure must NOT roll back the recorded send (PPRA evidence) — log it.
        if ($context->channel === 'email' && $context->recipientEmail) {
            try {
                Mail::to($context->recipientEmail)->send(new OutreachEmail(
                    recipientName: $context->contact->first_name ?: 'there',
                    emailSubject:  (string) ($finalSubject ?? ''),
                    body:          $finalBody,
                    agent:         $context->agent,
                ));
            } catch (\Throwable $e) {
                Log::error('SellerOutreach email send failed', [
                    'send_id'   => $send->id,
                    'agency_id' => $context->agencyId,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return $send;
    }

    public function whatsappUrl(SellerOutreachSend $send): string
    {
        if (!$send->recipient_phone_snapshot) {
            throw new \DomainException('Send has no recipient phone — cannot build WhatsApp URL.');
        }
        $text = rawurlencode((string) $send->body_snapshot);
        $phone = $send->recipient_phone_snapshot;

        // 2026-05-14 hotfix: agency picks `whatsapp_app` (direct deeplink — no
        // intermediate page) or `whatsapp_web` (wa.me universal-fallback URL).
        $mode = $this->resolveAgencyWhatsappMode((int) $send->agency_id, 'agent');

        return $mode === \App\Models\Agency::WHATSAPP_LAUNCH_APP
            ? "whatsapp://send?phone={$phone}&text={$text}"
            : "https://wa.me/{$phone}?text={$text}";
    }

    /**
     * Per-request cached lookup of the agency's WhatsApp launch mode.
     * Falls back to WHATSAPP_LAUNCH_WEB for any unknown value (defense in
     * depth against typos / corrupted DB values).
     */
    private function resolveAgencyWhatsappMode(int $agencyId, string $side): string
    {
        static $cache = [];
        $key = "{$agencyId}:{$side}";
        if (isset($cache[$key])) {
            return $cache[$key];
        }
        $column = $side === 'agent' ? 'whatsapp_launch_mode_agent' : 'whatsapp_launch_mode_seller';
        $value = \Illuminate\Support\Facades\DB::table('agencies')
            ->where('id', $agencyId)
            ->value($column);
        $cache[$key] = in_array($value, [
            \App\Models\Agency::WHATSAPP_LAUNCH_APP,
            \App\Models\Agency::WHATSAPP_LAUNCH_WEB,
        ], true) ? (string) $value : \App\Models\Agency::WHATSAPP_LAUNCH_WEB;
        return $cache[$key];
    }

    public function mailtoUrl(SellerOutreachSend $send): string
    {
        if (!$send->recipient_email_snapshot) {
            throw new \DomainException('Send has no recipient email — cannot build mailto URL.');
        }
        $params = [
            'subject' => (string) ($send->subject_snapshot ?? ''),
            'body' => (string) $send->body_snapshot,
        ];
        return 'mailto:' . $send->recipient_email_snapshot . '?' . http_build_query($params);
    }

    private function buildTrackingUrl(string $shortCode): string
    {
        return rtrim((string) config('app.url'), '/') . '/m/' . $shortCode;
    }

    private function buildOptOutUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/') . '/outreach/opt-out/' . $token;
    }

    private function buildOptInUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/') . '/outreach/opt-in/' . $token;
    }

    /**
     * 48-char base62 token, globally unique (the public opt-out route resolves
     * by token alone, with no agency in the URL). Collision at this length is
     * astronomically unlikely; the retry loop + the column's UNIQUE index are
     * belt-and-braces.
     */
    private function generateUniqueOptOutToken(): string
    {
        for ($attempt = 0; $attempt < self::MAX_GENERATION_ATTEMPTS; $attempt++) {
            $token = Str::random(self::OPT_OUT_TOKEN_LENGTH);
            $exists = SellerOutreachSend::withoutGlobalScopes()
                ->withTrashed()
                ->where('opt_out_token', $token)
                ->exists();
            if (!$exists) {
                return $token;
            }
        }
        throw new \RuntimeException('Could not generate a unique opt-out token after ' . self::MAX_GENERATION_ATTEMPTS . ' attempts.');
    }

    private function generateUniqueShortCode(int $agencyId): string
    {
        for ($attempt = 0; $attempt < self::MAX_GENERATION_ATTEMPTS; $attempt++) {
            $code = $this->randomShortCode();
            $exists = SellerOutreachSend::withoutGlobalScopes()
                ->withTrashed()
                ->where('agency_id', $agencyId)
                ->where('tracking_short_code', $code)
                ->exists();
            if (!$exists) {
                return $code;
            }
        }
        throw new \RuntimeException('Could not generate a unique short code after ' . self::MAX_GENERATION_ATTEMPTS . ' attempts.');
    }

    private function randomShortCode(): string
    {
        $alphabet = self::SHORT_CODE_ALPHABET;
        $length = self::SHORT_CODE_LENGTH;
        $max = strlen($alphabet) - 1;
        $result = '';
        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }
        return $result;
    }
}
