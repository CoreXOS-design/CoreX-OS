<?php

namespace App\Services\Rentals;

use App\Exceptions\RentalInspectionSigningLinkException as LinkException;
use App\Mail\Rentals\RentalInspectionSigningLinkMail;
use App\Models\Contact;
use App\Models\RentalInspection;
use App\Models\RentalInspectionAuditLog;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\RentalInspectionSigningLink;
use App\Models\User;
use App\Services\Distribution\SignedDocumentDistributionService;
use App\Support\WhatsAppNumberFormatter;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * .ai/specs/rental-inspections.md §46 — the ONE place a personal signing link is issued, sent, revoked, opened and
 * signed through. The agent's screens, the public signing page and the "sign on this device" page all go through here,
 * so the rules (who may sign, when, how it is recorded) exist once.
 *
 * Signing itself is never reimplemented: a tenant's or landlord's signature (or refusal) goes through
 * RentalInspectionSignature::capture(), the same method the agent's recording screen uses, so every invariant that
 * method enforces (one disposition per party, a real PNG, not after completion) holds for a link signature too.
 */
class RentalInspectionSigningLinkService
{
    // ═══ Who the parties are ═══════════════════════════════════════════════════

    /**
     * Every party of this inspection who can hold a signing link: each tenant on the lease, the landlord who signs
     * (Property::sellerOwnerContact(), exactly who capture() accepts), and the agent (the inspector, else the creator).
     *
     * @return Collection<int, array{key:string, role:string, contact_id:?int, user_id:?int, name:string, email:?string, phone:?string, whatsapp:?string}>
     */
    public function parties(RentalInspection $inspection): Collection
    {
        $notifier = app(RentalInspectionNotificationService::class);
        $parties = collect();

        foreach ($notifier->tenantContacts($inspection) as $contact) {
            $parties->push($this->contactParty(RentalInspectionSigningLink::ROLE_TENANT, $contact));
        }

        $landlord = $inspection->property?->sellerOwnerContact();
        if ($landlord) {
            $parties->push($this->contactParty(RentalInspectionSigningLink::ROLE_LANDLORD, $landlord));
        }

        $agent = $inspection->inspector ?? $inspection->createdBy;
        if ($agent) {
            $parties->push([
                'key' => 'agent:' . $agent->id,
                'role' => RentalInspectionSigningLink::ROLE_AGENT,
                'contact_id' => null,
                'user_id' => (int) $agent->id,
                'name' => (string) $agent->name,
                'email' => $agent->email,
                'phone' => null,
                'whatsapp' => null,
            ]);
        }

        return $parties->unique('key')->values();
    }

    private function contactParty(string $role, Contact $contact): array
    {
        $dial = $contact->phones()->where('is_primary', true)->value('dial_code') ?: '+27';
        $whatsapp = WhatsAppNumberFormatter::forDeepLink($contact->phone, $dial);

        return [
            'key' => $role . ':' . $contact->id,
            'role' => $role,
            'contact_id' => (int) $contact->id,
            'user_id' => null,
            'name' => (string) $contact->full_name,
            'email' => $contact->email ?: null,
            'phone' => $contact->phone ?: null,
            'whatsapp' => $whatsapp !== '' ? $whatsapp : null,
        ];
    }

    private function findParty(RentalInspection $inspection, string $role, ?int $contactId): ?array
    {
        return $this->parties($inspection)->first(
            fn (array $p) => $p['role'] === $role && ($role === RentalInspectionSigningLink::ROLE_AGENT || (int) $p['contact_id'] === (int) $contactId)
        );
    }

    // ═══ What is on screen ═════════════════════════════════════════════════════

    public function enabledFor(RentalInspection $inspection): bool
    {
        // Every inspection type is signed (Johan, 7 Oct 2026) — only the agency's own switch decides.
        return RentalInspectionSetting::signingLinkEnabledFor($inspection->agency_id);
    }

