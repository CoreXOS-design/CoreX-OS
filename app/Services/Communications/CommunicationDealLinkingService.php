<?php

namespace App\Services\Communications;

use App\Exceptions\Communications\AlreadyFiledException;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationLearnedRef;
use App\Models\Communications\CommunicationLink;
use App\Models\Deal;
use App\Models\DealV2\DealV2;
use App\Models\Document;
use App\Models\User;
use App\Services\Communications\CommunicationStorageService;
use App\Services\DealV2\DealDocumentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * CX-109 (Johan, 2026-08-20) — the manual email-to-deal linking transaction, extracted
 * so the Unfiled Emails screen and the in-deal "Linked Emails" tab (CX-108) share ONE
 * linking path instead of two copies of the same transaction. Behaviour is unchanged
 * from CX-108: link_method=manual, confirmed_at set immediately (an agent looked at it
 * and said yes — no separate confirm step), signals captured into
 * communication_learned_refs with is_verified left false (that flag is AT-231's
 * attorney-route silent auto-file, a different, narrower mechanism this does not touch).
 *
 * Also carries the SUGGESTION lookup for the Unfiled Emails screen (Johan: "if there are
 * any other emails that have the same subject / reference / recipients we can then
 * highlight as this could belong to this deal"). Deliberately reuses
 * communication_learned_refs — no second store. Matches on the same three signal types
 * captureSignals() already writes (sender_email, thread_key, subject_pattern); a
 * standalone "matter reference number" extraction is not attempted here — subject_pattern
 * (the whole normalised subject) is the closest existing signal to "reference" and often
 * carries it, since agency subjects tend to include the property/matter description.
 */
class CommunicationDealLinkingService
{
    public function __construct(
        private DealDocumentService $documents,
        private CommunicationStorageService $storage,
    ) {}

    /**
     * Attach $communication to $dealV2Id, capture signals, file its attachments into
     * the deal's document library, and return the (created or restored) link. One
     * transaction — a link with no signal, or a signal with no link, is a half-done
     * operation either way.
     *
     * CX-113 Phase A (Johan, 2026-08-21) — "file once": filing files the email
     * itself, not the filer's copy. Locks the Communication row first so two
     * simultaneous filers of the SAME still-unfiled email are serialized — the
     * second transaction blocks until the first commits, then re-reads the
     * now-current link state rather than racing it. If the email already carries
     * an active link to a DIFFERENT deal, the second filer is refused with
     * AlreadyFiledException (never a silent second link) unless $move is true, in
     * which case the old link is released and the new one takes its place.
     *
     * CX-114 (Johan, 2026-08-22) — Comms Suspense files an email's attachments into
     * the deal's real document library on file; DR2's filing didn't. Reuses
     * DealDocumentService (the SAME service Comms Suspense's own fileToDeal() calls)
     * — no second attachment-filing path. See fileAttachments() below.
     */
    public function link(Communication $communication, int $dealV2Id, ?int $agencyId, User $user, bool $move = false): CommunicationLink
    {
        return DB::transaction(function () use ($communication, $dealV2Id, $agencyId, $user, $move) {
            Communication::query()->whereKey($communication->id)->lockForUpdate()->first();

            // CX-113 Phase H (Johan, 2026-08-22) — a PROVISIONAL link (confirmed_at
            // null — e.g. the AT-231 correspondence pipeline's suggested-deal guess for
            // a no-contact sender) is a machine guess, not a filing decision, so it must
            // never block or force "move" on the agent's actual choice. Only a CONFIRMED
            // link to a different deal is a real conflict.
            $existingOther = CommunicationLink::where('communication_id', $communication->id)
                ->where('linkable_type', DealV2::class)
                ->where('linkable_id', '!=', $dealV2Id)
                ->whereNotNull('confirmed_at')
                ->first();

            if ($existingOther && ! $move) {
                throw new AlreadyFiledException($communication, $existingOther);
            }

            if ($existingOther && $move) {
                // CX-114 — a document on the wrong deal is worse than no document.
                // Withdraw everything THIS communication's attachments filed onto the
                // OLD deal (soft, recoverable — the exact shape of Comms Suspense's own
                // reassign(): withdrawFiling() then fileToDeal() to the new deal) before
                // the old link is even dropped, so there is never a window where the
                // documents exist un-attributed to any current link.
                $this->withdrawAttachmentDocuments($communication);
                $existingOther->delete();
            }

            // A stale PROVISIONAL link to a different deal (the machine guessed wrong,
            // the agent picked a different one) is soft-deleted here too — never left to
            // linger as an orphan a later query (e.g. the guessed deal's own Linked
            // Emails tab) could pick up and show as if it meant something.
            CommunicationLink::where('communication_id', $communication->id)
                ->where('linkable_type', DealV2::class)
                ->where('linkable_id', '!=', $dealV2Id)
                ->whereNull('confirmed_at')
                ->delete();

            // withTrashed so re-linking something previously unlinked from this SAME deal
            // restores the one row rather than accumulating duplicates.
            $link = CommunicationLink::withTrashed()
                ->where('communication_id', $communication->id)
                ->where('linkable_type', DealV2::class)
                ->where('linkable_id', $dealV2Id)
                ->first();

            if ($link) {
                $link->restore();
                $link->update([
                    'link_method'  => CommunicationLink::METHOD_MANUAL,
                    'confirmed_by' => $user->id,
                    'confirmed_at' => now(),
                ]);
            } else {
                $link = CommunicationLink::create([
                    'agency_id'        => $agencyId,
                    'communication_id' => $communication->id,
                    'linkable_type'    => DealV2::class,
                    'linkable_id'      => $dealV2Id,
                    'link_method'      => CommunicationLink::METHOD_MANUAL,
                    'confirmed_by'     => $user->id,
                    'confirmed_at'     => now(),
                ]);
            }

            $this->captureSignals($communication, $dealV2Id, $agencyId);
            $attachmentSummary = $this->fileAttachments($communication, $dealV2Id, $user);

            // Ephemeral — not a real column, not persisted. A cheap way for the
            // caller to read "did this filing half-work?" without changing link()'s
            // return type (findRelatedUnfiled and every other existing caller is
            // unaffected if it never reads this attribute).
            $link->setAttribute('attachment_filing', $attachmentSummary);

            return $link;
        });
    }

    /**
     * Other STILL-UNFILED emails whose sender, thread, or subject matches a signal
     * already learned for $dealV2Id (including the one just captured by link() above,
     * since that call happens first). "Unfiled" = no non-trashed CommunicationLink to
     * ANY deal — not just this one, matching the Unfiled Emails screen's own definition.
     * Suggest only — never auto-files anything (Johan, explicit: "Do NOT auto-file
     * them. Suggest, let the agent confirm").
     */
    public function findRelatedUnfiled(?int $agencyId, int $dealV2Id, ?int $excludeCommunicationId = null): Collection
    {
        $signals = CommunicationLearnedRef::query()
            ->where('agency_id', $agencyId)
            ->where('deal_id', $dealV2Id)
            ->whereIn('signal_type', [
                CommunicationLearnedRef::SIGNAL_SENDER_EMAIL,
                CommunicationLearnedRef::SIGNAL_THREAD_KEY,
                CommunicationLearnedRef::SIGNAL_SUBJECT_PATTERN,
            ])
            ->get(['signal_type', 'signal_value'])
            ->groupBy('signal_type')
            ->map(fn ($rows) => $rows->pluck('signal_value')->all());

        $senderValues  = $signals->get(CommunicationLearnedRef::SIGNAL_SENDER_EMAIL, []);
        $threadValues  = $signals->get(CommunicationLearnedRef::SIGNAL_THREAD_KEY, []);
        $subjectValues = $signals->get(CommunicationLearnedRef::SIGNAL_SUBJECT_PATTERN, []);

        if (empty($senderValues) && empty($threadValues) && empty($subjectValues)) {
            return new Collection();
        }

        return Communication::query()
            ->where('agency_id', $agencyId)
            ->where('channel', Communication::CHANNEL_EMAIL)
            ->when($excludeCommunicationId, fn ($q) => $q->where('id', '!=', $excludeCommunicationId))
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('communication_links')
                    ->whereColumn('communication_links.communication_id', 'communications.id')
                    ->where('communication_links.linkable_type', DealV2::class)
                    ->whereNull('communication_links.deleted_at');
            })
            ->where(function ($q) use ($senderValues, $threadValues, $subjectValues) {
                if (! empty($senderValues)) {
                    $q->orWhereIn(DB::raw('LOWER(TRIM(from_identifier))'), $senderValues);
                }
                if (! empty($threadValues)) {
                    $q->orWhereIn(DB::raw('LOWER(TRIM(thread_key))'), $threadValues);
                }
                if (! empty($subjectValues)) {
                    $q->orWhereIn(DB::raw('LOWER(TRIM(subject))'), $subjectValues);
                }
            })
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get();
    }

    /**
     * Signal capture (AT-231's store, reused). Deliberately minimal — exactly the
     * fields already sitting on the Communication row, no inference/regex heuristics
     * for a reference number. That belongs to whatever builds the suggestion engine's
     * next iteration, not here.
     */
    private function captureSignals(Communication $communication, int $dealId, ?int $agencyId): void
    {
        $signals = [];

        if (filled($communication->from_identifier)) {
            $signals[] = [
                CommunicationLearnedRef::SIGNAL_SENDER_EMAIL,
                CommunicationLearnedRef::normalizeValue($communication->from_identifier),
            ];
        }
        if (filled($communication->thread_key)) {
            $signals[] = [
                CommunicationLearnedRef::SIGNAL_THREAD_KEY,
                CommunicationLearnedRef::normalizeValue($communication->thread_key),
            ];
        }
        if (filled($communication->subject)) {
            $signals[] = [
                CommunicationLearnedRef::SIGNAL_SUBJECT_PATTERN,
                CommunicationLearnedRef::normalizeValue($communication->subject),
            ];
        }

        foreach ($signals as [$type, $value]) {
            if ($value === '') {
                continue;
            }
            // Unique on (agency_id, signal_type, signal_value) — a re-link of the same
            // signal bumps hits on the SAME row rather than duplicating it. deleted_at
            // is intentionally not fillable on this model, so a previously-trashed row
            // needs an explicit restore(), not updateOrCreate()'s mass-assignment
            // (which would silently no-op it).
            $ref = CommunicationLearnedRef::withTrashed()
                ->where('agency_id', $agencyId)
                ->where('signal_type', $type)
                ->where('signal_value', $value)
                ->first();

            if ($ref) {
                if ($ref->trashed()) {
                    $ref->restore();
                }
                $ref->deal_id = $dealId;
                $ref->save();
            } else {
                $ref = CommunicationLearnedRef::create([
                    'agency_id'    => $agencyId,
                    'deal_id'      => $dealId,
                    'signal_type'  => $type,
                    'signal_value' => $value,
                    'is_verified'  => false,
                    'hits'         => 0,
                ]);
            }

            $ref->increment('hits');
        }
    }

    // ──────────────────────── CX-114: attachment filing ────────────────────────

    /**
     * File $communication's attachments into the deal's real document library —
     * reuses DealDocumentService::fileDealDocumentFromDeal(), the SAME method
     * Comms Suspense's own CorrespondenceFilingService::fileToDeal() calls. No
     * second attachment-filing path.
     *
     * Idempotent by construction: isDuplicateOnDeal() means calling this again for
     * a communication already fully filed to this deal (e.g. link() called twice)
     * finds every attachment already produces a live document and skips all of
     * them — never accumulates duplicates.
     *
     * One bad attachment (missing/corrupt blob, storage failure) is skipped and
     * logged — it must never swallow the whole filing action, mirroring Comms
     * Suspense's own "absorb a missing blob — never break the file".
     *
     * JUNK FILTER (Johan's ruling, 2026-08-22): "only ingest pdf documents". Non-PDF
     * attachments (signature/logo images, but also anything else non-PDF) are never
     * filed as deal documents — they are NOT deleted anywhere; the email itself still
     * carries them untouched. Verified by the file's own magic bytes (isPdf() below),
     * not by filename or the sender-supplied mime — a mislabelled file must not slip
     * through, and a genuine PDF with an odd filename or wrong mime must not be
     * excluded. Measured on staging (2026-08-22, email-channel attachments): 431/2198
     * (19.6%) are genuinely PDF; of the 1767 excluded, 1574 match the classic Outlook
     * inline-signature naming (imageNNN.jpg/png, image.png, noname, RSImage*) and 193
     * do not — that residual includes a small number of real paperwork sent as
     * Word/Excel (e.g. body-corporate letters, rental schedules, asking-price lists)
     * and some ID-document photos (e.g. "Trevor ID front.jpg") that this rule
     * deliberately excludes from the document library per Johan's explicit ruling.
     *
     * Returns a per-communication filing summary (filed / skipped_duplicate /
     * skipped_non_pdf / failed) so the caller can surface a half-worked filing to the
     * agent instead of a silent "ok" — a filing where 2 of 3 attachments failed must
     * say so (Johan, 2026-08-22).
     *
     * @return array{filed:int,skipped_duplicate:int,skipped_non_pdf:int,failed:int}
     */
    private function fileAttachments(Communication $communication, int $dealV2Id, User $user): array
    {
        $summary = ['filed' => 0, 'skipped_duplicate' => 0, 'skipped_non_pdf' => 0, 'failed' => 0];

        $dr1Deal = Deal::where('deal_v2_id', $dealV2Id)->first();
        if (! $dr1Deal) {
            // No DR1 twin for this DealV2 yet — nothing to file into. The comm->deal
            // link above still stands; attachments simply have nowhere to land until
            // the twin exists.
            return $summary;
        }

        $attachments = $communication->attachments()
            ->whereNull('deleted_at')
            ->where('media_status', CommunicationAttachment::MEDIA_STORED)
            ->get();

        foreach ($attachments as $attachment) {
            try {
                if ($this->isDuplicateOnDeal((string) $attachment->content_hash, $dealV2Id)) {
                    $summary['skipped_duplicate']++;
                    continue;
                }

                $bytes = $this->storage->get((string) $attachment->storage_path);
                if ($bytes === null) {
                    $summary['failed']++;
                    continue; // absorb a missing blob — never break the filing
                }

                if (! $this->isPdf($bytes)) {
                    $summary['skipped_non_pdf']++;
                    continue; // not deleted anywhere — the email still carries it
                }

                $disk = 'local';
                $path = 'deals/' . $dr1Deal->id . '/correspondence/' . Str::random(12) . '_' . $this->safeName($attachment->filename);
                Storage::disk($disk)->put($path, $bytes);

                $doc = $this->documents->fileDealDocumentFromDeal($dr1Deal, [
                    'original_name' => $attachment->filename ?: 'attachment',
                    'storage_path'  => $path,
                    'disk'          => $disk,
                    'mime_type'     => 'application/pdf', // verified by magic bytes above, not trusted from the sender
                    'size'          => (int) $attachment->size_bytes,
                    'source_type'   => 'inbound_email',
                ], $user);

                // Provenance: THIS attachment (not just this communication — see
                // source_attachment_id docblock on the migration for why the
                // distinction matters) produced THIS document, via attachment
                // filing specifically (method distinct from Comms Suspense's
                // attorney_ref) — drives withdrawAttachmentDocuments() on
                // move/unlink and isDuplicateOnDeal() above.
                CommunicationLink::create([
                    'agency_id'             => $communication->agency_id,
                    'communication_id'      => $communication->id,
                    'linkable_type'         => Document::class,
                    'linkable_id'           => $doc->id,
                    'source_attachment_id'  => $attachment->id,
                    'link_method'           => CommunicationLink::METHOD_ATTACHMENT,
                    'confidence'            => 100,
                    'confirmed_by'          => $user->id,
                    'confirmed_at'          => now(),
                ]);

                $summary['filed']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                Log::warning('DR2 attachment filing failed for one attachment — filing continued', [
                    'communication_id' => $communication->id,
                    'attachment_id'    => $attachment->id,
                    'error'            => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * True content check, not filename/mime — a PDF's own magic number is the first
     * 5 bytes of the file, `%PDF-`, per the PDF spec. Sender-supplied mime and
     * filename extension are both untrusted (Johan, 2026-08-22: "a mislabelled file
     * should not slip through and a correctly-formed PDF with an odd filename should
     * not be excluded").
     */
    private function isPdf(string $bytes): bool
    {
        return str_starts_with($bytes, '%PDF-');
    }

    /**
     * Duplicates (Johan's question, 2026-08-22): the same attachment content
     * (content_hash — SHA-256, already computed at ingest) already filed to THIS
     * deal is skipped, not re-filed. Scoped per deal, not global — the same file
     * legitimately filed to two different deals is not a duplicate.
     *
     * Attachment-precise via source_attachment_id (2026-08-22 fix — found during
     * verification): an EARLIER version of this method matched on "does the
     * PRODUCING COMMUNICATION have any attachment with this hash", which is a
     * false-positive trap for a multi-attachment email — once its first attachment
     * files, the check trivially matches that SAME attachment against itself for
     * every subsequent attachment on the same email (a communication that "produced
     * a document" always "has an attachment" with the just-filed hash — that's just
     * the first attachment matching itself), silently skipping every attachment
     * after the first regardless of whether its content had ever actually been
     * filed. Matching on source_attachment_id's own content_hash instead of the
     * producing communication's full attachment set is exact.
     */
    private function isDuplicateOnDeal(string $contentHash, int $dealV2Id): bool
    {
        if ($contentHash === '') {
            return false; // never block filing on an unhashed attachment
        }

        return CommunicationLink::where('linkable_type', Document::class)
            ->where('link_method', CommunicationLink::METHOD_ATTACHMENT)
            ->whereNull('deleted_at')
            ->whereIn('linkable_id', function ($q) use ($dealV2Id) {
                $q->select('id')->from('documents')->where('deal_id', $dealV2Id)->whereNull('deleted_at');
            })
            ->whereIn('source_attachment_id', function ($q) use ($contentHash) {
                $q->select('id')->from('communication_attachments')->where('content_hash', $contentHash);
            })
            ->exists();
    }

    /**
     * Withdraw (soft-delete) every document THIS communication's attachments
     * produced, and the provenance links themselves — the move/removal answer.
     * Mirrors CorrespondenceFilingService::withdrawFiling() exactly: soft, no
     * orphan, no hard delete (Non-Negotiable #1). Deliberately scoped to
     * link_method=attachment only — never touches a Document linked here for any
     * other reason.
     */
    private function withdrawAttachmentDocuments(Communication $communication): void
    {
        $links = CommunicationLink::where('communication_id', $communication->id)
            ->where('linkable_type', Document::class)
            ->where('link_method', CommunicationLink::METHOD_ATTACHMENT)
            ->whereNull('deleted_at')
            ->get();

        foreach ($links as $link) {
            $doc = Document::withoutGlobalScopes()->find($link->linkable_id);
            $doc?->delete(); // soft — recoverable, no orphan
            $link->delete();
        }
    }

    /**
     * Removal (Johan's question, 2026-08-22): unlinking a filed email withdraws
     * its filed documents too — the SAME reversible mechanism as a move, just
     * without a re-file afterwards. Called by Dr2CommunicationLinkController::
     * unlink() instead of a bare $link->delete(), so removal was never silently
     * left as dead code on the document side.
     */
    public function unlink(CommunicationLink $link): void
    {
        DB::transaction(function () use ($link) {
            $communication = $link->communication;
            if ($communication) {
                $this->withdrawAttachmentDocuments($communication);
            }
            $link->delete();
        });
    }

    private function safeName(?string $name): string
    {
        $name = $name ?: 'attachment';

        return preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'attachment';
    }
}
