<?php

namespace App\Services\Compliance\Tfs;

use App\Models\Compliance\FicaTfsScreening;
use App\Models\Compliance\SanctionsListAlias;
use App\Models\Compliance\SanctionsListEntry;
use App\Models\Compliance\SanctionsListIdentifier;
use App\Models\Compliance\SanctionsListImport;
use App\Models\FicaSubmission;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Screen a FICA submission's Name + ID/Passport against the ingested sanctions list.
 *
 * Priority (favour false-flags over false-passes — NEVER silently pass a sanctioned party):
 *   1. exact ID/passport match  -> HIT (hard block)
 *   2. exact/alias/token name    -> REVIEW_REQUIRED (CO decides)
 *   3. no list / stale list      -> REVIEW_REQUIRED or ERROR (never auto-pass)
 *   4. clean                     -> PASSED (auto-clears ONLY if config trust_auto_pass is on)
 */
class TfsScreeningService
{
    public function screen(FicaSubmission $submission, ?User $actor = null): FicaTfsScreening
    {
        [$name, $idNumber, $dob, $kind] = $this->subjectOf($submission);

        $normName = TfsNormalizer::name($name);
        $normId   = TfsNormalizer::identifier($idNumber);

        $feeds = $this->operativeFeeds();
        $import = $this->freshestImport($feeds);

        $screening = new FicaTfsScreening([
            'fica_submission_id'       => $submission->id,
            'agency_id'                => $submission->agency_id,
            'subject_kind'             => $kind,
            'screened_name'            => $name,
            'screened_name_normalised' => $normName,
            'screened_id_number'       => $idNumber ?: null,
            'screened_id_normalised'   => $normId ?: null,
            'screened_dob'             => $dob,
            'import_id'                => $import?->id,
            'list_fetched_at'          => $import?->finished_at,
            'screened_by'              => $actor?->id,
            'screened_at'              => Carbon::now(),
            'auto_pass_trusted'        => false,
        ]);

        // ── No usable list at all -> cannot screen, never pass ───────────────
        if (! $import) {
            $screening->outcome = 'error';
            $screening->reason  = 'no_list';
            $screening->save();
            return $screening;
        }

        $stale = $import->finished_at
            && $import->finished_at->lt(Carbon::now()->subDays((int) config('tfs.max_staleness_days', 3)));

        // ── 1. Exact ID / passport match => HIT ──────────────────────────────
        $idHits = collect();
        if ($normId !== '') {
            $idHits = SanctionsListIdentifier::query()
                ->whereIn('source_feed', $feeds)
                ->where('normalised_value', $normId)
                ->with('entry')
                ->get();
        }
        if ($idHits->isNotEmpty()) {
            $screening->outcome     = 'hit';
            $screening->reason      = 'exact_id_match';
            $screening->match_count = $idHits->count();
            $screening->candidates  = $idHits->map(fn ($i) => $this->candidate($i->entry, 'ID/passport ' . $i->id_type . ': ' . $i->id_value))->values()->all();
            $screening->save();
            return $screening;
        }

        // ── 2. Name / alias / token match => REVIEW_REQUIRED ─────────────────
        $nameHits = $this->nameCandidates($normName, $feeds);
        if ($nameHits->isNotEmpty()) {
            $screening->outcome     = 'review_required';
            $screening->reason      = 'name_match';
            $screening->match_count = $nameHits->count();
            $screening->candidates  = $nameHits->map(function ($e) use ($dob) {
                $why = 'Name match';
                if ($dob && $e->date_of_birth && $e->date_of_birth->isSameDay($dob)) {
                    $why .= ' + DOB match';
                }
                return $this->candidate($e, $why);
            })->values()->all();
            $screening->save();
            return $screening;
        }

        // ── 3. Clean. Stale list can never auto-pass ─────────────────────────
        if ($stale) {
            $screening->outcome = 'review_required';
            $screening->reason  = 'list_stale';
            $screening->save();
            return $screening;
        }

        // ── 4. Clean + fresh => PASSED (auto-clears only if trusted) ─────────
        $screening->outcome           = 'passed';
        $screening->reason            = 'no_match';
        $screening->auto_pass_trusted = (bool) config('tfs.trust_auto_pass', false);
        $screening->save();
        return $screening;
    }

    /** Exact normalised name, alias match, and all-token containment. */
    private function nameCandidates(string $normName, array $feeds)
    {
        if ($normName === '') {
            return collect();
        }
        $ids = collect();

        // exact normalised name
        $ids = $ids->merge(
            SanctionsListEntry::whereIn('source_feed', $feeds)
                ->where('normalised_name', $normName)->pluck('id')
        );

        // exact alias
        $ids = $ids->merge(
            SanctionsListAlias::whereIn('source_feed', $feeds)
                ->where('normalised_alias', $normName)->pluck('entry_id')
        );

        // all significant tokens present (catches word-order / extra-token variants)
        $tokens = TfsNormalizer::tokens($normName);
        if (count($tokens) >= 2) {
            $q = SanctionsListEntry::whereIn('source_feed', $feeds);
            foreach ($tokens as $t) {
                $q->where('normalised_name', 'like', '%' . $t . '%');
            }
            $ids = $ids->merge($q->limit(25)->pluck('id'));
        }

        $ids = $ids->unique()->take(25);
        if ($ids->isEmpty()) {
            return collect();
        }
        return SanctionsListEntry::whereIn('id', $ids)->get();
    }

    private function candidate(?SanctionsListEntry $e, string $why): array
    {
        if (! $e) {
            return ['why' => $why];
        }
        return [
            'entry_id'    => $e->id,
            'ref'         => $e->external_ref,
            'kind'        => $e->record_kind,
            'name'        => $e->primary_name,
            'dob'         => optional($e->date_of_birth)->toDateString(),
            'nationality' => $e->nationality,
            'source_feed' => $e->source_feed,
            'why'         => $why,
        ];
    }

    /** Extract (name, id, dob, kind) from the submission's form_data / contact. */
    private function subjectOf(FicaSubmission $submission): array
    {
        $data = $submission->form_data ?? [];
        $personal = $data['personal'] ?? [];
        $entity   = $data['entity'] ?? [];

        if ($submission->entity_type && $submission->entity_type !== 'natural') {
            $name = $entity['company_name'] ?? $entity['trust_name'] ?? $entity['partnership_name'] ?? '';
            return [$name, '', null, 'entity'];
        }

        $name = $personal['full_name'] ?? $submission->contact?->full_name ?? '';
        $id   = $personal['id_number'] ?? $submission->contact?->id_number ?? '';
        $dob  = TfsNormalizer::parseDate($personal['date_of_birth'] ?? null);
        return [$name, $id, $dob ? Carbon::parse($dob) : null, 'individual'];
    }

    /** @return string[] operative feed keys */
    private function operativeFeeds(): array
    {
        return collect(config('tfs.feeds', []))
            ->filter(fn ($c) => $c['operative'] ?? false)
            ->keys()->all();
    }

    private function freshestImport(array $feeds): ?SanctionsListImport
    {
        return SanctionsListImport::whereIn('source_feed', $feeds)
            ->where('status', 'success')
            ->orderByDesc('finished_at')
            ->first();
    }
}
