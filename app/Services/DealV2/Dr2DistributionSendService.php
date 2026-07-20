<?php

namespace App\Services\DealV2;

use App\Mail\DealV2\DealPackMail;
use App\Models\Communications\Communication;
use App\Models\Deal;
use App\Models\DealV2\DealDocumentDistribution;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\Property;
use App\Models\User;
use App\Services\Communications\OutboundProvisionalLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * AT-228 — executes a party document distribution the agent authorised in compose-and-review.
 *
 * One "Send" = one group_key. It delivers the chosen (already-filed) documents to ONE party
 * recipient via one delivery_mode × channel, carrying the agent's message. Over the agency's
 * size limit, a direct-attachment send auto-splits into "(Part N of M)" emails — a document is
 * NEVER split across parts. Every send writes distribution rows + a 3-pillar comms record
 * (deal + property + recipient). Documents are the ones already filed on the deal — nothing is
 * re-filed, so there is no duplicate filing.
 */
class Dr2DistributionSendService
{
    public function __construct(
        private OutboundProvisionalLogger $comms,
        private Dr2DistributionComposer $composer,
    ) {}

    /**
     * @param array $recipient  [type, id, contact_id?, name, email, phone]
     * @param int[] $documentIds  the docs the agent confirmed (subset of the deal corpus)
     * @return array{group_key:string,rows:int,parts:int,channel:string,delivery_mode:string,delivered:bool}
     * @throws \DomainException on an unusable send (no twin, no recipient address, no docs)
     */
    public function sendToParty(
        Deal $deal,
        string $role,
        array $recipient,
        array $documentIds,
        string $mode,
        string $channel,
        ?string $message,
        User $actor,
        array $cc = [],
    ): array {
        if (! $deal->deal_v2_id) {
            // AT-245 — mint the twin on demand rather than dead-end the send. Only
            // a deal with no listing agent (nothing to satisfy the twin) can't be sent.
            app(DealSyncService::class)->ensureTwin($deal);
            if (! $deal->deal_v2_id) {
                throw new \DomainException('This deal has no DR2 record and no listing agent to create one — assign an agent to the deal, then send.');
            }
        }

        $email = trim((string) ($recipient['email'] ?? ''));
        $phone = trim((string) ($recipient['phone'] ?? ''));
        if ($channel === DealDocumentDistribution::CHANNEL_EMAIL && $email === '') {
            throw new \DomainException('No email address on file for this recipient — add one or send by WhatsApp.');
        }
        if ($channel === DealDocumentDistribution::CHANNEL_WHATSAPP && $phone === '' && $email === '') {
            throw new \DomainException('No phone or email on file for this recipient.');
        }

        // Only documents actually on this deal's 3-pillar corpus may be sent (never arbitrary ids).
        $corpus = $this->composer->documentCorpus($deal)->keyBy('id');
        $docs   = collect($documentIds)->map(fn ($id) => $corpus->get((int) $id))->filter()->values();
        if ($docs->isEmpty()) {
            throw new \DomainException('Select at least one document to send.');
        }

        // WhatsApp cannot carry attachments via the (staging-safe) deeplink idiom → force secure links.
        if ($channel === DealDocumentDistribution::CHANNEL_WHATSAPP) {
            $mode = DealDocumentDistribution::MODE_SECURE_LINK;
        }

        $groupKey  = Str::random(32);
        $twin      = DealV2::withoutGlobalScopes()->find($deal->deal_v2_id);
        $property  = $deal->property;
        $reference = $deal->deal_no ?: ('#' . $deal->id);
        $address   = $property?->buildDisplayAddress() ?: ($deal->property_address ?: '');

        // AT-231 P1 — stamp a machine-resolvable deal reference so an attorney's
        // reply files itself back onto THIS deal hands-free (the inbound half of
        // the loop). The token encodes the immutable deals.id (deal_no is a
        // reassignable human label); regex /\[CX-D(\d+)\]/ recovers it on the way
        // back. See .ai/specs/at231-inbound-attorney-comms-filing.md §3.1.
        $dealToken  = '[CX-D' . $deal->id . ']';
        $mailDomain = strtolower(Str::after((string) config('mail.from.address', ''), '@'));
        if ($mailDomain === '') {
            $mailDomain = 'corexos.co.za';
        }

        if ($mode === DealDocumentDistribution::MODE_DIRECT_ATTACHMENT) {
            $parts = $this->splitBySize($docs, $this->composer->sizeLimitBytes((int) $deal->agency_id));
        } else {
            $parts = [$docs];   // secure links: one email, no size concern
        }
        $partOf = count($parts);

        $rows = 0; $delivered = false;
        foreach ($parts as $i => $partDocs) {
            $partNo    = $i + 1;
            $partLabel = $partOf > 1 ? "Part {$partNo} of {$partOf}" : null;

            // AT-231 P1 — a known, bracketless Message-ID per email part. Persisted as
            // the outbound comm's thread_key so a reply's References/In-Reply-To resolves
            // to this deal. Email only (WA replies come via WAHA, not email threading).
            $messageId = $channel === DealDocumentDistribution::CHANNEL_EMAIL
                ? 'cx-d' . $deal->id . '.' . strtolower($groupKey) . '.p' . $partNo . '@' . $mailDomain
                : null;

            // Subject carries the human reference, the optional part label, and the
            // machine token — one string, reused by the email and the comms record.
            $subject = 'Documents — ' . $reference . ($partLabel ? " ({$partLabel})" : '') . ' ' . $dealToken;

            // Build per-doc distribution rows + the delivery payload for this part.
            $secureLinks = [];
            $attachments = [];
            $partRows    = [];
            foreach ($partDocs as $doc) {
                $isSecure = $mode === DealDocumentDistribution::MODE_SECURE_LINK;
                $token    = $isSecure ? $this->uniqueToken() : null;

                $dist = DealDocumentDistribution::create([
                    'agency_id'            => $deal->agency_id,   // explicit stamp (AT-203)
                    'deal_id'              => $deal->deal_v2_id,  // FK deals_v2 twin
                    'document_id'          => $doc->id,
                    'party_role'           => $role,
                    // recipient_contact_id FKs to `contacts` — only a real CoreX contact goes here.
                    // A provider recipient is tracked by recipient_provider_id (its firm); its
                    // service-provider-contact id is NOT a contacts.id.
                    'recipient_contact_id'  => ($recipient['type'] ?? null) === 'contact' ? ($recipient['id'] ?? null) : null,
                    'recipient_provider_id' => ($recipient['type'] ?? null) === 'provider' ? ($recipient['id'] ?? null) : null,
                    'recipient_email'      => $email ?: null,
                    'delivery_mode'        => $mode,
                    'channel'              => $channel,
                    'group_key'            => $groupKey,
                    'part_no'              => $partNo,
                    'part_of'              => $partOf,
                    'secure_token'         => $token,
                    'otp_required'         => $isSecure,
                    'status'               => DealDocumentDistribution::STATUS_QUEUED,
                    'sent_by_id'           => $actor->id,
                ]);
                $partRows[] = $dist;
                $rows++;

                if ($isSecure) {
                    // AT-264 — one pack link (built once, after the loop) unlocks the
                    // whole group with one OTP; no per-document link here anymore.
                } else {
                    $abs = Storage::disk($doc->disk ?? 'local')->path($doc->storage_path);
                    $attachments[] = [
                        'path'         => $abs,
                        'filename'     => $doc->original_name,
                        'storage_path' => $doc->storage_path,
                        'disk'         => $doc->disk ?? 'local',
                        'mime'         => $doc->mime_type ?? 'application/pdf',
                        'size'         => (int) ($doc->size ?? 0),
                    ];
                }
            }

            // AT-264 — a secure-link send emits ONE pack link (keyed on the group_key)
            // that unlocks every document in the group behind a single OTP, instead of
            // one link + one PIN per document. The mail view loops $secureLinks, so a
            // single entry renders as one link.
            if ($mode === DealDocumentDistribution::MODE_SECURE_LINK && ! empty($partRows)) {
                $count = count($partRows);
                $secureLinks = [[
                    'title' => "View {$count} secure " . ($count === 1 ? 'document' : 'documents'),
                    'url'   => route('deals-v2.secure-doc.pack', ['groupKey' => $groupKey]),
                ]];
            }

            // Deliver (email real via Mailer; WhatsApp = log-only deeplink idiom on staging).
            $partDelivered = $this->deliver($channel, $email, $actor, $recipient['name'] ?? '', $reference, $address, (string) $message, $attachments, $secureLinks, $partLabel, $dealToken, $messageId, $cc);
            $delivered = $delivered || $partDelivered;

            // 3-pillar comms — deal (twin) + property + recipient contact (resolved by id, form-safe).
            $links = array_values(array_filter([$twin, $property instanceof Property ? $property : null]));
            if (($recipient['type'] ?? null) === 'contact' && ! empty($recipient['id'])) {
                $c = \App\Models\Contact::withoutGlobalScopes()->find((int) $recipient['id']);
                if ($c) {
                    $links[] = $c;
                }
            }
            $comm = $this->comms->logDistribution(
                (int) $deal->agency_id,
                $actor->id,
                $email ?: ($phone ?: 'whatsapp'),
                $subject,
                (string) $message,
                $links,
                $attachments,
                $channel,
                $messageId,   // AT-231 P1 — thread_key (email only; null for WA)
            );

            // Anchor each row to the comms record + mark sent.
            foreach ($partRows as $dist) {
                $dist->update([
                    'communication_id' => $comm->id,
                    'status'           => $partDelivered ? DealDocumentDistribution::STATUS_SENT : DealDocumentDistribution::STATUS_DELIVERED_FAILED,
                    'sent_at'          => now(),
                ]);
            }
        }

        return [
            'group_key'     => $groupKey,
            'rows'          => $rows,
            'parts'         => $partOf,
            'channel'       => $channel,
            'delivery_mode' => $mode,
            'delivered'     => $delivered,
        ];
    }

