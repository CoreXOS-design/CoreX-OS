<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Events\SellerOutreach\PitchSent;
use App\Models\SellerOutreach\SellerOutreachSend;
use App\Support\SellerOutreach\OutreachContext;
use Illuminate\Support\Facades\DB;

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

    public function send(OutreachContext $context): SellerOutreachSend
    {
        if (!$context->isSendable()) {
            $reasons = $context->validationIssues;
            if ($context->optOutBlocks) {
                $reasons['opt_out_blocks'] = 'Contact opted out of ' . ($context->optOutReason ?? 'messaging') . '.';
            }
            throw new \DomainException(
                'Outreach context is not sendable: ' . json_encode($reasons)
            );
        }

        $shortCode = $this->generateUniqueShortCode($context->agencyId);
        $trackingUrl = $this->buildTrackingUrl($shortCode);

        // Final substitution: the composer leaves `{tracking_link}` literal
        // in the body so the agent can see/edit it. Replace it with the real
        // URL now, at send time.
        $finalBody = str_replace('{tracking_link}', $trackingUrl, $context->renderedBody);
        $finalSubject = $context->renderedSubject
            ? str_replace('{tracking_link}', $trackingUrl, $context->renderedSubject)
            : null;

        $factsSnapshot = $context->factsSnapshot;
        if (isset($factsSnapshot['merge_fields']) && is_array($factsSnapshot['merge_fields'])) {
            $factsSnapshot['merge_fields']['tracking_link'] = $trackingUrl;
        }

        $send = DB::transaction(function () use ($context, $shortCode, $finalBody, $finalSubject, $factsSnapshot) {
            return SellerOutreachSend::create([
                'agency_id' => $context->agencyId,
                'contact_id' => $context->contact->id,
                'property_id' => $context->property->id,
                'agent_id' => $context->agent->id,
                'template_id' => $context->template?->id,
                'channel' => $context->channel,
                'subject_snapshot' => $finalSubject,
                'body_snapshot' => $finalBody,
                'facts_snapshot' => $factsSnapshot,
                'tracking_short_code' => $shortCode,
                'recipient_phone_snapshot' => $context->recipientPhone,
                'recipient_email_snapshot' => $context->recipientEmail,
                'sent_at' => now(),
                'outcome' => SellerOutreachSend::OUTCOME_SENT,
            ]);
        });

        event(new PitchSent(
            send: $send,
            actorUserId: $context->agent->id,
            agencyId: $context->agencyId,
        ));

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
