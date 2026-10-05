<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Services\Rentals\TakeOnImport\RentalTakeOnArchiveService;
use App\Services\Rentals\TakeOnImport\RentalTakeOnConfirmService;
use App\Services\Rentals\TakeOnImport\RentalTakeOnDryRunResolver;
use App\Services\Rentals\TakeOnImport\RentalTakeOnFieldSchema;
use App\Services\Rentals\TakeOnImport\RentalTakeOnRowParser;
use App\Services\Rentals\TakeOnImport\RentalTakeOnTemplateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * .ai/specs/rental-takeon-import.md — the take-on import batch: upload,
 * dry-run preview, confirm, archivable history. Copies the P24 importer's
 * Run+Row shape (app/Http/Controllers/Admin/ImporterController.php).
 */
class RentalTakeOnImportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $showArchived = $request->boolean('archived');

        $query = RentalTakeOnImportRun::query()->with('user');
        $query = $showArchived ? $query->onlyTrashed() : $query;
        $query->visibleTo($user, $request->get('scope'));

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('source_filename', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->get('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $request->get('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $sort = $request->get('sort', 'created_at');
        $direction = $request->get('direction', 'desc');
        $allowedSorts = ['created_at' => 'created_at', 'status' => 'status', 'rows' => 'row_count'];
        if ($sort === 'rows') {
            $query->withCount('rows')->orderBy('row_count', $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy($allowedSorts[$sort] ?? 'created_at', $direction === 'asc' ? 'asc' : 'desc');
        }

        $runs = $query->paginate(20)->withQueryString();

        return view('corex.rentals.take-on-import.index', compact('runs', 'showArchived'));
    }

    public function downloadTemplate(Request $request, RentalTakeOnTemplateService $templateService)
    {
        $agency = Agency::find($request->user()->effectiveAgencyId());
        if (!$agency) {
            return back()->withErrors(['file' => 'Switch into an agency before downloading the take-on template.']);
        }

        $spreadsheet = $templateService->build($agency);

        $tmp = tempnam(sys_get_temp_dir(), 'rtoi');
        $templateService->write($spreadsheet, $tmp);

        return response()->download($tmp, 'rental-take-on-template.xlsx')->deleteFileAfterSend(true);
    }

    public function upload(Request $request, RentalTakeOnRowParser $parser, RentalTakeOnDryRunResolver $resolver)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,txt|max:51200',
        ]);

        $agencyId = $request->user()->effectiveAgencyId();
        if (!$agencyId) {
            return back()->withErrors(['file' => 'Switch into an agency before importing a take-on book.']);
        }

        $file = $request->file('file');
        $path = $file->store('imports/rental-takeon');
        $extension = strtolower($file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';

        $run = RentalTakeOnImportRun::create([
            'agency_id' => $agencyId,
            'branch_id' => $request->user()->effectiveBranchId(),
            'user_id' => $request->user()->id,
            'status' => RentalTakeOnImportRun::STATUS_PARSING,
            'source_filename' => $file->getClientOriginalName(),
            'source_file_path' => $path,
        ]);

        try {
            $counts = ['total' => 0, 'will_create_property' => 0, 'will_match_property' => 0, 'complete' => 0, 'draft' => 0, 'errors' => 0];

            foreach ($parser->parse(Storage::path($path), $extension) as $parsed) {
                $row = RentalTakeOnImportRow::create([
                    'run_id' => $run->id,
                    'row_number' => $parsed['row_number'],
                    'payload_json' => $parsed['payload'],
                    'status' => RentalTakeOnImportRow::STATUS_PENDING,
                ]);

                $resolver->resolve($row, $run);
                $row->refresh();

                $counts['total']++;
                $counts[$row->property_match_action === 'match' ? 'will_match_property' : 'will_create_property']++;
                $counts[$row->lease_completeness === RentalTakeOnImportRow::COMPLETENESS_COMPLETE ? 'complete' : 'draft']++;
                if ($row->status === RentalTakeOnImportRow::STATUS_ERROR) {
                    $counts['errors']++;
                }
            }

            if ($counts['total'] === 0) {
                $run->update(['status' => RentalTakeOnImportRun::STATUS_FAILED, 'error_message' => 'No data rows found in the uploaded file.']);

                return back()->withErrors(['file' => 'No data rows found in the uploaded file.']);
            }

            $run->update(['status' => RentalTakeOnImportRun::STATUS_PENDING_CONFIRM, 'counts_json' => $counts]);
        } catch (\Throwable $e) {
            report($e);
            $run->update(['status' => RentalTakeOnImportRun::STATUS_FAILED, 'error_message' => $e->getMessage()]);

            return back()->withErrors(['file' => 'Could not read the spreadsheet: ' . $e->getMessage()]);
        }

        return redirect()->route('corex.rentals.take-on-import.preview', $run);
    }

    public function preview(RentalTakeOnImportRun $run)
    {
        $rows = $run->rows()->orderBy('row_number')->paginate(50);

        return view('corex.rentals.take-on-import.preview', compact('run', 'rows'));
    }

    public function confirmRow(Request $request, RentalTakeOnImportRow $row, RentalTakeOnConfirmService $confirmService)
    {
        $result = $confirmService->confirmRow($row, $request->user()->id);
        $this->touchRunCompletion($row->run);

        if ($request->ajax() || $request->expectsJson()) {
            return response()->json($result);
        }

        return back()->with($result['ok'] ? 'status' : 'error', $result['message'] ?? 'Row imported.');
    }

    public function confirmBulk(Request $request, RentalTakeOnImportRun $run, RentalTakeOnConfirmService $confirmService)
    {
        $ids = (array) $request->input('ids', []);
        $rows = $run->rows()
            ->whereIn('id', $ids)
            ->whereNotIn('status', [RentalTakeOnImportRow::STATUS_CONFIRMED, RentalTakeOnImportRow::STATUS_EXCLUDED])
            ->whereNull('errors_json')
            ->get();

        $confirmed = 0;
        foreach ($rows as $row) {
            $result = $confirmService->confirmRow($row, $request->user()->id);
            if ($result['ok']) {
                $confirmed++;
            }
        }

        $this->touchRunCompletion($run);

        return back()->with('status', "Imported {$confirmed} of " . count($ids) . ' selected rows.');
    }

    public function excludeRow(RentalTakeOnImportRow $row)
    {
        $row->update(['status' => RentalTakeOnImportRow::STATUS_EXCLUDED]);
        $this->touchRunCompletion($row->run);

        return back()->with('status', 'Row excluded.');
    }

    public function cancel(RentalTakeOnImportRun $run)
    {
        if (in_array($run->status, [RentalTakeOnImportRun::STATUS_COMPLETED], true)) {
            return back()->withErrors(['run' => 'A completed batch cannot be cancelled — archive it instead.']);
        }

        $run->update(['status' => RentalTakeOnImportRun::STATUS_CANCELLED]);
        $run->delete();

        return redirect()->route('corex.rentals.take-on-import.index')->with('status', 'Import batch cancelled.');
    }

    public function show(RentalTakeOnImportRun $run)
    {
        $rows = $run->rows()->orderBy('row_number')->paginate(50);

        return view('corex.rentals.take-on-import.show', compact('run', 'rows'));
    }

    public function errorsCsv(RentalTakeOnImportRun $run)
    {
        $rows = $run->rows()
            ->where(function ($q) {
                $q->whereNotNull('errors_json')->orWhereNotNull('warnings_json');
            })
            ->orderBy('row_number')
            ->get();

        $filename = 'take-on-import-' . $run->id . '-issues.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Row', 'Type', 'Message']);
            foreach ($rows as $row) {
                foreach (($row->errors_json ?? []) as $msg) {
                    fputcsv($out, [$row->row_number, 'Error', $msg]);
                }
                foreach (($row->warnings_json ?? []) as $msg) {
                    fputcsv($out, [$row->row_number, 'Warning', $msg]);
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function archive(RentalTakeOnImportRun $run, RentalTakeOnArchiveService $archiveService)
    {
        $counts = $archiveService->archive($run);

        $archived = $counts['archived'];
        $leftAlone = array_sum($counts['left_alone_edited']) + array_sum($counts['left_alone_existing']);

        $message = "Archived {$archived['leases']} lease(s), {$archived['properties']} property/properties, {$archived['contacts']} contact(s).";
        if ($leftAlone > 0) {
            $message .= " Left {$leftAlone} record(s) alone — already existing or edited since import.";
        }

        return redirect()->route('corex.rentals.take-on-import.index')->with('status', $message);
    }

    public function restore(string $runId, RentalTakeOnArchiveService $archiveService)
    {
        $run = RentalTakeOnImportRun::withTrashed()->findOrFail($runId);
        $run->restore();
        $archiveService->restore($run);

        return redirect()->route('corex.rentals.take-on-import.index')->with('status', 'Import batch restored.');
    }

    private function touchRunCompletion(RentalTakeOnImportRun $run): void
    {
        $run->refresh();
        $outstanding = $run->rows()
            ->whereNotIn('status', [RentalTakeOnImportRow::STATUS_CONFIRMED, RentalTakeOnImportRow::STATUS_EXCLUDED])
            ->exists();

        if (!$outstanding && $run->status !== RentalTakeOnImportRun::STATUS_COMPLETED) {
            $run->update(['status' => RentalTakeOnImportRun::STATUS_COMPLETED, 'completed_at' => now()]);
        }
    }
}