    /** The party's live signature row (a superseded row is a corrected mistake, not the party's answer). */
    public function liveSignatureFor(RentalInspection $inspection, array $party): ?RentalInspectionSignature
    {
        return RentalInspectionSignature::withoutGlobalScopes()
            ->where('rental_inspection_id', $inspection->id)
            ->where('party_role', $party['role'])
            ->when($party['role'] !== RentalInspectionSigningLink::ROLE_AGENT, fn ($q) => $q->where('party_contact_id', $party['contact_id']))
            ->whereNull('superseded_at')
            ->latest('id')
            ->first();
    }

    /** The newest link ever issued for a party (live, signed, expired or revoked) — what the status chip describes. */
    public function latestLinkFor(RentalInspection $inspection, array $party): ?RentalInspectionSigningLink
    {
        return RentalInspectionSigningLink::withoutGlobalScopes()
            ->where('rental_inspection_id', $inspection->id)
            ->where('party_role', $party['role'])
            ->when($party['role'] !== RentalInspectionSigningLink::ROLE_AGENT, fn ($q) => $q->where('party_contact_id', $party['contact_id']))
            ->latest('id')
            ->first();
    }

    /**
     * One row per party for the agent's panel — the same shape on the inspection page and the phone recording screen.
     *
     * @return array{enabled:bool, signable:bool, ready_to_sign:bool, expiry_days:int, rows:array<int, array<string, mixed>>}
     */
    public function panel(RentalInspection $inspection): array
    {
        $enabled = $this->enabledFor($inspection);
        $recordable = $inspection->isRecordable();

        $rows = $this->parties($inspection)->map(function (array $party) use ($inspection, $enabled, $recordable) {
            $signature = $this->liveSignatureFor($inspection, $party);
            $link = $this->latestLinkFor($inspection, $party);
            $isAgent = $party['role'] === RentalInspectionSigningLink::ROLE_AGENT;

            $blocked = null;
            if (! $enabled) {
                $blocked = 'Signing by link is switched off for this agency.';
            } elseif (! $recordable) {
                $blocked = 'This inspection can no longer be changed.';
            } elseif (! $isAgent && $signature) {
                $blocked = 'Already recorded.';
            }

            return [
                'key' => $party['key'],
                'role' => $party['role'],
                'role_label' => ucfirst($party['role']),
                'name' => $party['name'],
                'email' => $party['email'],
                'phone' => $party['phone'],
                'whatsapp' => $party['whatsapp'],
                'contact_id' => $party['contact_id'],
                'is_agent' => $isAgent,
                'recorded' => $signature ? $this->describeSignature($signature) : null,
                'link' => $link ? $this->describeLink($link) : null,
                'can_issue' => $blocked === null,
                'blocked_reason' => $blocked,
            ];
        })->values()->all();

        return [
            'lock' => $this->lockState($inspection),
            'reopened' => $this->reopenedState($inspection, $rows),
            'enabled' => $enabled,
            'signable' => $enabled,
            'ready_to_sign' => $inspection->status === RentalInspection::STATUS_AWAITING_SIGNATURE,
            'expiry_days' => RentalInspectionSetting::signingLinkExpiryDaysFor($inspection->agency_id),
            'rows' => $rows,
        ];
    }

    /**
     * §47 — what the screen needs to show for a signed / sent report: whether it is locked, whether "Edit report" is
     * possible, whether it has been DISTRIBUTED (then only "Start new inspection" is left), and its replaces / replaced-by.
     *
     * @return array<string, mixed>
     */
    public function lockState(RentalInspection $inspection): array
    {
        $replacedBy = RentalInspection::withoutGlobalScopes()->where('replaces_inspection_id', $inspection->id)->where('status', '!=', RentalInspection::STATUS_CANCELLED)->first();
        $replaces = $inspection->replaces_inspection_id ? RentalInspection::withoutGlobalScopes()->withTrashed()->find($inspection->replaces_inspection_id) : null;
        $distributed = $inspection->isDistributed();

        return [
            'signed_locked' => $inspection->isSignedLocked(),
            'distributed' => $distributed,
            'can_reopen' => $inspection->canBeReopened(),
            'can_replace' => $distributed && ! $inspection->trashed() && $inspection->status !== RentalInspection::STATUS_CANCELLED && ! $replacedBy,
            'replaced_by' => $replacedBy ? ['id' => $replacedBy->id, 'url' => route('corex.rental-inspections.show', $replacedBy->id), 'label' => RentalInspection::typeName($replacedBy->type) . ' #' . $replacedBy->id] : null,
            'replaces' => $replaces ? ['id' => $replaces->id, 'url' => route('corex.rental-inspections.show', $replaces->id), 'label' => RentalInspection::typeName($replaces->type) . ' #' . $replaces->id] : null,
        ];
    }

