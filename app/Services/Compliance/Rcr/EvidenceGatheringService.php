<?php

declare(strict_types=1);

namespace App\Services\Compliance\Rcr;

use App\Models\Agency;
use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Compliance\Rcr\RcrAnswer;
use App\Models\Compliance\Rcr\RcrQuestion;
use App\Models\Compliance\Rcr\RcrSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 9d D1 — auto-populates RCR answers from existing CoreX data.
 *
 * Each question on the questionnaire MAY declare an `auto_population_source`
 * (dotted string like 'agency.fica_officer.primary'). When set, this service
 * resolves the code to live data and writes an `auto_filled` answer that the
 * compliance officer reviews and either accepts or overrides.
 *
 * KEY DESIGN GUARANTEES:
 *   - Idempotent: re-running on a submission updates auto_filled answers but
 *     skips any answer where manually_edited=true (human judgement wins).
 *   - Failure-isolated: a single source resolution that throws does not
 *     abort the whole sweep; that question gets an error result.
 *   - Audit-friendly: every result records source_data + pulled_at so the
 *     CO can see "this was pulled on 12 Jul from these 7 fica_submissions".
 *
 * Sources implemented (the initial set — expandable by adding handler
 * methods following the resolveXxx() naming convention):
 *
 *   agency.profile
 *   agency.fica_officer.primary
 *   agency.fica_officer.mlro
 *   agency.fica_officer.alternate
 *   rmcp.exists
 *   rmcp.last_reviewed
 *   rmcp.sections_count
 *   rmcp.acknowledgements_complete_pct
 *   cdd.completed_in_period
 *   cdd.outstanding
 *   cdd.high_risk_count
 *   edd.pep_screenings
 *   edd.sanctions_screenings
 *   str.filed_count
 *   str.flagged_unfiled
 *   training.completed_pct
 *   training.modules_available
 *   training.last_session_date
 *   transactions.total_count
 *   transactions.total_value
 *   transactions.high_value_count
 *   transactions.foreign_party_count
 *   mandates.by_type
 *   mandates.cancelled_count
 *   governance.last_compliance_committee_meeting
 *   governance.compliance_reports_generated
 *   audit.last_independent_review
 *
 * Sources that point at data CoreX doesn't yet collect (STR table absent,
 * PEP screening table absent, etc.) return a "no_data_available" result
 * with a helpful manual-entry-required message — see the audit findings
 * captured in PART A.
 */
