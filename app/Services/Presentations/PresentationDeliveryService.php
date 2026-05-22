<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Mail\Presentations\SendPresentationEmail;
use App\Models\Agency;
use App\Models\Presentation;
use App\Models\PresentationDelivery;
use App\Models\PresentationSnapshotLink;
use App\Models\User;
use App\Notifications\Presentations\PresentationDeliveryFailedNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Phase 6 Part B — multi-recipient send orchestration.
 *
 *   prepareDeliveryBatch() → DeliveryBatch DTO with per-recipient preview
 *   sendBatch()            → creates snapshot links + delivery rows + dispatches
 *
 * Idempotency: a (presentation_id, recipient_contact_id-or-email, sender)
 * tuple within 60s reuses the existing delivery instead of creating a new one.
 *
 * Sticky defaults: after every successful batch we stamp the sender's
 * users.last_presentation_send_channel + _mode for next-modal-open recall.
 */
final class PresentationDeliveryService
{
    private const IDEMPOTENCY_WINDOW_SECONDS = 60;

    public function __construct(
        private readonly SnapshotLinkService $linkService = new SnapshotLinkService(),
    ) {}

    /**
     * Build a fully-rendered preview batch. No DB writes.
     *
     * @param array<int, array{
     *   contact_id?: ?int, name?: string, first_name?: string,
     *   email?: ?string, phone?: ?string,
     *   mode?: string, channel?: string,
     * }> $recipients
     * @param array{
     *   default_mode?: string, default_channel?: string,
     *   subject?: ?string, body?: ?string,
     *   expires_at?: \DateTimeInterface|string|null,
     *   created_by_user_id: int,
     * } $options
     */
    public function prepareDeliveryBatch(Presentation $presentation, array $recipients, array $options): DeliveryBatch
    {
        if (empty($options['created_by_user_id'])) {
            throw new \InvalidArgumentException('created_by_user_id is required.');
        }
        if (count($recipients) === 0) {
            throw new \InvalidArgumentException('At least one recipient is required.');
        }

        $agency       = Agency::find($presentation->agency_id);
        $sender       = User::find($options['created_by_user_id']);
        $defaultMode  = $options['default_mode']    ?? 'full';
        $defaultCh    = $options['default_channel'] ?? 'email';
        $subjectTpl   = ($options['subject'] ?? null) ?: ($agency?->email_default_subject_template ?? 'Your property market analysis — {property_address}');
        $bodyTpl      = ($options['body']    ?? null) ?: ($agency?->email_default_body_template    ?? "Hi {recipient_first_name},\n\nView your property analysis: {presentation_url}");
        $waTpl        = ($options['body']    ?? null) ?: ($agency?->whatsapp_default_template      ?? 'Hi {recipient_first_name}, your property analysis: {presentation_url}');

        $rendered = [];
        foreach ($recipients as $r) {
            $mode    = in_array($r['mode'] ?? '', ['full', 'teaser'], true) ? $r['mode'] : $defaultMode;
            $channel = in_array($r['channel'] ?? '', ['email', 'whatsapp', 'copy', 'sms'], true) ? $r['channel'] : $defaultCh;
            $name    = trim((string) ($r['name'] ?? ''));
            $first   = $r['first_name'] ?? (explode(' ', $name, 2)[0] ?: $name);
            $email   = trim((string) ($r['email'] ?? '')) ?: null;
            $phone   = trim((string) ($r['phone'] ?? '')) ?: null;

            $validation = $this->validateRecipient($name, $email, $phone, $channel);

            // Placeholder context — presentation_url is a PREVIEW value
            // because the real link only exists after sendBatch().
            $context = [
                'recipient_first_name' => $first,
                'recipient_name'       => $name,
                'property_address'     => $presentation->property_address ?: 'your property',
                'agent_name'           => $sender?->name ?? '',
                'agency_name'          => $agency?->name ?? 'CoreX OS',
                'presentation_url'     => '[link will be generated when you click send]',
            ];

            $rendered[] = [
                'contact_id'        => $r['contact_id'] ?? null,
                'name'              => $name,
                'first_name'        => $first,
                'email'             => $email,
                'phone'             => $phone,
                'channel'           => $channel,
                'mode'              => $mode,
                'subject'           => $channel === 'email' ? $this->renderTemplate($subjectTpl, $context) : null,
                'body'              => $channel === 'whatsapp'
                    ? $this->renderTemplate($waTpl,   $context)
                    : $this->renderTemplate($bodyTpl, $context),
                'validation_error'  => $validation,
            ];
        }

        return new DeliveryBatch(
            presentation:    $presentation,
            recipients:      $rendered,
            createdByUserId: (int) $options['created_by_user_id'],
            expiresAt:       isset($options['expires_at']) && $options['expires_at'] ? Carbon::parse($options['expires_at']) : null,
        );
    }