    /**
     * §47 — after an "Edit report": who reopened it, why, and whether the agent still has to resend links (a party whose
     * signature was voided and who has neither signed again nor been sent a link since the reopen).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    public function reopenedState(RentalInspection $inspection, array $rows): ?array
    {
        $reopen = app(RentalInspectionReopenService::class)->latestFor($inspection);
        if (! $reopen) {
            return null;
        }

        $voidedKeys = collect($reopen->voided_signatures)->map(fn ($v) => $v['party_role'] . ':' . ($v['party_role'] === 'agent' ? '' : $this->contactIdOfSignature((int) $v['signature_id'])))->all();
        $resend = collect($rows)->contains(function ($row) use ($voidedKeys, $reopen) {
            if ($row['is_agent'] || $row['recorded']) {
                return false;
            }
            if (! in_array($row['role'] . ':' . $row['contact_id'], $voidedKeys, true)) {
                return false;
            }
            $sentAt = $row['link']['last_sent_at'] ?? null;

            return ! $sentAt || \Illuminate\Support\Carbon::parse($sentAt)->lt($reopen->reopened_at);
        });

        return [
            'at' => $reopen->reopened_at->toIso8601String(),
            'by' => $reopen->reopenedBy?->name,
            'reason' => $reopen->reason,
            'resend_needed' => $resend,
        ];
    }

    private function contactIdOfSignature(int $signatureId): ?int
    {
        return RentalInspectionSignature::withoutGlobalScopes()->whereKey($signatureId)->value('party_contact_id');
    }

    /** §47 — this party's signature was voided by an "Edit report" and they have not signed the changed report yet. */
    public function mustSignAgain(RentalInspection $inspection, array $party): bool
    {
        if ($party['role'] === RentalInspectionSigningLink::ROLE_AGENT || $this->liveSignatureFor($inspection, $party)) {
            return false;
        }

        return RentalInspectionSignature::withoutGlobalScopes()
            ->where('rental_inspection_id', $inspection->id)
            ->where('party_role', $party['role'])->where('party_contact_id', $party['contact_id'])
            ->whereNotNull('voided_by_reopen_id')->exists();
    }

    private function describeSignature(RentalInspectionSignature $s): array
    {
        $label = match ($s->disposition) {
            RentalInspectionSignature::DISPOSITION_SIGNED => 'Signed',
            RentalInspectionSignature::DISPOSITION_REFUSED => 'Refused / disputed',
            RentalInspectionSignature::DISPOSITION_WET_INK => 'Signed on paper',
            RentalInspectionSignature::DISPOSITION_AWAITING_WET_INK => 'Awaiting paper signature',
            default => ucfirst(str_replace('_', ' ', (string) $s->disposition)),
        };
        $via = match ($s->signed_via) {
            RentalInspectionSignature::SIGNED_VIA_LINK => 'from their link',
            RentalInspectionSignature::SIGNED_VIA_AGENT_DEVICE => "on the agent's device",
            default => null,
        };

        return [
            'disposition' => $s->disposition,
            'label' => $label,
            'via' => $via,
            'at' => $s->disposition_recorded_at?->toIso8601String(),
        ];
    }

