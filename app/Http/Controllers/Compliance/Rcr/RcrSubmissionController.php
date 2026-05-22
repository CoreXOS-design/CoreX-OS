<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compliance\Rcr;

use App\Http\Controllers\Controller;
use App\Models\Compliance\Rcr\RcrAnswer;
use App\Models\Compliance\Rcr\RcrAnswerEvidence;
use App\Models\Compliance\Rcr\RcrQuestion;
use App\Models\Compliance\Rcr\RcrQuestionnaire;
use App\Models\Compliance\Rcr\RcrSubmission;
use App\Services\Compliance\Rcr\EvidenceGatheringService;
use App\Services\Compliance\Rcr\RcrExportService;
use App\Services\Compliance\Rcr\RcrSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Phase 9d — agent-facing controller for the RCR drafting workflow.
 *
 * Access: compliance_officer / admin / branch_manager / principal /
 * super_admin (the compliance-officer role per CLAUDE.md and existing
 * permission strings).
 *
 * Routes:
 *   GET   /corex/compliance/rcr                          → index (list submissions)
 *   POST  /corex/compliance/rcr                          → create new submission for a questionnaire
 *   GET   /corex/compliance/rcr/{submission}             → show (drafting page)
 *   PATCH /corex/compliance/rcr/{submission}/answers/{answer} → save one answer (JSON)
 *   POST  /corex/compliance/rcr/{submission}/auto-populate-all → re-run evidence gathering
 *   POST  /corex/compliance/rcr/{submission}/send-for-review
 *   POST  /corex/compliance/rcr/{submission}/submit       → snapshot + lock for editing
 *   GET   /corex/compliance/rcr/{submission}/export/{format} → download pdf|csv|json
 */
final class RcrSubmissionController extends Controller
{
    public function __construct(
        private readonly EvidenceGatheringService $evidence,
        private readonly RcrSnapshotService $snapshots,
        private readonly RcrExportService $exports,
    ) {}

