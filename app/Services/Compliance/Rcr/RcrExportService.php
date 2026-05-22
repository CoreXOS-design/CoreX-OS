<?php

declare(strict_types=1);

namespace App\Services\Compliance\Rcr;

use App\Models\Agency;
use App\Models\Compliance\Rcr\RcrSubmission;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * Phase 9d F3+F4 — exports the RCR submission to three formats.
 *
 *   PDF  — formatted review document with declaration footer (the human
 *          artefact Elize uses to transpose into goAML and store on file)
 *   CSV  — flat questions+answers (quick reference + machine-friendly)
 *   JSON — full structured export mirroring the immutable snapshot shape
 *
 * Files are written to storage/app/compliance/rcr/{agency}/{submission}/
 * and the PDF path is also recorded on rcr_submissions.export_document_path
 * by the caller.
 */
final class RcrExportService
{
    public function exportAll(RcrSubmission $submission): array
    {
        $base = sprintf('compliance/rcr/%d/%d', $submission->agency_id, $submission->id);
        $disk = Storage::disk(config('filesystems.default', 'local'));

        $pdfRel  = $base . '/rcr.pdf';
        $csvRel  = $base . '/rcr.csv';
        $jsonRel = $base . '/rcr.json';

        $disk->put($pdfRel,  $this->buildPdf($submission));
        $disk->put($csvRel,  $this->buildCsv($submission));
        $disk->put($jsonRel, $this->buildJson($submission));

        return ['pdf' => $pdfRel, 'csv' => $csvRel, 'json' => $jsonRel];
    }

    public function buildPdf(RcrSubmission $submission): string
    {
        // CoreX standard is HTML-based document generation via the
        // DocuPerfect pipeline; for the RCR we emit a self-contained HTML
        // document that prints to A4. PDF rendering is downstream of this
        // service — the caller can run it through the Browsershot or
        // Dompdf pipeline used elsewhere. Returning HTML keeps this service
        // dependency-free.
        $submission->loadMissing(['agency', 'questionnaire.sections.questions', 'answers.evidence', 'assignedCo', 'submitter']);

        $agency = $submission->agency;
        $declaration = $this->buildDeclarationLine($submission);

        return view('compliance.rcr.export-pdf', [
            'submission'  => $submission,
            'agency'      => $agency,
            'declaration' => $declaration,
        ])->render();
    }

    public function buildCsv(RcrSubmission $submission): string
    {
        $submission->loadMissing(['questionnaire.sections.questions', 'answers']);
        $rows = [['Section', 'Question Code', 'Question', 'Answer Type', 'Required', 'Answer', 'Status', 'Auto-populated', 'Manually edited', 'Last edited at']];

        foreach ($submission->questionnaire->sections as $section) {
            foreach ($section->questions as $q) {
                $answer = $submission->answers->firstWhere('question_id', $q->id);
                $rows[] = [
                    $section->section_code . ' — ' . $section->title,
                    $q->question_code,
                    $q->question_text,
                    $q->answer_type,
                    $q->is_required ? 'yes' : 'no',
                    $answer?->answer_value ?? '',
                    $answer?->status ?? 'unanswered',
                    $answer && $answer->is_auto_populated ? 'yes' : 'no',
                    $answer && $answer->manually_edited ? 'yes' : 'no',
                    optional($answer?->last_edited_at)->toIso8601String() ?? '',
                ];
            }
        }

        $out = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);
        return $csv;
    }

    public function buildJson(RcrSubmission $submission): string
    {
        // Mirror the snapshot shape — re-uses the snapshot service render.
        return app(RcrSnapshotService::class)
            ? json_encode($this->mirrorSnapshotShape($submission), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
            : '{}';
    }

    public function buildDeclarationLine(RcrSubmission $submission): string
    {
        $coName = $submission->submitter?->name ?? $submission->assignedCo?->name ?? '(unsigned)';
        $when   = $submission->submitted_at?->format('j F Y') ?? 'Draft (not yet submitted)';
        return sprintf(
            'This RCR is submitted in compliance with %s by %s on %s.',
            $submission->questionnaire->directive_reference ?: 'FIC Directive 11 of 2026',
            $coName,
            $when,
        );
    }

    private function mirrorSnapshotShape(RcrSubmission $submission): array
    {
        $submission->loadMissing(['questionnaire.sections.questions', 'answers.evidence']);
        return [
            'submission_id'         => $submission->id,
            'agency_id'             => $submission->agency_id,
            'questionnaire_key'     => $submission->questionnaire->key,
            'reporting_period'      => [
                'from' => $submission->reporting_period_from->toDateString(),
                'to'   => $submission->reporting_period_to->toDateString(),
            ],
            'submission_deadline'   => $submission->submission_deadline->toDateString(),
            'submitted_at'          => optional($submission->submitted_at)->toIso8601String(),
            'sections'              => $submission->questionnaire->sections->map(fn ($section) => [
                'section_code' => $section->section_code,
                'title'        => $section->title,
                'questions'    => $section->questions->map(function ($q) use ($submission) {
                    $a = $submission->answers->firstWhere('question_id', $q->id);
                    return [
                        'question_code' => $q->question_code,
                        'question_text' => $q->question_text,
                        'answer'        => $a?->answer_value,
                        'status'        => $a?->status,
                        'auto_populated' => $a?->is_auto_populated,
                    ];
                })->all(),
            ])->all(),
        ];
    }
}
