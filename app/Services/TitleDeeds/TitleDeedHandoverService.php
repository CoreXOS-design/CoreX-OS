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
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

        $pinRequired = TitleDeedSetting::pinRequiredFor($agencyId);

        return [
            'owners' => $recipients,
            'settings' => [
                'pin_required' => $pinRequired,
                'signature_required' => TitleDeedSetting::signatureRequiredFor($agencyId),
                'representatives_allowed' => TitleDeedSetting::representativesAllowedFor($agencyId),
                'rep_mode' => TitleDeedSetting::repWithoutPinModeFor($agencyId),
                'phone_consent_allowed' => TitleDeedSetting::phoneConsentAllowedFor($agencyId),
                'authority_letter_required' => TitleDeedSetting::authorityLetterRequiredFor($agencyId),
                'collector_id_photo_required' => TitleDeedSetting::collectorIdPhotoRequiredFor($agencyId),
            ],
            'pin' => [
                'issued' => $pinRequired && $this->pins->hasActivePin($deed),
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
     *                                     signature_image, override_reason, authority_letter (UploadedFile), collector_id_photo (UploadedFile)
     * @param array{ip:?string, user_agent:?string} $evidence
     * @throws TitleDeedException
     */
    public function handover(User $by, TitleDeed $deed, array $in, array $evidence): TitleDeedReceipt
    {
        $method = (string) ($in['method'] ?? '');
        if (! in_array($method, ['pin', 'id_check', 'representative'], true)) {
            throw new TitleDeedException('Choose how the deed is being handed over.');
        }

        $stored = [];   // files written by this attempt - removed again if the attempt does not commit
        try {
            $outcome = DB::transaction(fn () => $this->run($by, $deed, $method, $in, $evidence, $stored));
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

        /** @var TitleDeedReceipt $receipt */
        $receipt = $outcome['receipt'];
        try {
            event(new TitleDeedCollected($deed->id, $receipt->id, $deed->agency_id, $by->id));
        } catch (\Throwable $e) {
            report($e);   // the handover is committed; a notification problem must not turn it into an error screen
        }

        return $receipt;
    }

    /** @return array{receipt?:TitleDeedReceipt, wrong?:array{message:string, locked:bool}} */
    private function run(User $by, TitleDeed $deed, string $method, array $in, array $evidence, array &$stored): array
    {
        $locked = TitleDeed::query()->lockForUpdate()->find($deed->id);
        if (! $locked) {
            throw new TitleDeedException('This title deed is archived. Restore it before handing it over.');
        }
        if ($locked->isCollected()) {
            throw new TitleDeedException('This title deed has already been collected.', TitleDeedException::ALREADY_COLLECTED);
        }
        $agencyId = $locked->agency_id;

        // ── 1. Who is collecting, and on what authority ──
        $collector = $this->resolveCollector($locked, $method, $in, $agencyId, $consent);
        $needsPinCheck = $method === 'pin';

        // ── 2. What must be present (checked before anything is stored or the PIN is touched) ──
        $typedName = trim((string) ($in['typed_name'] ?? ''));
        $image = (string) ($in['signature_image'] ?? '');
        if (TitleDeedSetting::signatureRequiredFor($agencyId)) {
            if (mb_strlen($typedName) < 2) {
                throw new TitleDeedException("Type the collector's full name before they sign.");
            }
            if ($image === '') {
                throw new TitleDeedException('The collector needs to sign on the screen.');
            }
        }
        $photo = $in['collector_id_photo'] ?? null;
        if (TitleDeedSetting::collectorIdPhotoRequiredFor($agencyId) && ! $photo instanceof UploadedFile) {
            throw new TitleDeedException("Take a photo of the collector's ID.");
        }
        $letter = $in['authority_letter'] ?? null;
        if ($collector['needs_letter'] && ! $letter instanceof UploadedFile) {
            throw new TitleDeedException("Upload the owner's signed authority letter before handing the deed over.");
        }

        // ── 3. Contact details changed recently? Then only an override user may release it ──
        $override = $this->checkOverride($by, $locked, $agencyId, (string) ($in['override_reason'] ?? ''));

        // ── 4. The PIN, before any file is written (a wrong PIN commits its count and nothing else) ──
        if ($needsPinCheck) {
            $this->pins->assertUsable($locked);
            $check = $this->pins->check($locked, (string) ($in['pin'] ?? ''));
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
        }

        // ── 4b. Store the files. Anything that fails from here rolls the whole attempt back, un-consuming the PIN. ──
        $signaturePath = $image !== '' ? $this->storeSignature($image, $locked->id, $stored) : null;
        $photoDoc = $photo instanceof UploadedFile ? $this->storeDocument($locked, $photo, 'ids', 'title_deed_collector_id', $by, $stored) : null;
        $letterDoc = $letter instanceof UploadedFile ? $this->storeDocument($locked, $letter, 'power_of_attorney', 'title_deed_authority_letter', $by, $stored) : null;

        // ── 5. Record it ──
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
            'signed_typed_name' => mb_substr($typedName !== '' ? $typedName : $collector['name'], 0, 191),
            'signed_at' => now(),
            'signed_ip' => ($evidence['ip'] ?? null) ? mb_substr((string) $evidence['ip'], 0, 45) : null,
            'signed_user_agent' => ($evidence['user_agent'] ?? null) ? mb_substr((string) $evidence['user_agent'], 0, 500) : null,
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

        if ($override) {
            TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_OVERRIDE,
                'Released despite recent contact-detail changes - override by ' . $by->name . ': ' . $override, null, null, $by);
        }
        if ($letterDoc) {
            TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_AUTHORITY_LETTER, 'Authority letter uploaded (' . $letterDoc->original_name . ').', null, null, $by);
        }
        TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_HANDOVER,
            'Handed over to ' . $collector['name'] . ' (' . ($collector['type'] === 'owner' ? 'owner' : 'representative: ' . $collector['relationship']) . ') - verified by '
            . TitleDeed::METHOD_LABELS[$collector['method']] . '.' . ($collector['note'] ? ' ' . $collector['note'] : ''),
            null, ['method' => $collector['method'], 'signed' => $signaturePath !== null], $by);

        return ['receipt' => $receipt];
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
            if (! TitleDeedSetting::pinRequiredFor($agencyId)) {
                throw new TitleDeedException('This agency does not use a collection PIN.');
            }
            if (($in['collector_type'] ?? 'owner') === 'representative') {
                $rep = $this->repFromInput($in, $agencyId);

                return $rep + ['type' => 'representative', 'contact_id' => null, 'id_number_seen' => null, 'method' => TitleDeed::METHOD_PIN, 'needs_letter' => false, 'note' => null];
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
        if (! TitleDeedSetting::representativesAllowedFor($agencyId)) {
            throw new TitleDeedException('Representatives are not allowed to collect for this agency.');
        }
        $mode = TitleDeedSetting::repWithoutPinModeFor($agencyId);
        if ($mode === TitleDeedSetting::REP_REFUSE) {
            throw new TitleDeedException('A representative cannot collect without the PIN at this agency.');
        }

        if (! empty($in['consent_id'])) {
            $consent = TitleDeedConsent::query()->lockForUpdate()->where('title_deed_id', $deed->id)->find((int) $in['consent_id']);
            if (! $consent || ! $consent->isUsableForHandover()) {
                throw new TitleDeedException('The owners\' consent is not valid any more (it was declined, has run out, or was used). Ask again.');
            }
        }

        $hasLetterInput = ($in['authority_letter'] ?? null) instanceof UploadedFile;
        $needsConsent = $mode === TitleDeedSetting::REP_OWNER_CONSENT;
        if ($needsConsent && ! $consent) {
            throw new TitleDeedException('A representative without the PIN needs an owner\'s consent first. Ask the owners to confirm.');
        }
        if (! $consent && ! $hasLetterInput) {
            throw new TitleDeedException('A representative without the PIN needs the owners\' consent or a signed authority letter.');
        }
        $needsLetter = TitleDeedSetting::authorityLetterRequiredFor($agencyId) || ! $consent;

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
        if (! TitleDeedSetting::representativesAllowedFor($agencyId)) {
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
    private function storeSignature(string $base64, int $deedId, array &$stored): string
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
