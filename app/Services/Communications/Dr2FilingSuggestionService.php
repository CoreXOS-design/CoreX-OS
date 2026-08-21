<?php

namespace App\Services\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLearnedRef;
use App\Models\Deal;

/**
 * CX-113 Phase D (Johan, 2026-08-21) — "auto-suggest the deal from filing history.
 * Same reference filed to the same deal before should pre-fill the inline box with
 * the reason shown... Suggest, never auto-file." Reuses communication_learned_refs —
 * the SAME signal store CommunicationDealLinkingService::captureSignals() already
 * writes to on every manual link, and CommunicationDealLinkingService::
 * findRelatedUnfiled() already reads from for the post-filing suggestion popup. No
 * second store, no parallel mechanism.
 *
 * "Johan says this will be expanded a lot going forward, so build the suggestion
 * source so more signals can be added later" — hence the small, deliberately
 * indirected shape: candidates() returns one row per (signal_type, deal_id, hits)
 * match, scored and reduced to a single best answer in suggestFor(). Adding a new
 * signal type later is exactly "add another WHERE branch to candidates()" — nothing
 * about the scoring/reduction changes.
 */
class Dr2FilingSuggestionService
{
    /**
     * The single best deal suggestion for $communication, or null if nothing in its
     * learned signal history points anywhere. NEVER auto-files — purely advisory data
     * for the caller to pre-fill/highlight, still requires an explicit agent click.
     *
     * @return array{deal_id: int, label: string, reason: string}|null
     */
    public function suggestFor(Communication $communication): ?array
    {
        $candidates = $this->candidates($communication);
        if ($candidates->isEmpty()) {
            return null;
        }

        // Prefer the most SPECIFIC signal type first (a shared thread is a much
        // stronger "same matter" signal than a shared subject line), then within a
        // type prefer the one with the most prior hits.
        $specificity = [
            CommunicationLearnedRef::SIGNAL_THREAD_KEY      => 3,
            CommunicationLearnedRef::SIGNAL_SENDER_EMAIL    => 2,
            CommunicationLearnedRef::SIGNAL_SUBJECT_PATTERN => 1,
        ];
        $best = $candidates
            ->sortByDesc(fn ($c) => [$specificity[$c->signal_type] ?? 0, $c->hits])
            ->first();

        $deal = Deal::query()->where('deal_v2_id', $best->deal_id)->first();
        if (! $deal) {
            return null;
        }

        $times = (int) $best->hits;
        $what = match ($best->signal_type) {
            CommunicationLearnedRef::SIGNAL_THREAD_KEY      => 'this thread',
            CommunicationLearnedRef::SIGNAL_SENDER_EMAIL    => 'this sender',
            CommunicationLearnedRef::SIGNAL_SUBJECT_PATTERN => 'this reference',
            default => 'this signal',
        };
        $label = trim(($deal->deal_no ? "#{$deal->deal_no} · " : '') . ($deal->property_address ?: ''));

        return [
            'deal_id' => (int) $deal->id,
            'label'   => $label,
            'reason'  => $times === 1
                ? "1 previous email with {$what} was filed to {$label}"
                : "{$times} previous emails with {$what} were filed to {$label}",
        ];
    }

    /**
     * Every learned-signal match for $communication's own sender/thread/subject,
     * across all deals — the raw material suggestFor() scores and reduces. Adding a
     * new signal source later means adding another branch here (or another
     * CommunicationLearnedRef::SIGNAL_* type entirely) — the reduction step in
     * suggestFor() does not need to change.
     *
     * @return \Illuminate\Support\Collection<int, object{signal_type: string, deal_id: int, hits: int}>
     */
    private function candidates(Communication $communication): \Illuminate\Support\Collection
    {
        $wanted = [];
        if (filled($communication->from_identifier)) {
            $wanted[CommunicationLearnedRef::SIGNAL_SENDER_EMAIL] = CommunicationLearnedRef::normalizeValue($communication->from_identifier);
        }
        if (filled($communication->thread_key)) {
            $wanted[CommunicationLearnedRef::SIGNAL_THREAD_KEY] = CommunicationLearnedRef::normalizeValue($communication->thread_key);
        }
        if (filled($communication->subject)) {
            $wanted[CommunicationLearnedRef::SIGNAL_SUBJECT_PATTERN] = CommunicationLearnedRef::normalizeValue($communication->subject);
        }
        $wanted = array_filter($wanted, fn ($v) => $v !== '');
        if (empty($wanted)) {
            return collect();
        }

        return CommunicationLearnedRef::query()
            ->where('agency_id', $communication->agency_id)
            ->whereNotNull('deal_id')
            ->where(function ($q) use ($wanted) {
                foreach ($wanted as $type => $value) {
                    $q->orWhere(fn ($w) => $w->where('signal_type', $type)->where('signal_value', $value));
                }
            })
            ->get(['signal_type', 'deal_id', 'hits']);
    }
}