    /** GET /corex/compliance/rcr */
    public function index(Request $request): View
    {
        $this->assertCompliance($request);
        $agencyId = (int) $request->user()->effectiveAgencyId();

        $submissions = RcrSubmission::where('agency_id', $agencyId)
            ->with(['questionnaire:id,key,title,submission_deadline', 'assignedCo:id,name'])
            ->orderByDesc('created_at')
            ->get();

        $availableQuestionnaires = RcrQuestionnaire::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'key', 'title', 'submission_deadline']);

        return view('compliance.rcr.index', [
            'submissions'             => $submissions,
            'availableQuestionnaires' => $availableQuestionnaires,
        ]);
    }

    /** POST /corex/compliance/rcr */
    public function store(Request $request): RedirectResponse
    {
        $this->assertCompliance($request);
        $agencyId = (int) $request->user()->effectiveAgencyId();

        $data = $request->validate([
            'questionnaire_id' => ['required', 'integer', 'exists:rcr_questionnaires,id'],
        ]);

        $questionnaire = RcrQuestionnaire::findOrFail($data['questionnaire_id']);

        $submission = RcrSubmission::firstOrCreate(
            [
                'agency_id'             => $agencyId,
                'questionnaire_id'      => $questionnaire->id,
                'reporting_period_from' => $questionnaire->reporting_period_from,
            ],
            [
                'questionnaire_id'      => $questionnaire->id,
                'status'                => RcrSubmission::STATUS_DRAFT,
                'reporting_period_to'   => $questionnaire->reporting_period_to,
                'submission_deadline'   => $questionnaire->submission_deadline,
                'assigned_co_user_id'   => $request->user()->id,
            ],
        );

        // Kick off auto-population in the foreground for the initial sweep
        // (cheap — ~20-30 questions). Subsequent re-runs use the explicit
        // re-populate endpoint.
        if ($submission->wasRecentlyCreated) {
            $this->evidence->autoPopulate($submission);
        }

        return redirect()->route('corex.compliance.rcr.show', $submission->id)
            ->with('status', 'RCR draft started — ' . $questionnaire->title);
    }

    /** GET /corex/compliance/rcr/{submission} */
    public function show(Request $request, RcrSubmission $submission): View
    {
        $this->assertCompliance($request);
        $this->guardAgency($request, $submission);

        $submission->loadMissing([
            'questionnaire.sections.questions',
            'answers.evidence',
            'assignedCo',
        ]);

        // Build section-keyed answer index for fast view lookup.
        $answerById = $submission->answers->keyBy('question_id');

        $stats = [
            'total'        => $submission->questionnaire->questions->count(),
            'required'     => $submission->questionnaire->questions->where('is_required', true)->count(),
            'answered'     => $submission->answers->whereIn('status', [
                RcrAnswer::STATUS_ANSWERED, RcrAnswer::STATUS_REVIEWED, RcrAnswer::STATUS_APPROVED,
            ])->count(),
            'auto_filled'  => $submission->answers->where('status', RcrAnswer::STATUS_AUTO_FILLED)->count(),
            'in_progress'  => $submission->answers->where('status', RcrAnswer::STATUS_IN_PROGRESS)->count(),
        ];
        $stats['unanswered'] = max(0, $stats['total'] - $stats['answered'] - $stats['auto_filled'] - $stats['in_progress']);
        $stats['progress_pct'] = $stats['total'] > 0
            ? round(($stats['answered'] + $stats['auto_filled']) / $stats['total'] * 100, 1)
            : 0.0;

        return view('compliance.rcr.show', [
            'submission' => $submission,
            'answerById' => $answerById,
            'stats'      => $stats,
        ]);
    }

    /** PATCH /corex/compliance/rcr/{submission}/answers/{answer} */
    public function saveAnswer(Request $request, RcrSubmission $submission, RcrAnswer $answer): JsonResponse
    {
        $this->assertCompliance($request);
        $this->guardAgency($request, $submission);
        if (!$submission->isEditable()) {
            return response()->json(['error' => 'Submission is locked for editing.'], 422);
        }
        if ((int) $answer->submission_id !== (int) $submission->id) {
            abort(404);
        }

        $data = $request->validate([
            'answer_value'     => 'nullable|string|max:65000',
            'answer_data_json' => 'nullable|array',
            'notes'            => 'nullable|string|max:5000',
            'status'           => ['nullable', Rule::in([
                RcrAnswer::STATUS_IN_PROGRESS, RcrAnswer::STATUS_ANSWERED,
            ])],
        ]);

        $answer->forceFill([
            'answer_value'      => $data['answer_value'] ?? $answer->answer_value,
            'answer_data_json'  => $data['answer_data_json'] ?? $answer->answer_data_json,
            'notes'             => $data['notes'] ?? $answer->notes,
            'status'            => $data['status'] ?? ($answer->status === RcrAnswer::STATUS_UNANSWERED ? RcrAnswer::STATUS_IN_PROGRESS : $answer->status),
            'manually_edited'   => true,
            'last_edited_at'    => now(),
            'last_edited_by_user_id' => $request->user()->id,
        ])->save();

        return response()->json(['ok' => true, 'answer' => $answer->fresh()->toArray()]);
    }

    /** POST /corex/compliance/rcr/{submission}/auto-populate-all */
    public function autoPopulateAll(Request $request, RcrSubmission $submission): JsonResponse
    {
        $this->assertCompliance($request);
        $this->guardAgency($request, $submission);
        if (!$submission->isEditable()) {
            return response()->json(['error' => 'Submission is locked for editing.'], 422);
        }

        $results = $this->evidence->autoPopulate($submission);

        $summary = [
            'attempted' => count($results),
            'populated' => count(array_filter($results, fn ($r) => $r->populated)),
            'skipped'   => count(array_filter($results, fn ($r) => $r->skipped)),
            'errors'    => count(array_filter($results, fn ($r) => $r->error !== null && !$r->skipped)),
        ];
        return response()->json(['ok' => true, 'summary' => $summary]);
    }

    /** POST /corex/compliance/rcr/{submission}/send-for-review */
    public function sendForReview(Request $request, RcrSubmission $submission): RedirectResponse
    {
        $this->assertCompliance($request);
        $this->guardAgency($request, $submission);
        if (!$submission->isEditable()) {
            return back()->withErrors(['rcr' => 'Submission is locked for editing.']);
        }
        $submission->forceFill(['status' => RcrSubmission::STATUS_IN_REVIEW])->save();
        return back()->with('status', 'Sent for review.');
    }

    /** POST /corex/compliance/rcr/{submission}/submit */
    public function submit(Request $request, RcrSubmission $submission): RedirectResponse
    {
        $this->assertCompliance($request);
        $this->guardAgency($request, $submission);
        if ($submission->isSubmitted()) {
            return back()->withErrors(['rcr' => 'Submission already submitted.']);
        }

        $data = $request->validate([
            'submitted_to_platform_reference' => 'nullable|string|max:200',
            'confirmed' => 'required|accepted',
        ]);

        // Readiness validation (Part G2): require all is_required questions answered.
        $unanswered = $submission->questionnaire->questions()
            ->where('is_required', true)
            ->whereNotIn('id', $submission->answers()
                ->whereIn('status', [
                    RcrAnswer::STATUS_ANSWERED, RcrAnswer::STATUS_REVIEWED,
                    RcrAnswer::STATUS_APPROVED, RcrAnswer::STATUS_AUTO_FILLED,
                ])
                ->pluck('question_id'))
            ->count();
        if ($unanswered > 0) {
            return back()->withErrors([
                'rcr' => "Cannot submit — {$unanswered} required question(s) still unanswered.",
            ]);
        }

        // Persist + snapshot + export, all transactional.
        \DB::transaction(function () use ($submission, $request, $data) {
            $submission->forceFill([
                'status'                          => RcrSubmission::STATUS_SUBMITTED,
                'submitted_at'                    => now(),
                'submitted_by_user_id'            => $request->user()->id,
                'submitted_to_platform_reference' => $data['submitted_to_platform_reference'] ?? null,
            ])->save();
            $this->snapshots->takeSnapshot($submission, $request->user());
            $paths = $this->exports->exportAll($submission);
            $submission->forceFill(['export_document_path' => $paths['pdf']])->save();
        });

        // Emit a domain event for the activity feed (best-effort).
        try {
            event(new \App\Events\Compliance\RcrSubmissionSubmitted(
                submissionId: (int) $submission->id,
                agencyIdValue: (int) $submission->agency_id,
                actorUserIdValue: (int) $request->user()->id,
                directiveReference: (string) ($submission->questionnaire->directive_reference ?: ''),
            ));
        } catch (\Throwable $e) {
            \Log::warning('rcr.submit.event_failed', ['submission_id' => $submission->id, 'error' => $e->getMessage()]);
        }

        return redirect()->route('corex.compliance.rcr.show', $submission->id)
            ->with('status', 'RCR submitted + snapshot taken. Export ready below.');
    }

    /** GET /corex/compliance/rcr/{submission}/export/{format} */
    public function export(Request $request, RcrSubmission $submission, string $format): Response
    {
        $this->assertCompliance($request);
        $this->guardAgency($request, $submission);

        $format = strtolower($format);
        if (!in_array($format, ['pdf', 'csv', 'json'], true)) {
            abort(404);
        }
        $payload = match ($format) {
            'pdf'  => $this->exports->buildPdf($submission),
            'csv'  => $this->exports->buildCsv($submission),
            'json' => $this->exports->buildJson($submission),
        };
        $mime = match ($format) {
            'pdf'  => 'text/html',  // HTML for now; print-to-PDF downstream
            'csv'  => 'text/csv',
            'json' => 'application/json',
        };
        $extension = $format === 'pdf' ? 'html' : $format; // honest filename
        $filename = sprintf('rcr-%s-%s.%s',
            $submission->questionnaire->key,
            $submission->id,
            $extension,
        );
        return response($payload, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /** POST /corex/compliance/rcr/{submission}/answers/{answer}/evidence */
    public function attachEvidence(Request $request, RcrSubmission $submission, RcrAnswer $answer): JsonResponse
    {
        $this->assertCompliance($request);
        $this->guardAgency($request, $submission);
        if (!$submission->isEditable()) {
            return response()->json(['error' => 'Submission is locked for editing.'], 422);
        }
        if ((int) $answer->submission_id !== (int) $submission->id) {
            abort(404);
        }

        $data = $request->validate([
            'evidence_type' => ['required', Rule::in([
                'document_upload', 'corex_record_reference', 'external_url', 'note',
            ])],
            'description'        => 'required|string|max:1000',
            'document'           => 'nullable|file|max:20480', // 20MB
            'corex_record_table' => 'nullable|string|max:100',
            'corex_record_id'    => 'nullable|integer',
            'external_url'       => 'nullable|url|max:500',
        ]);

        $path = null;
        if ($data['evidence_type'] === 'document_upload' && $request->hasFile('document')) {
            $path = $request->file('document')->store(
                sprintf('compliance/rcr/%d/%d/evidence', $submission->agency_id, $submission->id),
                config('filesystems.default', 'local'),
            );
        }

        $row = RcrAnswerEvidence::create([
            'answer_id'          => $answer->id,
            'evidence_type'      => $data['evidence_type'],
            'document_path'      => $path,
            'corex_record_table' => $data['corex_record_table'] ?? null,
            'corex_record_id'    => $data['corex_record_id'] ?? null,
            'external_url'       => $data['external_url'] ?? null,
            'description'        => $data['description'],
            'added_by_user_id'   => $request->user()->id,
        ]);
        return response()->json(['ok' => true, 'evidence' => $row->toArray()]);
    }

    // ── Guards ──────────────────────────────────────────────────────────────

    private function assertCompliance(Request $request): void
    {
        $user = $request->user();
        if (!$user) abort(403);
        $role = (string) $user->role;
        if (!in_array($role, ['super_admin', 'admin', 'branch_manager', 'principal'], true) && !$user->is_admin) {
            abort(403, 'Compliance officer / admin only.');
        }
    }

    private function guardAgency(Request $request, RcrSubmission $submission): void
    {
        $effective = (int) $request->user()->effectiveAgencyId();
        if ((int) $submission->agency_id !== $effective) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