    private function describeLink(RentalInspectionSigningLink $l): array
    {
        $status = $l->status();

        return [
            'id' => $l->id,
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'url' => $l->url(),
            'live' => $l->isLive(),
            'expires_at' => $l->expires_at?->toIso8601String(),
            'last_sent_at' => $l->last_sent_at?->toIso8601String(),
            'last_sent_channel' => $l->last_sent_channel,
            'last_sent_to' => $l->last_sent_to,
            'last_send_status' => $l->last_send_status,
            'last_send_error' => $l->last_send_error,
            'send_count' => $l->send_count,
            'opened_at' => $l->first_opened_at?->toIso8601String(),
            'outcome_at' => $l->outcome_at?->toIso8601String(),
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            RentalInspectionSigningLink::STATUS_NOT_SENT => 'Not sent',
            RentalInspectionSigningLink::STATUS_SENT => 'Sent',
            RentalInspectionSigningLink::STATUS_OPENED => 'Opened',
            RentalInspectionSigningLink::STATUS_SIGNED => 'Signed',
            RentalInspectionSigningLink::STATUS_DECLINED => 'Declined / disputed',
            RentalInspectionSigningLink::STATUS_EXPIRED => 'Expired',
            RentalInspectionSigningLink::STATUS_REVOKED => 'Revoked',
            default => ucfirst($status),
        };
    }

    // ═══ Issue / send / revoke ═════════════════════════════════════════════════

    /**
     * The party's live link, issuing one when there is none. A signed, expired or revoked link is not "live for
     * sending": signed stays as it is (the party is done), expired and revoked get a fresh link.
     *
     * @throws LinkException
     */
    public function issue(RentalInspection $inspection, string $role, ?int $contactId, User $by): RentalInspectionSigningLink
    {
        $this->assertCanIssue($inspection);
        $party = $this->findParty($inspection, $role, $contactId)
            ?? throw new LinkException(LinkException::INVALID, 'That person is not a party to this inspection.');

        if ($role !== RentalInspectionSigningLink::ROLE_AGENT && $this->liveSignatureFor($inspection, $party)) {
            throw new LinkException(LinkException::ALREADY_RECORDED, $party['name'] . ' already has a signing outcome recorded on this inspection.');
        }

        $latest = $this->latestLinkFor($inspection, $party);
        if ($latest && $latest->isLive()) {
            return $latest;
        }

        return RentalInspectionSigningLink::create([
            'agency_id' => $inspection->agency_id,
            'rental_inspection_id' => $inspection->id,
            'party_role' => $role,
            'party_contact_id' => $party['contact_id'],
            'party_user_id' => $party['user_id'],
            'token' => Str::random(48),
            'expires_at' => now()->addDays(RentalInspectionSetting::signingLinkExpiryDaysFor($inspection->agency_id)),
            'created_by_user_id' => $by->id,
        ]);
    }

