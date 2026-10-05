<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\RentalCatalogueItem;
use App\Services\Rentals\CatalogueImport\RentalCatalogueImportDryRunResolver;
use App\Services\Rentals\CatalogueImport\RentalCatalogueImportRowParser;
use App\Services\Rentals\CatalogueImport\RentalCatalogueImportTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §14.19 — bulk-load an agency's own price
 * list in one go: download template, upload CSV/XLSX, dry-run preview with
 * per-row errors, confirm. Same template -> parse -> dry-run -> confirm
 * shape as App\Http\Controllers\CoreX\RentalTakeOnImportController, but
 * deliberately WITHOUT that importer's persisted Run/Row history/archive —
 * nobody asked for a standing, listable import-batch entity here, only the
 * workflow itself, so the dry-run result is held in Cache (30 min TTL,
 * keyed by a token) between the upload and confirm requests rather than in
 * new database tables.
 */
class RentalCatalogueImportController extends Controller
{
    private const CACHE_TTL_MINUTES = 30;

    public function index(Request $request): View
    {
        $agency = Agency::withoutGlobalScopes()->find($request->user()->effectiveAgencyId());

        return view('corex.rental-catalogue-items.import.index', ['agency' => $agency]);
    }

    public function downloadTemplate(Request $request, RentalCatalogueImportTemplateService $templateService)
    {
        $agency = Agency::withoutGlobalScopes()->find($request->user()->effectiveAgencyId());
        if (! $agency) {
            return back()->withErrors(['file' => 'Switch into an agency before downloading the catalogue import template.']);
        }

        $spreadsheet = $templateService->build($agency);

        $tmp = tempnam(sys_get_temp_dir(), 'rcci');
        $templateService->write($spreadsheet, $tmp);

        return response()->download($tmp, 'parts-labour-catalogue-template.xlsx')->deleteFileAfterSend(true);
    }

    public function upload(Request $request, RentalCatalogueImportRowParser $parser, RentalCatalogueImportDryRunResolver $resolver): RedirectResponse
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,csv,txt|max:51200',
            'on_duplicate' => 'required|in:update,skip',
        ]);

        $agency = Agency::withoutGlobalScopes()->find($request->user()->effectiveAgencyId());
        if (! $agency) {
            return back()->withErrors(['file' => 'Switch into an agency before importing a catalogue.']);
        }

        $file = $request->file('file');
        $path = $file->store('imports/rental-catalogue');
        $extension = strtolower($file->getClientOriginalExtension()) === 'csv' ? 'csv' : 'xlsx';
        $onDuplicate = $request->string('on_duplicate')->toString();

        $rows = [];
        $counts = ['total' => 0, 'create' => 0, 'update' => 0, 'skip' => 0, 'error' => 0];
        $codesSeenThisFile = [];

        try {
            foreach ($parser->parse(Storage::path($path), $extension) as $parsed) {
                $result = $resolver->resolve($parsed['row_number'], $parsed['payload'], $agency, $onDuplicate, $codesSeenThisFile);
                $rows[] = $result;
                $counts['total']++;
                $counts[$result['action']]++;
            }
        } catch (\Throwable $e) {
            report($e);

            return back()->withErrors(['file' => 'Could not read the spreadsheet: ' . $e->getMessage()]);
        } finally {
            Storage::delete($path);
        }

        if ($counts['total'] === 0) {
            return back()->withErrors(['file' => 'No data rows found in the uploaded file.']);
        }

        $token = (string) Str::uuid();
        Cache::put($this->cacheKey($token), [
            'agency_id' => $agency->id,
            'user_id' => $request->user()->id,
            'source_filename' => $file->getClientOriginalName(),
            'on_duplicate' => $onDuplicate,
            'counts' => $counts,
            'rows' => $rows,
        ], now()->addMinutes(self::CACHE_TTL_MINUTES));

        return redirect()->route('corex.rental-catalogue-items.import.preview', $token);
    }

    public function preview(Request $request, string $token): View|RedirectResponse
    {
        $batch = $this->loadBatch($token, $request);
        if ($batch instanceof RedirectResponse) {
            return $batch;
        }

        return view('corex.rental-catalogue-items.import.preview', ['token' => $token] + $batch);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $batch = $this->loadBatch($token, $request);
        if ($batch instanceof RedirectResponse) {
            return $batch;
        }

        $agency = Agency::withoutGlobalScopes()->findOrFail($batch['agency_id']);
        $created = 0;
        $updated = 0;

        foreach ($batch['rows'] as $row) {
            if ($row['action'] === RentalCatalogueImportDryRunResolver::ACTION_CREATE) {
                RentalCatalogueItem::create([
                    'agency_id' => $agency->id,
                    'rental_catalogue_item_type_id' => $row['resolved']['rental_catalogue_item_type_id'],
                    'code' => $row['code'],
                    'description' => $row['description'],
                    'rental_catalogue_unit_id' => $row['resolved']['rental_catalogue_unit_id'],
                    'default_price' => $row['resolved']['default_price'],
                    'default_rental_vat_type_id' => $row['resolved']['default_rental_vat_type_id'],
                    'default_custom_vat_rate' => $row['resolved']['default_custom_vat_rate'],
                    'is_active' => true,
                    'sort_order' => (int) (RentalCatalogueItem::max('sort_order') ?? 0) + 1,
                    'created_by_user_id' => $request->user()->id,
                ]);
                $created++;
            } elseif ($row['action'] === RentalCatalogueImportDryRunResolver::ACTION_UPDATE && $row['existing_item_id']) {
                $existing = RentalCatalogueItem::where('agency_id', $agency->id)->find($row['existing_item_id']);
                if ($existing) {
                    $existing->update([
                        'rental_catalogue_item_type_id' => $row['resolved']['rental_catalogue_item_type_id'],
                        'description' => $row['description'],
                        'rental_catalogue_unit_id' => $row['resolved']['rental_catalogue_unit_id'],
                        'default_price' => $row['resolved']['default_price'],
                        'default_rental_vat_type_id' => $row['resolved']['default_rental_vat_type_id'],
                        'default_custom_vat_rate' => $row['resolved']['default_custom_vat_rate'],
                    ]);
                    $updated++;
                }
            }
            // ACTION_SKIP / ACTION_ERROR rows are never written — exactly what the dry-run preview promised.
        }

        Cache::forget($this->cacheKey($token));

        $skipped = $batch['counts']['skip'];
        $errored = $batch['counts']['error'];
        $message = "Imported: {$created} created, {$updated} updated.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (already existed).";
        }
        if ($errored > 0) {
            $message .= " {$errored} skipped (errors).";
        }

        return redirect()->route('corex.rental-catalogue-items.index')->with('success', $message);
    }

    /** @return array{agency_id:int,user_id:int,source_filename:string,on_duplicate:string,counts:array,rows:array}|RedirectResponse */
    private function loadBatch(string $token, Request $request): array|RedirectResponse
    {
        $batch = Cache::get($this->cacheKey($token));
        if (! $batch || (int) $batch['agency_id'] !== (int) $request->user()->effectiveAgencyId()) {
            return redirect()->route('corex.rental-catalogue-items.import.index')
                ->withErrors(['file' => 'This import preview has expired or is no longer available — upload the file again.']);
        }

        return $batch;
    }

    private function cacheKey(string $token): string
    {
        return "rental-catalogue-import:{$token}";
    }
}
