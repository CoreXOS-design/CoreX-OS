<?php

namespace App\Services\Communications;

use App\Exceptions\Communications\AlreadyFiledException;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLearnedRef;
use App\Models\Communications\CommunicationLink;
use App\Models\DealV2\DealV2;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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
    /**
     * Attach $communication to $dealV2Id, capture signals, return the (created or
     * restored) link. One transaction — a link with no signal, or a signal with no
     * link, is a half-done operation either way.
     *
     * CX-113 Phase A (Johan, 2026-08-21) — "file once": filing files the email
     * itself, not the filer's copy. Locks the Communication row first so two
     * simultaneous filers of the SAME still-unfiled email are serialized — the
     * second transaction blocks until the first commits, then re-reads the
     * now-current link state rather than racing it. If the email already carries
     * an active link to a DIFFERENT deal, the second filer is refused with
     * AlreadyFiledException (never a silent second link) unless $move is true, in
     * which case the old link is released and the new one takes its place.
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
}