    /** Greedy fill under the size limit; a single over-limit document gets its own part (never split). */
    public function splitBySize(Collection $docs, int $limitBytes): array
    {
        $parts = []; $current = collect(); $currentSize = 0;
        foreach ($docs->sortByDesc(fn ($d) => (int) ($d->size ?? 0))->values() as $doc) {
            $size = (int) ($doc->size ?? 0);
            if ($size > $limitBytes) {                       // too big for any pack — its own email
                if ($current->isNotEmpty()) { $parts[] = $current; $current = collect(); $currentSize = 0; }
                $parts[] = collect([$doc]);
                continue;
            }
            if ($currentSize + $size > $limitBytes && $current->isNotEmpty()) {
                $parts[] = $current; $current = collect(); $currentSize = 0;
            }
            $current->push($doc); $currentSize += $size;
        }
        if ($current->isNotEmpty()) { $parts[] = $current; }
        return $parts ?: [collect()];
    }

    /** Actually deliver a part. Email → Mailer; WhatsApp → deeplink+provisional-log (no real dispatch on staging). */
    private function deliver(string $channel, string $email, User $actor, string $name, string $ref, string $address, string $message, array $attachments, array $secureLinks, ?string $partLabel, string $dealToken = '', ?string $messageId = null, array $cc = []): bool
    {
        if ($channel === DealDocumentDistribution::CHANNEL_WHATSAPP) {
            // The message + secure links go to the recipient over WhatsApp. On staging (WAHA not
            // linked) we do NOT dispatch — the comms row IS the record (deeplink idiom, AT-149/outreach).
            Log::info('AT-228 WhatsApp distribution logged (no server dispatch): ' . $ref . ' → ' . ($email ?: 'wa'));
            return true;
        }
        try {
            $files = array_map(fn ($a) => ['path' => $a['path'], 'filename' => $a['filename']], $attachments);
            // AT-229 COC — optional CC (listing/selling agents), already de-duped by the caller
            // and stripped of the primary address; cc([]) is a no-op.
            $mailer = Mail::to($email);
            if (! empty($cc)) {
                $mailer->cc($cc);
            }
            $mailer->send(
                (new DealPackMail($name ?: 'there', $ref, $address, $message, $files, $secureLinks, $partLabel, $dealToken, $messageId))->fromAgent($actor)
            );
            return true;
        } catch (\Throwable $e) {
            Log::warning('AT-228 email distribution failed for ' . $ref . ': ' . $e->getMessage());
            return false;
        }
    }

    private function uniqueToken(): string
    {
        do {
            $token = Str::random(40);
        } while (DealDocumentDistribution::withoutGlobalScopes()->where('secure_token', $token)->exists());
        return $token;
    }
}