    /**
     * Execute a prepared batch. Creates one snapshot link per recipient,
     * one delivery row, and dispatches the appropriate channel handler.
     *
     * @return array<int, array{
     *   recipient: string, channel: string, mode: string,
     *   delivery_id: int, snapshot_link_id: int, snapshot_url: string,
     *   status: string, error: ?string, whatsapp_url: ?string,
     * }>
     */
    public function sendBatch(DeliveryBatch $batch, User $by): array
    {
        if (!$batch->isValid()) {
            $errors = $batch->validationErrors();
            throw new \InvalidArgumentException('Batch has validation errors: ' . json_encode($errors));
        }

        $results = [];
        $channelsUsed = [];
        $modesUsed    = [];

        foreach ($batch->recipients as $r) {
            try {
                $result = $this->sendOne($batch->presentation, $r, $batch->createdByUserId, $batch->expiresAt);
                $results[] = $result;
                $channelsUsed[] = $r['channel'];
                $modesUsed[]    = $r['mode'];
            } catch (\Throwable $e) {
                Log::error('PresentationDeliveryService::sendBatch — sendOne failed', [
                    'presentation_id' => $batch->presentation->id,
                    'recipient'       => $r['name'],
                    'err'             => $e->getMessage(),
                ]);
                $results[] = [
                    'recipient'        => $r['name'],
                    'channel'          => $r['channel'],
                    'mode'             => $r['mode'],
                    'delivery_id'      => 0,
                    'snapshot_link_id' => 0,
                    'snapshot_url'     => '',
                    'status'           => 'failed',
                    'error'            => $e->getMessage(),
                    'whatsapp_url'     => null,
                ];
            }
        }

        // Sticky defaults — pick the most-used channel + mode in the batch.
        $stickyChannel = $this->mostCommon($channelsUsed);
        $stickyMode    = $this->mostCommon($modesUsed);
        if ($stickyChannel || $stickyMode) {
            $by->forceFill(array_filter([
                'last_presentation_send_channel' => $stickyChannel,
                'last_presentation_send_mode'    => $stickyMode,
            ]))->save();
        }

        return $results;
    }

    /**
     * Public helper for the modal Step 2 — render an editable template
     * for one recipient (so the agent sees the substituted text live).
     *
     * @param array<string, string> $context
     */
    public function renderTemplate(string $template, array $context): string
    {
        $out = $template;
        foreach ($context as $key => $value) {
            $out = str_replace('{' . $key . '}', (string) $value, $out);
        }
        return $out;
    }

    /**
     * Build the wa.me URL for a SA phone + URL-encoded body.
     * 082 123 4567 → 27821234567. +27821234567 → 27821234567.
     */
    public function whatsappUrl(string $phone, string $body): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        // Strip leading 27 if present, then re-add. Strip leading 0 if next.
        if (str_starts_with($digits, '27')) {
            $digits = '27' . preg_replace('/^0+/', '', substr($digits, 2));
        } elseif (str_starts_with($digits, '0')) {
            $digits = '27' . preg_replace('/^0+/', '', $digits);
        } else {
            // Bare 9 digits assume SA, prepend 27.
            if (strlen($digits) === 9) $digits = '27' . $digits;
        }
        return 'https://wa.me/' . $digits . '?text=' . rawurlencode($body);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * @param array $r  rendered recipient row from the batch
     * @return array<string, mixed>
     */
    private function sendOne(Presentation $presentation, array $r, int $createdByUserId, ?\DateTimeInterface $expiresAt): array
    {
        // Idempotency check first.
        $existing = $this->findRecentDelivery($presentation, $r, $createdByUserId);
        if ($existing) {
            return [
                'recipient'        => $r['name'],
                'channel'          => $existing->channel,
                'mode'             => $existing->mode,
                'delivery_id'      => $existing->id,
                'snapshot_link_id' => $existing->snapshot_link_id,
                'snapshot_url'     => $this->linkService->publicUrl($existing->link->token),
                'status'           => $existing->status,
                'error'            => null,
                'whatsapp_url'     => $existing->whatsapp_url,
            ];
        }

        // Create snapshot link + delivery row in one transaction.
        $delivery = DB::transaction(function () use ($presentation, $r, $createdByUserId, $expiresAt) {
            $link = $this->linkService->createLink($presentation, [
                'mode'                 => $r['mode'],
                'recipient_contact_id' => $r['contact_id'] ?? null,
                'recipient_label'      => $r['name'],
                'expires_at'           => $expiresAt,
                'created_by_user_id'   => $createdByUserId,
            ]);

            // Now that we have the real link, fill in {presentation_url}.
            $publicUrl = $this->linkService->publicUrl($link->token);
            $body      = str_replace('[link will be generated when you click send]', $publicUrl, (string) $r['body']);
            $subject   = $r['subject'] !== null
                ? str_replace('[link will be generated when you click send]', $publicUrl, (string) $r['subject'])
                : null;

            $whatsappUrl = $r['channel'] === 'whatsapp' && !empty($r['phone'])
                ? $this->whatsappUrl($r['phone'], $body)
                : null;

            return PresentationDelivery::create([
                'snapshot_link_id'     => $link->id,
                'presentation_id'      => $presentation->id,
                'agency_id'            => $presentation->agency_id,
                'sent_by_user_id'      => $createdByUserId,
                'channel'              => $r['channel'],
                'recipient_contact_id' => $r['contact_id'] ?? null,
                'recipient_name'       => $r['name'],
                'recipient_email'      => $r['email'] ?? null,
                'recipient_phone'      => $r['phone'] ?? null,
                'mode'                 => $r['mode'],
                'status'               => PresentationDelivery::STATUS_QUEUED,
                'whatsapp_url'         => $whatsappUrl,
                'subject_line'         => $subject,
                'message_body'         => $body,
            ]);
        });

        // Channel branch.
        $status = $this->dispatchByChannel($delivery);
        $delivery->refresh();

        return [
            'recipient'        => $r['name'],
            'channel'          => $delivery->channel,
            'mode'             => $delivery->mode,
            'delivery_id'      => $delivery->id,
            'snapshot_link_id' => $delivery->snapshot_link_id,
            'snapshot_url'     => $this->linkService->publicUrl($delivery->link->token),
            'status'           => $delivery->status,
            'error'            => $delivery->error_message,
            'whatsapp_url'     => $delivery->whatsapp_url,
        ];
    }