final class EvidenceGatheringService
{
    /**
     * Sweep every question on a submission. Returns the per-question results
     * for the caller to log / surface to the CO.
     *
     * @return array<int, AutoPopulationResult>
     */
    public function autoPopulate(RcrSubmission $submission): array
    {
        $results = [];
        $agency  = Agency::find($submission->agency_id);

        if (!$agency) {
            return [];
        }

        $questions = RcrQuestion::where('questionnaire_id', $submission->questionnaire_id)
            ->whereNotNull('auto_population_source')
            ->get();

        foreach ($questions as $question) {
            $result = $this->populateOne($submission, $question, $agency);
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Public for tests + manual re-population of a single question from the
     * UI. Writes the answer row + records the source data.
     */
    public function populateOne(RcrSubmission $submission, RcrQuestion $question, Agency $agency): AutoPopulationResult
    {
        $source = (string) $question->auto_population_source;

        // Skip-if-manually-edited (D2 guarantee).
        $existing = RcrAnswer::where('submission_id', $submission->id)
            ->where('question_id', $question->id)
            ->first();
        if ($existing && $existing->manually_edited) {
            return new AutoPopulationResult(
                questionId:    (int) $question->id,
                source:        $source,
                populated:     false,
                skipped:       true,
                skippedReason: 'manually_edited',
            );
        }

        try {
            $payload = $this->resolveSource($source, $submission, $agency);
        } catch (\Throwable $e) {
            Log::warning('rcr.autopopulate.error', [
                'submission_id' => $submission->id,
                'question_id'   => $question->id,
                'source'        => $source,
                'error'         => $e->getMessage(),
            ]);
            return new AutoPopulationResult(
                questionId: (int) $question->id,
                source:     $source,
                populated:  false,
                error:      $e->getMessage(),
            );
        }

        $result = new AutoPopulationResult(
            questionId: (int) $question->id,
            source:     $source,
            populated:  $payload['populated'] ?? false,
            value:      $payload['value']     ?? null,
            data:       $payload['data']      ?? [],
            error:      $payload['error']     ?? null,
        );

        $this->writeAnswer($submission, $question, $result);
        return $result;
    }

    /**
     * Map a dotted source code to an actual data resolver.
     *
     * @return array{populated:bool, value:mixed, data:array, error:?string}
     */
    private function resolveSource(string $source, RcrSubmission $submission, Agency $agency): array
    {
        return match ($source) {
            'agency.profile'                  => $this->resolveAgencyProfile($agency),
            'agency.fica_officer.primary'     => $this->resolveFicaOfficer($agency, FicaOfficerAppointment::ROLE_PRIMARY),
            'agency.fica_officer.mlro'        => $this->resolveFicaOfficer($agency, FicaOfficerAppointment::ROLE_MLRO),
            'agency.fica_officer.alternate'   => $this->resolveFicaOfficerAlternate($agency),
            'rmcp.exists'                     => $this->resolveRmcpExists($agency),
            'rmcp.last_reviewed'              => $this->resolveRmcpLastReviewed($agency),
            'rmcp.sections_count'             => $this->resolveRmcpSectionsCount($agency),
            'rmcp.acknowledgements_complete_pct' => $this->resolveRmcpAcknowledgementsPct($agency),
            'cdd.completed_in_period'         => $this->resolveCddCompletedInPeriod($submission),
            'cdd.outstanding'                 => $this->resolveCddOutstanding($agency),
            'cdd.high_risk_count'             => $this->resolveCddHighRiskCount($submission),
            'edd.pep_screenings'              => $this->resolveNoDataAvailable('PEP screening records not tracked in CoreX as a structured table — see RMCP Section 17 for manual TFS process.'),
            'edd.sanctions_screenings'        => $this->resolveNoDataAvailable('Sanctions list screening not tracked in CoreX as a structured table — manual workflow via tfs.fic.gov.za.'),
            'str.filed_count'                 => $this->resolveNoDataAvailable('Suspicious Transaction Reports filed externally via goAML — internal STR intake table not yet implemented.'),
            'str.flagged_unfiled'             => $this->resolveNoDataAvailable('Internal STR flagging table not yet implemented.'),
            'training.completed_pct'          => $this->resolveTrainingCompletedPct($agency, $submission),
            'training.modules_available'      => $this->resolveTrainingModulesAvailable($agency),
            'training.last_session_date'      => $this->resolveTrainingLastSessionDate($agency),
            'transactions.total_count'        => $this->resolveTransactionsCount($submission, 'total'),
            'transactions.total_value'        => $this->resolveTransactionsValue($submission),
            'transactions.high_value_count'   => $this->resolveTransactionsCount($submission, 'high_value'),
            'transactions.foreign_party_count' => $this->resolveNoDataAvailable('Foreign-party flag not yet captured on deals/contacts — manual entry required.'),
            'mandates.by_type'                => $this->resolveMandatesByType($agency, $submission),
            'mandates.cancelled_count'        => $this->resolveNoDataAvailable('Mandate cancellation log not yet first-class (see compliance audit Phase 9b — Mandate model on roadmap).'),
            'governance.last_compliance_committee_meeting' => $this->resolveNoDataAvailable('Compliance committee minute log not tracked in CoreX yet — manual entry required.'),
            'governance.compliance_reports_generated' => $this->resolveNoDataAvailable('Monthly compliance reports not yet automated — manual count required.'),
            'audit.last_independent_review'   => $this->resolveNoDataAvailable('Independent compliance review log not tracked in CoreX yet — manual entry required.'),
            default => [
                'populated' => false,
                'error' => 'Unknown auto-population source code: ' . $source,
                'data'  => [],
            ],
        };
    }

    // ── Resolvers ──────────────────────────────────────────────────────────

    private function resolveAgencyProfile(Agency $agency): array
    {
        $data = [
            'name'           => $agency->name ?? null,
            'ffc_no'         => $agency->ffc_no ?? null,
            'fic_no'         => $agency->fic_no ?? null,
            'address'        => $agency->address ?? null,
            'phone'          => $agency->phone ?? null,
            'email'          => $agency->email ?? null,
            'branches_count' => DB::table('branches')->where('agency_id', $agency->id)->count(),
        ];
        $value = ($data['name'] ?? '') . ' (FFC ' . ($data['ffc_no'] ?: 'n/a') . ')';
        return ['populated' => true, 'value' => $value, 'data' => $data];
    }

    private function resolveFicaOfficer(Agency $agency, string $role): array
    {
        $officer = FicaOfficerAppointment::where('agency_id', $agency->id)
            ->where('role', $role)
            ->whereNull('ended_on')
            ->latest('appointed_on')
            ->first();
        if (!$officer) {
            return ['populated' => false, 'error' => "No active {$role} appointed for this agency.", 'data' => []];
        }
        return [
            'populated' => true,
            'value'     => trim($officer->full_name . ' (' . $officer->title . ', appointed ' . optional($officer->appointed_on)->toDateString() . ')'),
            'data'      => [
                'appointment_id' => $officer->id,
                'full_name'      => $officer->full_name,
                'title'          => $officer->title,
                'email'          => $officer->email,
                'cell'           => $officer->cell,
                'appointed_on'   => optional($officer->appointed_on)->toDateString(),
            ],
        ];
    }

    private function resolveFicaOfficerAlternate(Agency $agency): array
    {
        // No 'alternate' role in the enum; count any additional non-primary
        // appointments as a soft proxy for the alternate concept.
        $count = FicaOfficerAppointment::where('agency_id', $agency->id)
            ->whereNotIn('role', [FicaOfficerAppointment::ROLE_PRIMARY])
            ->whereNull('ended_on')
            ->count();
        if ($count === 0) {
            return ['populated' => false, 'error' => 'No alternate compliance officer appointed.', 'data' => []];
        }
        return [
            'populated' => true,
            'value'     => $count . ' additional compliance officer' . ($count === 1 ? '' : 's') . ' appointed.',
            'data'      => ['count' => $count],
        ];
    }

    private function resolveRmcpExists(Agency $agency): array
    {
        $hasActive = DB::table('rmcp_versions')
            ->where('agency_id', $agency->id)
            ->where('status', 'active')
            ->whereNull('superseded_at')
            ->exists();
        return [
            'populated' => true,
            'value'     => $hasActive ? 'Yes' : 'No',
            'data'      => ['has_active_rmcp' => $hasActive],
        ];
    }

    private function resolveRmcpLastReviewed(Agency $agency): array
    {
        $v = DB::table('rmcp_versions')
            ->where('agency_id', $agency->id)
            ->where('status', 'active')
            ->orderByDesc('effective_from')
            ->first();
        if (!$v) {
            return ['populated' => false, 'error' => 'No RMCP version found.', 'data' => []];
        }
        return [
            'populated' => true,
            'value'     => $v->effective_from,
            'data'      => [
                'version_id'      => $v->id,
                'version_number'  => $v->version_number,
                'effective_from'  => $v->effective_from,
                'next_review_due' => $v->next_review_due,
            ],
        ];
    }

    private function resolveRmcpSectionsCount(Agency $agency): array
    {
        $activeId = DB::table('rmcp_versions')
            ->where('agency_id', $agency->id)
            ->where('status', 'active')
            ->value('id');
        if (!$activeId) {
            return ['populated' => false, 'error' => 'No active RMCP version found.', 'data' => []];
        }
        $count = DB::table('rmcp_sections')->where('rmcp_version_id', $activeId)->count();
        return ['populated' => true, 'value' => $count, 'data' => ['rmcp_version_id' => $activeId, 'count' => $count]];
    }

    private function resolveRmcpAcknowledgementsPct(Agency $agency): array
    {
        $activeId = DB::table('rmcp_versions')
            ->where('agency_id', $agency->id)
            ->where('status', 'active')
            ->value('id');
        if (!$activeId) {
            return ['populated' => false, 'error' => 'No active RMCP version found.', 'data' => []];
        }
        $required = DB::table('users')
            ->where('agency_id', $agency->id)
            ->where('is_active', true)
            ->count();
        if ($required === 0) {
            return ['populated' => true, 'value' => '100%', 'data' => ['required' => 0, 'completed' => 0]];
        }
        $completed = DB::table('rmcp_acknowledgements')
            ->where('rmcp_version_id', $activeId)
            ->where('status', 'completed')
            ->count();
        $pct = round($completed / $required * 100, 1);
        return [
            'populated' => true,
            'value'     => $pct . '%',
            'data'      => ['required' => $required, 'completed' => $completed, 'pct' => $pct],
        ];
    }

    private function resolveCddCompletedInPeriod(RcrSubmission $submission): array
    {
        $count = DB::table('fica_submissions')
            ->where('agency_id', $submission->agency_id)
            ->whereIn('status', ['agent_approved', 'approved'])
            ->whereBetween('created_at', [$submission->reporting_period_from, $submission->reporting_period_to->endOfDay()])
            ->count();
        return [
            'populated' => true,
            'value'     => $count,
            'data'      => ['count' => $count, 'window_from' => $submission->reporting_period_from->toDateString(), 'window_to' => $submission->reporting_period_to->toDateString()],
        ];
    }

    private function resolveCddOutstanding(Agency $agency): array
    {
        $count = DB::table('fica_submissions')
            ->where('agency_id', $agency->id)
            ->whereIn('status', ['draft', 'submitted', 'corrections_requested'])
            ->count();
        return ['populated' => true, 'value' => $count, 'data' => ['count' => $count]];
    }

    private function resolveCddHighRiskCount(RcrSubmission $submission): array
    {
        if (!\Schema::hasColumn('fica_submissions', 'risk_rating')) {
            return $this->resolveNoDataAvailable('FicaSubmission.risk_rating not yet populated by workflow — manual entry required.');
        }
        $count = DB::table('fica_submissions')
            ->where('agency_id', $submission->agency_id)
            ->where('risk_rating', '>=', 3)
            ->whereBetween('created_at', [$submission->reporting_period_from, $submission->reporting_period_to->endOfDay()])
            ->count();
        return ['populated' => true, 'value' => $count, 'data' => ['count' => $count, 'threshold' => 'risk_rating >= 3']];
    }

    private function resolveTrainingCompletedPct(Agency $agency, RcrSubmission $submission): array
    {
        $required = DB::table('users')->where('agency_id', $agency->id)->where('is_active', true)->count();
        if ($required === 0) {
            return ['populated' => true, 'value' => '100%', 'data' => ['required' => 0]];
        }
        $completed = DB::table('training_completions')
            ->join('users', 'users.id', '=', 'training_completions.user_id')
            ->where('users.agency_id', $agency->id)
            ->whereNotNull('training_completions.completed_at')
            ->distinct('training_completions.user_id')
            ->count('training_completions.user_id');
        $pct = round($completed / $required * 100, 1);
        return ['populated' => true, 'value' => $pct . '%', 'data' => ['required' => $required, 'completed' => $completed]];
    }

    private function resolveTrainingModulesAvailable(Agency $agency): array
    {
        $modules = DB::table('training_courses')
            ->where('is_active', true)
            ->select('id', 'title', 'category', 'is_required')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
        return [
            'populated' => true,
            'value'     => count($modules) . ' modules available',
            'data'      => ['modules' => $modules, 'count' => count($modules)],
        ];
    }

    private function resolveTrainingLastSessionDate(Agency $agency): array
    {
        $last = DB::table('training_completions')
            ->join('users', 'users.id', '=', 'training_completions.user_id')
            ->where('users.agency_id', $agency->id)
            ->max('training_completions.completed_at');
        if (!$last) {
            return ['populated' => false, 'error' => 'No training completions recorded yet for this agency.', 'data' => []];
        }
        return ['populated' => true, 'value' => $last, 'data' => ['last_completed_at' => $last]];
    }

    private function resolveTransactionsCount(RcrSubmission $submission, string $variant): array
    {
        $q = DB::table('deals')
            ->where('agency_id', $submission->agency_id)
            ->whereBetween('created_at', [$submission->reporting_period_from, $submission->reporting_period_to->endOfDay()]);
        if ($variant === 'high_value') {
            $q->where(function ($qq) {
                $qq->where('sale_price', '>=', 200_000)
                   ->orWhere('property_value', '>=', 200_000);
            });
        }
        $count = $q->count();
        return ['populated' => true, 'value' => $count, 'data' => ['count' => $count, 'variant' => $variant]];
    }

    private function resolveTransactionsValue(RcrSubmission $submission): array
    {
        $sumSale = DB::table('deals')
            ->where('agency_id', $submission->agency_id)
            ->whereBetween('created_at', [$submission->reporting_period_from, $submission->reporting_period_to->endOfDay()])
            ->sum(DB::raw('COALESCE(sale_price, property_value)'));
        $val = (int) $sumSale;
        return [
            'populated' => true,
            'value'     => 'R ' . number_format($val, 0, '.', ' '),
            'data'      => ['total_rand' => $val],
        ];
    }

    private function resolveMandatesByType(Agency $agency, RcrSubmission $submission): array
    {
        $rows = DB::table('properties')
            ->where('agency_id', $agency->id)
            ->whereBetween('created_at', [$submission->reporting_period_from, $submission->reporting_period_to->endOfDay()])
            ->whereNotNull('mandate_type')
            ->selectRaw('mandate_type, COUNT(*) as n')
            ->groupBy('mandate_type')
            ->pluck('n', 'mandate_type')
            ->toArray();
        $summary = empty($rows)
            ? 'No mandates in period.'
            : collect($rows)->map(fn ($n, $t) => "{$t}: {$n}")->values()->implode(', ');
        return ['populated' => true, 'value' => $summary, 'data' => ['by_type' => $rows]];
    }

    /**
     * Helper for sources that point at data we don't yet collect. Returns a
     * non-populated result with a clear "manual entry required" message so
     * the CO sees it in the UI without scratching their head.
     */
    private function resolveNoDataAvailable(string $reason): array
    {
        return [
            'populated' => false,
            'error'     => 'Manual entry required — ' . $reason,
            'data'      => ['reason' => $reason],
        ];
    }

    // ── Persistence ────────────────────────────────────────────────────────

    private function writeAnswer(RcrSubmission $submission, RcrQuestion $question, AutoPopulationResult $result): void
    {
        if ($result->skipped) return;

        $existing = RcrAnswer::firstOrNew([
            'submission_id' => $submission->id,
            'question_id'   => $question->id,
        ]);

        // Even if the source couldn't populate, we still write the row so the
        // CO sees the failed-attempt message in the UI.
        $existing->fill([
            'submission_id'              => $submission->id,
            'question_id'                => $question->id,
            'answer_value'               => $result->populated ? (string) ($result->value ?? '') : ($existing->answer_value ?? null),
            'is_auto_populated'          => $result->populated,
            'auto_population_source_data' => $result->toLogArray(),
            'status'                     => $result->populated
                ? RcrAnswer::STATUS_AUTO_FILLED
                : ($existing->status ?: RcrAnswer::STATUS_UNANSWERED),
        ]);
        $existing->save();
    }
}
