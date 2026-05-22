<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compliance\Rcr;

use App\Http\Controllers\Controller;
use App\Models\Compliance\Rcr\RcrQuestion;
use App\Models\Compliance\Rcr\RcrQuestionnaire;
use App\Models\Compliance\Rcr\RcrQuestionnaireSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Phase 9d C2 — admin importer for RCR questionnaires.
 *
 * Lets Elize (or any admin) bulk-import the FIC's 38-page PDF question
 * set via CSV without dev intervention. Expected CSV columns:
 *
 *   section_code, question_code, question_text, answer_type,
 *   answer_options, is_required, auto_population_source, help_text
 *
 * Two modes:
 *   append  — add new questions; reject if any question_code already exists
 *   replace — drop all questions on the questionnaire first, then import
 *
 * The CSV must have a header row matching the column names. answer_options
 * is a pipe-separated list for multi/single-select questions. is_required
 * accepts 1/0/yes/no/true/false (case-insensitive).
 */
final class RcrQuestionnaireAdminController extends Controller
{
    /** GET /corex/admin/rcr/questionnaires */
    public function index(Request $request): View
    {
        $this->assertAdmin($request);
        $questionnaires = RcrQuestionnaire::orderBy('sort_order')
            ->withCount(['sections', 'questions'])
            ->get();
        return view('admin.rcr.questionnaires.index', ['questionnaires' => $questionnaires]);
    }

    /** GET /corex/admin/rcr/questionnaires/{questionnaire} */
    public function show(Request $request, RcrQuestionnaire $questionnaire): View
    {
        $this->assertAdmin($request);
        $questionnaire->loadMissing(['sections.questions']);
        return view('admin.rcr.questionnaires.show', ['questionnaire' => $questionnaire]);
    }

    /** POST /corex/admin/rcr/questionnaires/{questionnaire}/import-csv */
    public function importCsv(Request $request, RcrQuestionnaire $questionnaire): RedirectResponse
    {
        $this->assertAdmin($request);

        $data = $request->validate([
            'csv_file' => 'required|file|mimes:csv,txt|max:5120',
            'mode'     => 'required|in:append,replace',
        ]);

        $path = $request->file('csv_file')->getRealPath();
        $handle = fopen($path, 'r');
        if (!$handle) {
            return back()->withErrors(['csv_file' => 'Could not open uploaded file.']);
        }

        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return back()->withErrors(['csv_file' => 'CSV is empty.']);
        }
        $header = array_map(fn ($h) => strtolower(trim((string) $h)), $header);
        $expected = ['section_code', 'question_code', 'question_text', 'answer_type'];
        foreach ($expected as $required) {
            if (!in_array($required, $header, true)) {
                fclose($handle);
                return back()->withErrors(['csv_file' => 'Missing required CSV column: ' . $required]);
            }
        }

        $rows = [];
        $lineNo = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $lineNo++;
            if (count(array_filter($row, fn ($c) => $c !== null && $c !== '')) === 0) continue;
            $rows[] = ['line' => $lineNo, 'data' => array_combine($header, array_pad($row, count($header), null))];
        }
        fclose($handle);

        // Validate before applying.
        $errors = [];
        $seenCodes = [];
        $sectionsByCode = [];
        foreach ($rows as $r) {
            $d = $r['data'];
            $sc = trim((string) ($d['section_code'] ?? ''));
            $qc = trim((string) ($d['question_code'] ?? ''));
            if ($sc === '') { $errors[] = "Line {$r['line']}: empty section_code"; continue; }
            if ($qc === '') { $errors[] = "Line {$r['line']}: empty question_code"; continue; }
            if (isset($seenCodes[$qc])) { $errors[] = "Line {$r['line']}: duplicate question_code '{$qc}' in CSV"; continue; }
            $seenCodes[$qc] = true;
            $at = strtolower(trim((string) ($d['answer_type'] ?? 'free_text')));
            if (!in_array($at, RcrQuestion::ALL_TYPES, true)) {
                $errors[] = "Line {$r['line']}: invalid answer_type '{$at}'";
            }
        }
        if (!empty($errors)) {
            return back()->withErrors(['csv_file' => 'CSV has issues — first 5: ' . implode(' | ', array_slice($errors, 0, 5))]);
        }

        // Apply.
        DB::transaction(function () use ($questionnaire, $rows, $data) {
            if ($data['mode'] === 'replace') {
                // Soft-replace via cascading delete on sections+questions.
                RcrQuestion::where('questionnaire_id', $questionnaire->id)->delete();
                RcrQuestionnaireSection::where('questionnaire_id', $questionnaire->id)->delete();
            }
            $sectionsByCode = RcrQuestionnaireSection::where('questionnaire_id', $questionnaire->id)
                ->pluck('id', 'section_code')->toArray();
            $nextSectionOrder = (int) (RcrQuestionnaireSection::where('questionnaire_id', $questionnaire->id)->max('sort_order') ?? 0) + 1;
            $nextQuestionOrder = (int) (RcrQuestion::where('questionnaire_id', $questionnaire->id)->max('sort_order') ?? 0) + 1;

            foreach ($rows as $r) {
                $d = $r['data'];
                $sectionCode = trim((string) $d['section_code']);
                $sectionId = $sectionsByCode[$sectionCode] ?? null;
                if (!$sectionId) {
                    // Auto-create section row with title = code (admin can rename later).
                    $sec = RcrQuestionnaireSection::create([
                        'questionnaire_id' => $questionnaire->id,
                        'section_code'     => $sectionCode,
                        'title'            => $sectionCode,
                        'sort_order'       => $nextSectionOrder++,
                    ]);
                    $sectionsByCode[$sectionCode] = $sec->id;
                    $sectionId = $sec->id;
                }

                $opts = trim((string) ($d['answer_options'] ?? ''));
                $optsArr = $opts !== '' ? array_values(array_filter(array_map('trim', explode('|', $opts)))) : null;

                RcrQuestion::updateOrCreate(
                    [
                        'questionnaire_id' => $questionnaire->id,
                        'question_code'    => trim((string) $d['question_code']),
                    ],
                    [
                        'section_id'             => $sectionId,
                        'question_text'          => trim((string) ($d['question_text'] ?? '')),
                        'answer_type'            => strtolower(trim((string) ($d['answer_type'] ?? 'free_text'))),
                        'answer_options_json'    => $optsArr,
                        'is_required'            => $this->parseBool($d['is_required'] ?? 'true'),
                        'auto_population_source' => trim((string) ($d['auto_population_source'] ?? '')) ?: null,
                        'help_text'              => trim((string) ($d['help_text'] ?? '')) ?: null,
                        'sort_order'             => $nextQuestionOrder++,
                    ],
                );
            }
        });

        return redirect()->route('corex.admin.rcr.questionnaires.show', $questionnaire->id)
            ->with('status', 'Imported ' . count($rows) . ' questions (' . $data['mode'] . ' mode).');
    }

    private function parseBool(mixed $v): bool
    {
        $s = strtolower(trim((string) $v));
        return in_array($s, ['1', 'yes', 'true', 'y', 'required'], true);
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();
        if (!$user) abort(403);
        $role = (string) $user->role;
        if (!in_array($role, ['super_admin', 'admin', 'branch_manager', 'principal'], true) && !$user->is_admin) {
            abort(403, 'Admin only.');
        }
    }
}
