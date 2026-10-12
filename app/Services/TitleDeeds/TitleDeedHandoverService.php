<?php

namespace App\Services\TitleDeeds;

use App\Events\TitleDeeds\TitleDeedCollected;
use App\Exceptions\TitleDeedException;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedConsent;
use App\Models\TitleDeeds\TitleDeedOwner;
use App\Models\TitleDeeds\TitleDeedReceipt;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Models\TitleDeeds\TitleDeedSigningLink;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * .ai/specs/title-deed-register.md §8.3 — the handover: the one place a deed becomes Collected.
 *
 * Everything that can be checked without touching the PIN is checked first; the PIN is verified LAST, inside the deed's
 * row lock, so a refusal for any other reason never burns it. A wrong PIN is returned as an OUTCOME (not thrown) from the
 * transaction so its count, the lock and the audit row commit; the caller then raises it.
 */
class TitleDeedHandoverService
{
    public const MAX_SIGNATURE_BYTES = 1048576;

    public function __construct(
        private TitleDeedPinService $pins,
        private TitleDeedConsentService $consents,
    ) {}

    /** Everything the handover screen needs, in one place (one source for what the screen offers and the server enforces). */
    public function context(User $user, TitleDeed $deed): array
    {
        $agencyId = $deed->agency_id;
        $owners = TitleDeedOwner::query()->where('title_deed_id', $deed->id)->orderBy('id')->get();
        $recipients = [];
        $changed = [];
        $days = TitleDeedSetting::contactChangedBlockDaysFor($agencyId);

        foreach ($owners as $owner) {
            $contact = TitleDeedDestinations::liveContact($deed, $owner);
            $recipients[] = [
                'contact_id' => $owner->contact_id,
                'name' => $owner->display_name,
                'reachable' => $contact !== null,
                'numbers' => TitleDeedDestinations::storedNumbers($contact),
            ];
            if ($contact && TitleDeedDestinations::detailsChangedWithin($contact->id, $days)) {
                $changed[] = $owner->display_name;
            }
        }

        $policy = TitleDeedPolicy::for($agencyId);

        return [
            'owners' => $recipients,
            'policy' => $policy,
            'pin' => [
                'issued' => $policy->pinUsed() && $this->pins->hasActivePin($deed),
                'locked' => $this->pins->isLocked($deed),
                'attempts' => (int) $deed->pin_failed_attempts,
                'limit' => TitleDeedSetting::pinLockAttemptsFor($agencyId),
            ],
            'consent' => $this->consents->status($deed),
            'override' => [
                'needed' => $changed !== [],
                'owners' => $changed,
                'days' => $days,
                'can' => $user->hasPermission('title_deeds.override'),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $in      method (pin|id_check|representative), pin, collector_type, collector_contact_id,
     *                                     rep_name, rep_id_number, rep_relationship, id_number_seen, consent_id, typed_name,
     *                                     signature_image, override_reason, authority_letter (UploadedFile), collector_id_photo (UploadedFile);
     *                                     OR `signing_link_id` (the collector already signed on their own phone: everything else
     *                                     comes from what was proven when the link was generated, never from this request)
     * @param array{ip:?string, user_agent:?string} $evidence
     * @throws TitleDeedException
     */
    public function handover(User $by, TitleDeed $deed, array $in, array $evidence): TitleDeedReceipt
    {
        $mode = ! empty($in['signing_link_id']) ? 'finish' : 'screen';
        $method = (string) ($in['method'] ?? '');
        if ($mode === 'screen' && ! in_array($method, ['pin', 'id_check', 'representative'], true)) {
            throw new TitleDeedException('Choose how the deed is being handed over.');
        }

        $outcome = $this->transact($mode, fn (array &$stored) => $this->run($by, $deed, $method, $in, $evidence, $stored, $mode));

        /** @var TitleDeedReceipt $receipt */
        $receipt = $outcome['receipt'];
        try {
            event(new TitleDeedCollected($deed->id, $receipt->id, $deed->agency_id, $by->id));
        } catch (\Throwable $e) {
            report($e);   // the handover is committed; a notification problem must not turn it into an error screen
        }

        return $receipt;
    }

    /**
     * "Generate signing link": every gate of the handover EXCEPT the signature is passed here, at the counter (the PIN is proven
     * but not spent; the ID number seen / owner consent / authority letter / override are checked and kept), and a single-use,
     * expiring link for THIS named collector is created. Returns the link and the raw token (shown once, in the send).
     *
     * @param array{send_email?:?string, send_mobile?:?string} $contact where a REPRESENTATIVE's link may go (captured at the counter)
     * @return array{link:TitleDeedSigningLink, token:string}
     * @throws TitleDeedException
     */
    public function prepareLink(User $by, TitleDeed $deed, array $in, array $contact = []): array
    {
        $method = (string) ($in['method'] ?? '');
        if (! in_array($method, ['pin', 'id_check', 'representative'], true)) {
            throw new TitleDeedException('Choose how the deed is being handed over.');
        }
        $policy = TitleDeedPolicy::for($deed->agency_id);
        if (! $policy->signByLink) {
            throw new TitleDeedException($policy->signatureUsed() ? 'Signing on their own phone from a link is switched off for this agency.' : 'This agency does not collect a signature.');
        }

        $outcome = $this->transact('prepare', fn (array &$stored) => $this->run($by, $deed, $method, $in + ['_contact' => $contact], [], $stored, 'prepare'));

        return ['link' => $outcome['link'], 'token' => $outcome['token']];
    }

    /** Cancel a signing link that has not been used (before or after the collector signed). */
    public function cancelLink(User $by, TitleDeed $deed, TitleDeedSigningLink $link, string $why = 'Cancelled by staff.'): void
    {
        DB::transaction(function () use ($by, $deed, $link, $why) {
            $row = TitleDeedSigningLink::query()->lockForUpdate()->find($link->id);
            if (! $row || (int) $row->title_deed_id !== (int) $deed->id || ! in_array($row->status, [TitleDeedSigningLink::STATUS_WAITING, TitleDeedSigningLink::STATUS_SIGNED], true)) {
                return;   // already cancelled / used: absorbed
            }
            $wasSigned = $row->status === TitleDeedSigningLink::STATUS_SIGNED;
            $row->forceFill(['status' => TitleDeedSigningLink::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_user_id' => $by->id, 'token_encrypted' => null])->save();
            TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_SIGNING_LINK_CANCELLED,
                'Signing link for ' . $row->collector_name . ' cancelled' . ($wasSigned ? ' (after the collector had signed - the signature is discarded)' : '') . '. ' . $why, null, null, $by);
        });
    }

    /**
     * Shared transaction + file-cleanup + wrong-PIN handling for the three modes.
     *
     * @return array<string,mixed>
     */
    private function transact(string $mode, \Closure $work): array
    {
        $stored = [];   // files written by this attempt - removed again if the attempt does not commit
        try {
            $outcome = DB::transaction(fn () => $work($stored));
        } catch (QueryException $e) {
            $this->discard($stored);
            // The unique key on the receipt is the last line of defence against two handovers landing together.
            if (str_contains($e->getMessage(), 'Duplicate entry') && str_contains($e->getMessage(), 'title_deed_id')) {
                throw new TitleDeedException('This title deed has just been collected.', TitleDeedException::ALREADY_COLLECTED);
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->discard($stored);
            throw $e;
        }

        if (isset($outcome['wrong'])) {
            $this->discard($stored);
            throw new TitleDeedException($outcome['wrong']['message'], $outcome['wrong']['locked'] ? TitleDeedException::LOCKED : TitleDeedException::GENERAL);
        }

        return $outcome;
    }

    /**
     * mode: 'screen'  - the collector signs on the staff screen (the classic handover)
     *       'prepare' - generate a signing link: every gate except the signature, PIN proven not spent, nothing is collected
     *       'finish'  - the collector already signed from their link: complete the handover from what was proven at the counter
     *
     * @return array<string,mixed>
     */
    private function run(User $by, TitleDeed $deed, string $method, array $in, array $evidence, array &$stored, string $mode): array
    {
        $locked = TitleDeed::query()->lockForUpdate()->find($deed->id);
        if (! $locked) {
            throw new TitleDeedException('This title deed is archived. Restore it before handing it over.');
        }
        if ($locked->isCollected()) {
            throw new TitleDeedException('This title deed has already been collected.', TitleDeedException::ALREADY_COLLECTED);
        }
        $agencyId = $locked->agency_id;

        $link = null;
        if ($mode === 'finish') {
            $link = TitleDeedSigningLink::query()->lockForUpdate()->find((int) $in['signing_link_id']);
            if (! $link || (int) $link->title_deed_id !== (int) $locked->id) {
                throw new TitleDeedException('That signing link could not be found.');
            }
            if ($link->status === TitleDeedSigningLink::STATUS_WAITING) {
                throw new TitleDeedException('The collector has not signed yet.');
            }
            if ($link->status !== TitleDeedSigningLink::STATUS_SIGNED) {
                throw new TitleDeedException('That signing link can no longer be used. Generate a new one, or sign on this screen.');
            }
            $snap = (array) $link->snapshot;
            $method = (string) ($snap['method'] ?? '');
            $in = (array) ($snap['input'] ?? []);
        }

        // ── 1. Who is collecting, and on what authority ──
        $collector = $this->resolveCollector($locked, $method, $in, $agencyId, $consent);

        // ── 2. What must be present (checked before anything is stored or the PIN is touched) ──
        // Required = cannot hand over without it. Optional = offered, may be skipped. Not used = never asked for, and anything sent
        // anyway is ignored (never stored).
        $policy = TitleDeedPolicy::for($agencyId);
        $typedName = $mode === 'finish' ? trim((string) $link->signed_typed_name) : trim((string) ($in['typed_name'] ?? ''));
        $image = ($mode === 'screen' && $policy->signOnScreen) ? (string) ($in['signature_image'] ?? '') : '';
        if ($mode === 'screen' && $policy->signatureRequired()) {
            if (! $policy->signOnScreen) {
                throw new TitleDeedException($policy->signByLink
                    ? 'Signing on this screen is switched off at this agency. Send the collector a signing link.'
                    : 'The signature is Required but no way to sign is switched on. Ask an administrator to fix the Title Deed settings.');
            }
            if (mb_strlen($typedName) < 2) {
                throw new TitleDeedException("Type the collector's full name before they sign.");
            }
            if ($image === '') {
                throw new TitleDeedException('The collector needs to sign: draw in the signature box (nothing has been drawn yet).');
            }
        }
        $photo = $policy->photoUsed() ? ($in['collector_id_photo'] ?? null) : null;
        $photoDocId = $policy->photoUsed() ? (int) ($in['photo_document_id'] ?? 0) : 0;
        if ($policy->photoRequired() && ! $photo instanceof UploadedFile && ! $photoDocId) {
            throw new TitleDeedException("Take a photo of the collector's ID.");
        }
        $letter = $policy->letterUsed() ? ($in['authority_letter'] ?? null) : null;
        $letterDocId = $policy->letterUsed() ? (int) ($in['letter_document_id'] ?? 0) : 0;
        if ($collector['needs_letter'] && ! $letter instanceof UploadedFile && ! $letterDocId) {
            throw new TitleDeedException("Upload the owner's signed authority letter before handing the deed over.");
        }

        // ── 3. Contact details changed recently? Then only an override user may release it ──
        $override = $this->checkOverride($by, $locked, $agencyId, (string) ($in['override_reason'] ?? ''));

        // ── 4. The PIN, before any file is written (a wrong PIN commits its count and nothing else) ──
        $pinVersion = null;
        if ($method === 'pin') {
            if ($mode === 'finish') {
                if (! $this->pins->consumeProven($locked, (int) ($in['pin_version'] ?? 0))) {
                    throw new TitleDeedException('The PIN that was checked at the counter is no longer valid (it was replaced, locked or has run out). Check a PIN again and generate a new signing link.');
                }
            } else {
                $this->pins->assertUsable($locked);
                $check = $mode === 'prepare' ? $this->pins->peek($locked, (string) ($in['pin'] ?? '')) : $this->pins->check($locked, (string) ($in['pin'] ?? ''));
                if (! $check['ok']) {
                    $remaining = max(0, $check['limit'] - $check['attempts']);
                    TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_PIN_WRONG,
                        'Wrong PIN entered (attempt ' . $check['attempts'] . ' of ' . $check['limit'] . ').', null, null, $by);
                    if ($check['locked']) {
                        TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_PIN_LOCKED, 'The PIN is now locked.', null, null, $by);
                    }

                    return ['wrong' => [
                        'message' => $check['locked']
                            ? 'That PIN is wrong and the PIN is now locked. Issue a new PIN, or hand the deed over another way.'
                            : 'That PIN is not right. ' . $remaining . ' attempt' . ($remaining === 1 ? '' : 's') . ' left.',
                        'locked' => $check['locked'],
                    ]];
                }
                $pinVersion = (int) $locked->pin_version;
            }
        }

        // ── 4b. Store the files. Anything that fails from here rolls the whole attempt back, un-consuming the PIN. ──
        $signaturePath = $mode === 'finish' ? $link->signature_path : ($image !== '' ? $this->storeSignatureImage($image, $locked->id, $stored) : null);
        $photoDoc = $photo instanceof UploadedFile ? $this->storeDocument($locked, $photo, 'ids', 'title_deed_collector_id', $by, $stored) : ($photoDocId ? Document::query()->find($photoDocId) : null);
        $letterDoc = $letter instanceof UploadedFile ? $this->storeDocument($locked, $letter, 'power_of_attorney', 'title_deed_authority_letter', $by, $stored) : ($letterDocId ? Document::query()->find($letterDocId) : null);

        // ── 5a. Generate a signing link: nothing is collected ──
        if ($mode === 'prepare') {
            $contact = (array) ($in['_contact'] ?? []);
            $token = Str::random(48);
            $this->cancelLiveLinks($locked, $by, 'Replaced by a new signing link.');
            $newLink = TitleDeedSigningLink::create([
                'agency_id' => $agencyId,
                'title_deed_id' => $locked->id,
                'token_hash' => TitleDeedSigningLink::hashToken($token),
                'token_encrypted' => Crypt::encryptString($token),
                'status' => TitleDeedSigningLink::STATUS_WAITING,
                'collector_type' => $collector['type'],
                'collector_contact_id' => $collector['contact_id'],
                'collector_name' => $collector['name'],
                'send_email' => ($contact['send_email'] ?? null) ?: null,
                'send_mobile' => ($contact['send_mobile'] ?? null) ?: null,
                'snapshot' => ['method' => $method, 'input' => [
                    'method' => $method, 'collector_type' => $collector['type'], 'collector_contact_id' => $collector['contact_id'],
                    'rep_name' => $collector['type'] === 'representative' ? $collector['name'] : null,
                    'rep_id_number' => $collector['id_number'], 'rep_relationship' => $collector['relationship'],
                    'id_number_seen' => $collector['id_number_seen'], 'consent_id' => $consent?->id, 'override_reason' => $override,
                    'pin_version' => $pinVersion, 'letter_document_id' => $letterDoc?->id, 'photo_document_id' => $photoDoc?->id,
                ]],
                'created_by_user_id' => $by->id,
                'expires_at' => now()->addMinutes(TitleDeedSetting::signingLinkMinutesFor($agencyId)),
            ]);
            TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_SIGNING_LINK_CREATED,
                'Signing link generated for ' . $collector['name'] . ' (' . TitleDeed::METHOD_LABELS[$collector['method']] . ' checked). Valid until ' . $newLink->expires_at->format('H:i') . '.', null, null, $by);

            return ['link' => $newLink, 'token' => $token];
        }

        // ── 5b. Record the handover ──
        $receipt = TitleDeedReceipt::create([
            'agency_id' => $agencyId,
            'title_deed_id' => $locked->id,
            'method' => $collector['method'],
            'collector_type' => $collector['type'],
            'collector_contact_id' => $collector['contact_id'],
            'collector_name' => $collector['name'],
            'collector_id_number' => $collector['id_number'],
            'collector_relationship' => $collector['relationship'],
            'id_number_seen' => $collector['id_number_seen'],
            'consent_id' => $consent?->id,
            'signature_path' => $signaturePath,
            // Only when a signature was actually captured: a skipped (Optional) or unused signature leaves these empty, so the record
            // can say "not captured" instead of implying one.
            'signed_typed_name' => $signaturePath !== null ? mb_substr($typedName !== '' ? $typedName : $collector['name'], 0, 191) : null,
            'signed_at' => $signaturePath === null ? null : ($mode === 'finish' ? $link->signed_at : now()),
            'signed_ip' => $signaturePath === null ? null : ($mode === 'finish' ? $link->signed_ip : (($evidence['ip'] ?? null) ? mb_substr((string) $evidence['ip'], 0, 45) : null)),
            'signed_user_agent' => $signaturePath === null ? null : ($mode === 'finish' ? $link->signed_user_agent : (($evidence['user_agent'] ?? null) ? mb_substr((string) $evidence['user_agent'], 0, 500) : null)),
            'signed_via' => $signaturePath === null ? null : ($mode === 'finish' ? 'link' : 'screen'),
            'proof_modes' => $policy->proofModes(),
            'signing_link_id' => $link?->id,
            'recorded_by_user_id' => $by->id,
            'authority_letter_document_id' => $letterDoc?->id,
            'collector_id_photo_document_id' => $photoDoc?->id,
            'override_user_id' => $override ? $by->id : null,
            'override_reason' => $override,
        ]);

        $locked->forceFill([
            'status' => TitleDeed::STATUS_COLLECTED,
            'collected_at' => now(),
            'collected_by_name' => mb_substr($collector['name'], 0, 191),
            'collected_method' => $collector['method'],
        ])->save();
        $this->pins->retire($locked);

        if ($consent) {
            $consent->forceFill(['status' => TitleDeedConsent::STATUS_USED])->save();
        }

        // Whichever way the signature came, every other signing link for this deed stops here ("whichever completes first").
        if ($link) {
            $link->forceFill(['status' => TitleDeedSigningLink::STATUS_USED, 'token_encrypted' => null])->save();
        }
        $this->cancelLiveLinks($locked, $by, $mode === 'finish' ? 'The deed was handed over.' : 'The collector signed on this screen instead.', $link?->id);

        if ($override) {
            TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_OVERRIDE,
                'Released despite recent contact-detail changes - override by ' . $by->name . ': ' . $override, null, null, $by);
        }
        if ($letterDoc && $letter instanceof UploadedFile) {
            TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_AUTHORITY_LETTER, 'Authority letter uploaded (' . $letterDoc->original_name . ').', null, null, $by);
        }
        TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_HANDOVER,
            'Handed over to ' . $collector['name'] . ' (' . ($collector['type'] === 'owner' ? 'owner' : 'representative: ' . $collector['relationship']) . ') - verified by '
            . TitleDeed::METHOD_LABELS[$collector['method']] . '; ' . ($signaturePath === null ? 'no signature captured' : 'signed ' . ($mode === 'finish' ? 'on their own phone from a link' : 'on the screen')) . '.' . ($collector['note'] ? ' ' . $collector['note'] : ''),
            null, ['method' => $collector['method'], 'signed' => $signaturePath !== null, 'signed_via' => $signaturePath === null ? null : ($mode === 'finish' ? 'link' : 'screen')], $by);