    public function revoke(RentalInspectionSigningLink $link, User $by): void
    {
        if ($link->revoked_at !== null) {
            return;
        }
        $link->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $by->id])->save();

        $inspection = $this->inspectionOf($link);
        RentalInspectionAuditLog::record(
            $inspection,
            RentalInspectionAuditLog::EVENT_SIGNING_LINK_REVOKED,
            ucfirst($link->party_role) . ' signing link revoked' . ($link->outcome ? ' (after they had responded).' : '.'),
            null,
            ['party_role' => $link->party_role, 'link_id' => $link->id],
            $by,
        );
    }

    /** The agent shared the link some way other than email (WhatsApp, copy, QR, handed over): that counts as sent. */
    public function recordShared(RentalInspectionSigningLink $link, string $channel, ?string $to, User $by): void
    {
        if (! in_array($channel, [RentalInspectionSigningLink::CHANNEL_WHATSAPP, RentalInspectionSigningLink::CHANNEL_COPIED, RentalInspectionSigningLink::CHANNEL_QR, RentalInspectionSigningLink::CHANNEL_DEVICE], true)) {
            throw new LinkException(LinkException::INVALID, 'Unknown way of sharing the link.');
        }
        $this->markSent($link, $channel, $to, 'shared', null, $by);
    }

    /**
     * Email the link from the agent's own mailbox (the same per-agent mail path every other inspection mail uses; in
     * any non-production environment it is redirected to the configured test address by sendGenericMail()).
     *
     * @return array{status:string, error:?string, to:?string}
     * @throws LinkException
     */
    public function sendEmail(RentalInspectionSigningLink $link, User $by): array
    {
        if (! $link->isLive() || $link->outcome !== null) {
            throw new LinkException(LinkException::UNAVAILABLE, 'This link is no longer live — issue a new one first.');
        }
        $inspection = $this->inspectionOf($link);
        $this->assertCanIssue($inspection);
        $party = $this->findParty($inspection, $link->party_role, $link->party_contact_id)
            ?? throw new LinkException(LinkException::INVALID, 'That person is not a party to this inspection.');

        if (! $party['email']) {
            $this->markSent($link, RentalInspectionSigningLink::CHANNEL_EMAIL, null, 'skipped', 'No email address on file.', $by, countAsSent: false);

            return ['status' => 'skipped', 'error' => 'No email address on file for ' . $party['name'] . '.', 'to' => null];
        }

        $mail = new RentalInspectionSigningLinkMail(
            recipientName: $party['name'],
            propertyAddress: $inspection->property?->buildDisplayAddress() ?? '',
            inspectionLabel: RentalInspection::typeName($inspection->type),
            signingUrl: $link->url(),
            expiresOn: $link->expires_at->format('d M Y'),
            canSign: $party['role'] !== RentalInspectionSigningLink::ROLE_AGENT,
            agentName: $by->name,
            reSign: $this->mustSignAgain($inspection, $party),
        );

        $result = app(SignedDocumentDistributionService::class)->sendGenericMail($party['email'], $mail, $by);
        $ok = $result['status'] === 'sent';
        $this->markSent($link, RentalInspectionSigningLink::CHANNEL_EMAIL, $party['email'], $ok ? 'sent' : 'failed', $ok ? null : ($result['error'] ?? 'The email did not send.'), $by, countAsSent: $ok);

        return ['status' => $ok ? 'sent' : 'failed', 'error' => $ok ? null : ($result['error'] ?? 'The email did not send.'), 'to' => $party['email']];
    }

    private function markSent(RentalInspectionSigningLink $link, string $channel, ?string $to, string $status, ?string $error, User $by, bool $countAsSent = true): void
    {
        $fields = ['last_send_status' => $status, 'last_send_error' => $error ? mb_substr($error, 0, 500) : null, 'last_sent_channel' => $channel, 'last_sent_to' => $to];
        if ($countAsSent) {
            $fields['last_sent_at'] = now();
            $fields['send_count'] = $link->send_count + 1;
        }
        $link->forceFill($fields)->save();

        if ($countAsSent) {
            RentalInspectionAuditLog::record(
                $this->inspectionOf($link),
                RentalInspectionAuditLog::EVENT_SIGNING_LINK_SENT,
                ucfirst($link->party_role) . ' signing link ' . ($channel === RentalInspectionSigningLink::CHANNEL_EMAIL ? 'emailed' : 'shared (' . $channel . ')') . '.',
                null,
                ['party_role' => $link->party_role, 'link_id' => $link->id, 'channel' => $channel],
                $by,
            );
        }
    }

    /** @throws LinkException */
    private function assertCanIssue(RentalInspection $inspection): void
    {
        if (! RentalInspectionSetting::signingLinkEnabledFor($inspection->agency_id)) {
            throw new LinkException(LinkException::NOT_ENABLED, 'Signing by link is switched off for this agency (Settings → Rental inspections).');
        }
        if (! $inspection->isRecordable()) {
            throw new LinkException(LinkException::UNAVAILABLE, 'This inspection can no longer be changed, so no new signing link can be issued.');
        }
    }

    private function inspectionOf(RentalInspectionSigningLink $link): RentalInspection
    {
        return $link->relationLoaded('inspection') && $link->inspection
            ? $link->inspection
            : RentalInspection::withoutGlobalScopes()->findOrFail($link->rental_inspection_id);
    }

    // ═══ QR ════════════════════════════════════════════════════════════════════

    /** The link as a QR code (PNG data URI) — the target is the link's own URL, byte for byte, so it can be checked. */
    public function qrDataUri(RentalInspectionSigningLink $link, int $size = 520): string
    {
        $qr = new QrCode(
            data: $link->url(),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $size,
            margin: 12,
        );

        return (new PngWriter())->write($qr)->getDataUri();
    }

    // ═══ Opening and signing ═══════════════════════════════════════════════════

    /** Record that the party opened their link (first and latest time, and how many times). Never throws. */
    public function recordOpen(RentalInspectionSigningLink $link): void
    {
        try {
            $link->forceFill([
                'first_opened_at' => $link->first_opened_at ?? now(),
                'last_opened_at' => now(),
                'open_count' => $link->open_count + 1,
            ])->save();
        } catch (\Throwable $e) {
            \Log::warning('Signing link open could not be recorded', ['link_id' => $link->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Whether, right now, this link's party can sign or decline from it — and if not, why in plain words.
     *
     * @return array{can_sign:bool, reason:?string, message:?string}
     */
    public function signability(RentalInspectionSigningLink $link): array
    {
        $inspection = $this->inspectionOf($link);

        if ($link->party_role === RentalInspectionSigningLink::ROLE_AGENT) {
            return ['can_sign' => false, 'reason' => LinkException::AGENT_LINK, 'message' => 'The agent signs in CoreX, with their own login and PIN.'];
        }
        if ($link->outcome !== null) {
            return ['can_sign' => false, 'reason' => LinkException::ALREADY_USED, 'message' => 'This link has already been used.'];
        }
        if (! $this->enabledFor($inspection)) {
            return ['can_sign' => false, 'reason' => LinkException::NOT_ENABLED, 'message' => 'Signing from a link is not available for this inspection.'];
        }
        if ($inspection->status === RentalInspection::STATUS_COMPLETED) {
            return ['can_sign' => false, 'reason' => LinkException::UNAVAILABLE, 'message' => 'This inspection is complete — the report below is the signed record.'];
        }
        if ($inspection->status !== RentalInspection::STATUS_AWAITING_SIGNATURE) {
            return ['can_sign' => false, 'reason' => LinkException::NOT_READY, 'message' => 'You can read the report now. Signing opens when the agent finishes the inspection and marks it ready to sign — open this link again then.'];
        }
        $party = $this->findParty($inspection, $link->party_role, $link->party_contact_id);
        if ($party && $this->liveSignatureFor($inspection, $party)) {
            return ['can_sign' => false, 'reason' => LinkException::ALREADY_RECORDED, 'message' => 'A signing outcome has already been recorded for you on this inspection.'];
        }

        return ['can_sign' => true, 'reason' => null, 'message' => null];
    }

    /**
     * Sign — or decline / dispute — through a link. `$evidence` is who/where: ip, user_agent, and for the agent's
     * device the agent's user id (`via` = RentalInspectionSignature::SIGNED_VIA_*).
     *
     * @param  array{action:string, typed_name:string, signature_image?:?string, read_confirmed?:mixed, comment?:?string, reason_preset?:?string, reason_note?:?string}  $input
     * @param  array{via:string, ip:?string, user_agent:?string, device_user_id?:?int}  $evidence
     * @throws LinkException
     */
    public function submit(RentalInspectionSigningLink $link, array $input, array $evidence): RentalInspectionSignature
    {
        return DB::transaction(function () use ($link, $input, $evidence) {
            // Lock the row: two taps of the button (or two phones) must produce exactly one signature.
            $locked = RentalInspectionSigningLink::withoutGlobalScopes()->lockForUpdate()->find($link->id);
            if (! $locked || ! $locked->isLive()) {
                throw new LinkException(LinkException::UNAVAILABLE, 'This link is no longer available.');
            }
            $inspection = RentalInspection::withoutGlobalScopes()->findOrFail($locked->rental_inspection_id);
            $locked->setRelation('inspection', $inspection);

            $check = $this->signability($locked);
            if (! $check['can_sign']) {
                throw new LinkException($check['reason'], $check['message']);
            }

            $action = (string) ($input['action'] ?? '');
            $typedName = trim((string) ($input['typed_name'] ?? ''));
            if (mb_strlen($typedName) < 2 || mb_strlen($typedName) > 191) {
                throw new LinkException(LinkException::INVALID, 'Please type your full name.');
            }

            $attributes = [
                'party_contact_id' => $locked->party_contact_id,
                'recorded_by_user_id' => $evidence['device_user_id'] ?? null,
                'signed_via' => $evidence['via'],
                'signing_link_id' => $locked->id,
                'signed_on_device_by_user_id' => $evidence['via'] === RentalInspectionSignature::SIGNED_VIA_AGENT_DEVICE ? ($evidence['device_user_id'] ?? null) : null,
                'signed_typed_name' => $typedName,
                'signed_ip' => $evidence['ip'] ? mb_substr($evidence['ip'], 0, 45) : null,
                'signed_user_agent' => $evidence['user_agent'] ? mb_substr($evidence['user_agent'], 0, 500) : null,
                'signed_report_fingerprint' => $inspection->reportFingerprint(),
            ];

            try {
                if ($action === 'sign') {
                    if (! filter_var($input['read_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                        throw new LinkException(LinkException::INVALID, 'Please tick that you have read this inspection report.');
                    }
                    if (empty($input['signature_image'])) {
                        throw new LinkException(LinkException::INVALID, 'Please draw your signature.');
                    }
                    $attributes['read_confirmed_at'] = now();
                    $comment = trim((string) ($input['comment'] ?? ''));
                    $attributes['signer_comment'] = $comment !== '' ? mb_substr($comment, 0, 2000) : null;
                    $attributes['party_signature_path'] = RentalInspectionSignature::storeCanvasImage((string) $input['signature_image'], $inspection->property_id);

                    $signature = RentalInspectionSignature::capture($inspection, $locked->party_role, RentalInspectionSignature::DISPOSITION_SIGNED, $attributes);
                    $outcome = RentalInspectionSigningLink::OUTCOME_SIGNED;
                } elseif ($action === 'decline') {
                    $presets = collect(RentalInspectionSetting::refusalReasonPresetsFor($inspection->agency_id))->pluck('key')->all();
                    $preset = (string) ($input['reason_preset'] ?? '');
                    if (! in_array($preset, $presets, true)) {
                        throw new LinkException(LinkException::INVALID, 'Please choose a reason.');
                    }
                    $note = trim((string) ($input['reason_note'] ?? ''));
                    if ($preset === 'other' && $note === '') {
                        throw new LinkException(LinkException::INVALID, 'Please tell us the reason.');
                    }
                    $attributes['refusal_reason_preset'] = $preset;
                    $attributes['refusal_reason_note'] = $note !== '' ? mb_substr($note, 0, 2000) : null;

                    $signature = RentalInspectionSignature::capture($inspection, $locked->party_role, RentalInspectionSignature::DISPOSITION_REFUSED, $attributes);
                    $outcome = RentalInspectionSigningLink::OUTCOME_DECLINED;
                } else {
                    throw new LinkException(LinkException::INVALID, 'Choose whether you are signing or not.');
                }
            } catch (\InvalidArgumentException $e) {
                throw new LinkException(LinkException::INVALID, $e->getMessage());
            } catch (\LogicException $e) {
                // capture(): "already has a disposition" / not recordable — nothing was written.
                throw new LinkException(LinkException::ALREADY_RECORDED, $e->getMessage());
            }

            $locked->forceFill(['outcome' => $outcome, 'outcome_at' => now(), 'signature_id' => $signature->id])->save();

            $by = $evidence['device_user_id'] ?? null ? User::withoutGlobalScopes()->find($evidence['device_user_id']) : null;
            RentalInspectionAuditLog::record(
                $inspection,
                RentalInspectionAuditLog::EVENT_SIGNED_BY_LINK,
                ucfirst($locked->party_role) . ' ' . ($outcome === RentalInspectionSigningLink::OUTCOME_SIGNED ? 'signed' : 'declined to sign') . ' ('
                    . ($evidence['via'] === RentalInspectionSignature::SIGNED_VIA_AGENT_DEVICE ? "on the agent's device" : 'from their own link') . ') as "' . $typedName . '".',
                null,
                ['party_role' => $locked->party_role, 'link_id' => $locked->id, 'signature_id' => $signature->id, 'via' => $evidence['via']],
                $by,
            );

            return $signature;
        });
    }
}
