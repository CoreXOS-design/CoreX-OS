<?php

declare(strict_types=1);

namespace App\Services\Compliance\Rcr;

use App\Models\Compliance\Rcr\RcrSubmission;
use App\Models\Compliance\Rcr\RcrSubmissionSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Phase 9d F1 — immutable snapshot at submission time.
 *
 * Captures the FULL denormalised state of the submission so that future
 * FIC audits can answer "what did you submit and what backed each answer"
 * even when the live data has churned. Idempotent: a submission only ever
 * gets one snapshot row.
 */
final class RcrSnapshotService
{
    public function takeSnapshot(RcrSubmission $submission, User $by): RcrSubmissionSnapshot
    {
        $existing = RcrSubmissionSnapshot::where('submission_id', $submission->id)->first();
        if ($existing) {
            return $existing; // idempotent
        }

        $submission->loadMissing(['questionnaire.sections.questions', 'answers.evidence', 'assignedCo']);

        $sectionsExport = $submission->questionnaire->sections->map(function ($section) use ($submission) {
            return [
                'section_code' => $section->section_code,
                'title'        => $section->title,
                'description'  => $section->description,
                'questions'    => $section->questions->map(function ($q) use ($submission) {
                    $answer = $submission->answers->firstWhere('question_id', $q->id);
                    return [
                        'question_code'   => $q->question_code,
                        'question_text'   => $q->question_text,
                        'answer_type'     => $q->answer_type,
                        'is_required'     => (bool) $q->is_required,
                        'auto_population_source' => $q->auto_population_source,
                        'answer'          => $answer ? [
                            'value'                       => $answer->answer_value,
                            'data'                        => $answer->answer_data_json,
                            'is_auto_populated'           => (bool) $answer->is_auto_populated,
                            'manually_edited'             => (bool) $answer->manually_edited,
                            'status'                      => $answer->status,
                            'last_edited_at'              => optional($answer->last_edited_at)->toIso8601String(),
                            'last_edited_by_user_id'      => $answer->last_edited_by_user_id,
                            'auto_population_source_data' => $answer->auto_population_source_data,
                            'reviewed_at'                 => optional($answer->reviewed_at)->toIso8601String(),
                            'reviewer_user_id'            => $answer->reviewer_user_id,
                            'evidence'                    => $answer->evidence->map(fn ($e) => [
                                'evidence_type'      => $e->evidence_type,
                                'document_path'      => $e->document_path,
                                'corex_record_table' => $e->corex_record_table,
                                'corex_record_id'    => $e->corex_record_id,
                                'external_url'       => $e->external_url,
                                'description'        => $e->description,
                                'added_by_user_id'   => $e->added_by_user_id,
                                'created_at'         => optional($e->created_at)->toIso8601String(),
                            ])->all(),
                        ] : null,
                    ];
                })->all(),
            ];
        })->all();

        $payload = [
            'submission_id'             => $submission->id,
            'agency_id'                 => $submission->agency_id,
            'agency_name'               => optional($submission->agency)->name,
            'questionnaire_key'         => $submission->questionnaire->key,
            'questionnaire_title'       => $submission->questionnaire->title,
            'directive_reference'       => $submission->questionnaire->directive_reference,
            'reporting_period_from'     => $submission->reporting_period_from->toDateString(),
            'reporting_period_to'       => $submission->reporting_period_to->toDateString(),
            'submission_deadline'       => $submission->submission_deadline->toDateString(),
            'submitted_at'              => optional($submission->submitted_at)->toIso8601String(),
            'submitted_by_user_id'      => $submission->submitted_by_user_id,
            'submitted_to_platform_reference' => $submission->submitted_to_platform_reference,
            'assigned_co'               => $submission->assignedCo?->name,
            'notes'                     => $submission->notes,
            'sections'                  => $sectionsExport,
        ];

        $structureHash = hash('sha256', json_encode([
            'questionnaire_id'   => $submission->questionnaire_id,
            'sections_signature' => collect($sectionsExport)->map(fn ($s) => [
                'code' => $s['section_code'],
                'qs'   => collect($s['questions'])->pluck('question_code')->all(),
            ])->all(),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($submission, $by, $payload, $structureHash) {
            return RcrSubmissionSnapshot::create([
                'submission_id'              => $submission->id,
                'snapshot_json'              => $payload,
                'questionnaire_version_hash' => $structureHash,
                'taken_at'                   => now(),
                'taken_by_user_id'           => $by->id,
            ]);
        });
    }
}