        return ['receipt' => $receipt];
    }

    /** Cancel every signing link of the deed that is still waiting or signed-but-unused (except $exceptId). */
    private function cancelLiveLinks(TitleDeed $deed, User $by, string $why, ?int $exceptId = null): void
    {
        $rows = TitleDeedSigningLink::query()->where('title_deed_id', $deed->id)
            ->whereIn('status', [TitleDeedSigningLink::STATUS_WAITING, TitleDeedSigningLink::STATUS_SIGNED])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->lockForUpdate()->get();
        foreach ($rows as $row) {
            $row->forceFill(['status' => TitleDeedSigningLink::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancelled_by_user_id' => $by->id, 'token_encrypted' => null])->save();
            TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_SIGNING_LINK_CANCELLED, 'Signing link for ' . $row->collector_name . ' cancelled. ' . $why, null, null, $by);
        }
    }

    /**
     * @return array{type:string, contact_id:?int, name:string, id_number:?string, relationship:?string, id_number_seen:?string,
     *               method:string, needs_letter:bool, note:?string}
     */
    private function resolveCollector(TitleDeed $deed, string $method, array $in, int $agencyId, ?TitleDeedConsent &$consent): array
    {
        $consent = null;
        $owners = TitleDeedOwner::query()->where('title_deed_id', $deed->id)->get()->keyBy('contact_id');
        if ($owners->isEmpty()) {
            throw new TitleDeedException('This record has no owners, so there is no one to hand the deed to. Edit the record first.');
        }

        $policy = TitleDeedPolicy::for($agencyId);
        if ($method !== 'pin' && $policy->pinRequired()) {
            throw new TitleDeedException("At this agency a title deed is only handed over against the owner's PIN. Ask the owner for it, or issue a new PIN.");
        }

        if ($method === 'id_check') {
            $owner = $owners->get((int) ($in['collector_contact_id'] ?? 0));
            if (! $owner) {
                throw new TitleDeedException('Pick which owner is collecting.');
            }
            $seen = trim((string) ($in['id_number_seen'] ?? ''));
            if (mb_strlen($seen) < 6) {
                throw new TitleDeedException('Enter the ID number you saw.');
            }

            return [
                'type' => 'owner', 'contact_id' => (int) $owner->contact_id, 'name' => $owner->display_name, 'id_number' => null,
                'relationship' => null, 'id_number_seen' => mb_substr($seen, 0, 40), 'method' => TitleDeed::METHOD_ID_CHECK,
                'needs_letter' => false, 'note' => $this->idMatchNote($deed, $owner, $seen),
            ];
        }

        if ($method === 'pin') {
            if (! $policy->pinUsed()) {
                throw new TitleDeedException('This agency does not use a collection PIN.');
            }
            if (($in['collector_type'] ?? 'owner') === 'representative') {
                $rep = $this->repFromInput($in, $agencyId);

                return $rep + ['type' => 'representative', 'contact_id' => null, 'id_number_seen' => null, 'method' => TitleDeed::METHOD_PIN, 'needs_letter' => $policy->letterRequired(), 'note' => null];
            }
            $owner = $owners->get((int) ($in['collector_contact_id'] ?? 0));
            if (! $owner) {
                throw new TitleDeedException('Pick which owner is collecting.');
            }

            return [
                'type' => 'owner', 'contact_id' => (int) $owner->contact_id, 'name' => $owner->display_name, 'id_number' => null,
                'relationship' => null, 'id_number_seen' => null, 'method' => TitleDeed::METHOD_PIN, 'needs_letter' => false, 'note' => null,
            ];
        }

        // A representative WITHOUT the PIN.
        if (! $policy->repsAllowed) {
            throw new TitleDeedException('Representatives are not allowed to collect for this agency.');
        }
        $mode = $policy->repRule;
        if ($mode === TitleDeedSetting::REP_REFUSE) {
            throw new TitleDeedException('A representative cannot collect without the PIN at this agency.');
        }

        if (! empty($in['consent_id'])) {
            $consent = TitleDeedConsent::query()->lockForUpdate()->where('title_deed_id', $deed->id)->find((int) $in['consent_id']);
            if (! $consent || ! $consent->isUsableForHandover()) {
                throw new TitleDeedException('The owners\' consent is not valid any more (it was declined, has run out, or was used). Ask again.');
            }
        }

        $hasLetterInput = ($in['authority_letter'] ?? null) instanceof UploadedFile || ! empty($in['letter_document_id']);
        $needsConsent = $mode === TitleDeedSetting::REP_OWNER_CONSENT;
        if ($needsConsent && ! $consent) {
            throw new TitleDeedException('A representative without the PIN needs an owner\'s consent first. Ask the owners to confirm.');
        }
        if (! $consent && ! $hasLetterInput) {
            throw new TitleDeedException('A representative without the PIN needs the owners\' consent or a signed authority letter.');
        }
        $needsLetter = $policy->letterRequired() || ! $consent;

        if ($consent) {
            $name = $consent->rep_name;
            $idNumber = $consent->rep_id_number;
            $rel = $consent->rep_relationship;
            $methodKey = $consent->channel === TitleDeedConsent::CHANNEL_PHONE ? TitleDeed::METHOD_PHONE_CONSENT : TitleDeed::METHOD_OWNER_CONSENT;
            $note = null;
        } else {
            $rep = $this->repFromInput($in, $agencyId);
            [$name, $idNumber, $rel] = [$rep['name'], $rep['id_number'], $rep['relationship']];
            $methodKey = TitleDeed::METHOD_AUTHORITY_LETTER;
            $note = null;
        }

        return [
            'type' => 'representative', 'contact_id' => null, 'name' => $name, 'id_number' => $idNumber, 'relationship' => $rel,
            'id_number_seen' => null, 'method' => $methodKey, 'needs_letter' => $needsLetter, 'note' => $note,
        ];
    }

    /** @return array{name:string, id_number:string, relationship:string} */
    private function repFromInput(array $in, int $agencyId): array
    {
        if (! TitleDeedPolicy::for($agencyId)->repsAllowed) {
            throw new TitleDeedException('Representatives are not allowed to collect for this agency.');
        }
        $name = trim((string) ($in['rep_name'] ?? ''));
        $id = trim((string) ($in['rep_id_number'] ?? ''));
        $rel = trim((string) ($in['rep_relationship'] ?? ''));
        if (mb_strlen($name) < 2 || mb_strlen($id) < 5 || mb_strlen($rel) < 2) {
            throw new TitleDeedException("Enter the representative's full name, ID number and relationship to the owner.");
        }

        return ['name' => mb_substr($name, 0, 191), 'id_number' => mb_substr($id, 0, 40), 'relationship' => mb_substr($rel, 0, 100)];
    }

    /** Null = no override needed or used; a string = the reason an override user gave. */
    private function checkOverride(User $by, TitleDeed $deed, int $agencyId, string $reason): ?string
    {
        $days = TitleDeedSetting::contactChangedBlockDaysFor($agencyId);
        if ($days <= 0) {
            return null;
        }

        $changed = [];
        foreach (TitleDeedOwner::query()->where('title_deed_id', $deed->id)->get() as $owner) {
            $contact = TitleDeedDestinations::liveContact($deed, $owner);
            if ($contact && TitleDeedDestinations::detailsChangedWithin($contact->id, $days)) {
                $changed[] = $owner->display_name;
            }
        }
        if ($changed === []) {
            return null;
        }

        if (! $by->hasPermission('title_deeds.override')) {
            throw new TitleDeedException(
                'The email or phone number for ' . implode(', ', $changed) . ' was changed in the last ' . $days . ' day' . ($days === 1 ? '' : 's')
                . '. Someone with permission to override must release this deed.',
                TitleDeedException::NEEDS_OVERRIDE
            );
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) {
            throw new TitleDeedException('The contact details for ' . implode(', ', $changed) . ' changed recently. Give the reason you are releasing the deed anyway.', TitleDeedException::NEEDS_OVERRIDE);
        }

        return mb_substr($reason, 0, 500);
    }

    private function idMatchNote(TitleDeed $deed, TitleDeedOwner $owner, string $seen): string
    {
        $contact = TitleDeedDestinations::liveContact($deed, $owner);
        $onFile = preg_replace('/\D+/', '', (string) ($contact?->id_number ?? ''));
        $given = preg_replace('/\D+/', '', $seen);
        if ($onFile === '') {
            return 'No ID number on the owner\'s record to compare with.';
        }

        return $onFile === $given ? 'ID number matches the owner\'s record.' : 'ID number differs from the owner\'s record.';
    }

    /** Validate and store the drawn signature (PNG only, re-encoded). Returns the private-disk path. */
    public function storeSignatureImage(string $base64, int $deedId, array &$stored): string
    {
        $data = str_contains($base64, ',') ? explode(',', $base64, 2)[1] : $base64;
        $binary = base64_decode(trim($data), true);
        if ($binary === false || $binary === '') {
            throw new TitleDeedException('The signature was empty. Please sign again.');
        }
        if (strlen($binary) > self::MAX_SIGNATURE_BYTES) {
            throw new TitleDeedException('The signature image is too large.');
        }
        $info = @getimagesizefromstring($binary);
        if ($info === false || ($info[2] ?? null) !== IMAGETYPE_PNG || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
            throw new TitleDeedException('The signature must be a drawn image. Please sign again.');
        }
        if (function_exists('imagecreatefromstring') && function_exists('imagepng')) {
            $img = @imagecreatefromstring($binary);
            if ($img === false) {
                throw new TitleDeedException('The signature could not be read. Please sign again.');
            }
            imagealphablending($img, false);
            imagesavealpha($img, true);
            ob_start();
            imagepng($img);
            $re = (string) ob_get_clean();
            imagedestroy($img);
            if ($re !== '') {
                $binary = $re;
            }
        }

        $path = "title-deed-signatures/{$deedId}/" . bin2hex(random_bytes(16)) . '.png';
        Storage::disk('local')->put($path, $binary);
        $stored[] = $path;

        return $path;
    }

    /** A handover supporting file (authority letter / collector ID photo): private disk, tied to the deed only. */
    private function storeDocument(TitleDeed $deed, UploadedFile $file, string $typeSlug, string $sourceType, User $by, array &$stored): Document
    {
        $path = $file->store("title-deeds/{$deed->id}/handover", 'local');
        if (! $path) {
            throw new TitleDeedException('A file could not be saved. Please try again.');
        }
        $stored[] = $path;

        return Document::create([
            'agency_id' => $deed->agency_id,
            'branch_id' => $deed->branch_id,
            'original_name' => $file->getClientOriginalName() ?: $sourceType,
            'storage_path' => $path,
            'disk' => 'local',
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'document_type_id' => DocumentType::query()->where('slug', $typeSlug)->value('id'),
            'source_type' => $sourceType,
            'source_id' => $deed->id,
            'uploaded_by' => $by->id,
        ]);
    }

    public function discardFiles(array $paths): void
    {
        $this->discard($paths);
    }

    private function discard(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                Storage::disk('local')->delete($path);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