    private function dispatchByChannel(PresentationDelivery $delivery): string
    {
        switch ($delivery->channel) {
            case PresentationDelivery::CHANNEL_EMAIL:
                if (empty($delivery->recipient_email)) {
                    $delivery->forceFill([
                        'status'        => PresentationDelivery::STATUS_FAILED,
                        'error_message' => 'No email address for recipient',
                    ])->save();
                    return PresentationDelivery::STATUS_FAILED;
                }
                try {
                    Mail::to($delivery->recipient_email)->queue(new SendPresentationEmail($delivery));
                    $delivery->forceFill([
                        'status'  => PresentationDelivery::STATUS_SENT,
                        'sent_at' => now(),
                    ])->save();
                    return PresentationDelivery::STATUS_SENT;
                } catch (\Throwable $e) {
                    $delivery->forceFill([
                        'status'        => PresentationDelivery::STATUS_FAILED,
                        'error_message' => $e->getMessage(),
                    ])->save();
                    $this->notifyFailure($delivery);
                    return PresentationDelivery::STATUS_FAILED;
                }

            case PresentationDelivery::CHANNEL_COPY:
                // No-op — agent will copy the URL.
                $delivery->forceFill([
                    'status'  => PresentationDelivery::STATUS_SENT,
                    'sent_at' => now(),
                ])->save();
                return PresentationDelivery::STATUS_SENT;

            case PresentationDelivery::CHANNEL_WHATSAPP:
                // Status stays 'queued' until agent click-through (handled
                // by recordWhatsappClickThrough below).
                return PresentationDelivery::STATUS_QUEUED;

            default:
                return $delivery->status;
        }
    }

    /**
     * Find a delivery to the same recipient created in the last 60s by the
     * same sender for the same presentation. Used to short-circuit double
     * submissions of the modal.
     */
    private function findRecentDelivery(Presentation $presentation, array $r, int $createdByUserId): ?PresentationDelivery
    {
        $cutoff = now()->subSeconds(self::IDEMPOTENCY_WINDOW_SECONDS);
        $q = PresentationDelivery::with('link')
            ->where('presentation_id', $presentation->id)
            ->where('sent_by_user_id', $createdByUserId)
            ->where('created_at', '>=', $cutoff);

        if (!empty($r['contact_id'])) {
            $q->where('recipient_contact_id', $r['contact_id']);
        } elseif (!empty($r['email'])) {
            $q->whereRaw('LOWER(recipient_email) = ?', [mb_strtolower($r['email'])]);
        } elseif (!empty($r['phone'])) {
            $q->where('recipient_phone', $r['phone']);
        } else {
            return null;
        }
        return $q->latest('id')->first();
    }

    public function recordWhatsappClickThrough(PresentationDelivery $delivery): void
    {
        $delivery->forceFill([
            'whatsapp_click_through_at' => now(),
            'status'                    => PresentationDelivery::STATUS_SENT,
            'sent_at'                   => $delivery->sent_at ?: now(),
        ])->save();
    }

    private function validateRecipient(string $name, ?string $email, ?string $phone, string $channel): ?string
    {
        if ($name === '' || strlen($name) < 2) return 'name required';
        if ($email === null && $phone === null) return 'email or phone required';
        if ($channel === 'email' && empty($email)) return 'email required for email channel';
        if ($channel === 'whatsapp' && empty($phone)) return 'phone required for whatsapp channel';
        return null;
    }

    private function notifyFailure(PresentationDelivery $delivery): void
    {
        try {
            $delivery->sender?->notify(new PresentationDeliveryFailedNotification($delivery->id));
        } catch (\Throwable $e) {
            Log::warning('Delivery failure notification dispatch failed', ['err' => $e->getMessage()]);
        }
    }

    /** @param array<int, string> $values */
    private function mostCommon(array $values): ?string
    {
        if (empty($values)) return null;
        $counts = array_count_values(array_filter($values));
        if (empty($counts)) return null;
        arsort($counts);
        return array_key_first($counts);
    }
}
