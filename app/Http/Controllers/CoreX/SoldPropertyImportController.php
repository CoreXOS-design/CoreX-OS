<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Properties\SoldPropertyImporter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Super-admin bulk import of SOLD listings into the Property pillar.
 *
 * Three steps:
 *   1. form()    — upload page.
 *   2. preview() — parse + auto-match, show the review/assign screen.
 *   3. run()     — create the properties with the confirmed agent per row.
 *
 * Routes are gated by the `super_admin` middleware. See
 * .ai/specs/sold-properties-import.md.
 */
class SoldPropertyImportController extends Controller
{
    private const STAGE_DIR = 'sold-imports';

    /** Step 1 — upload form. */
    public function form()
    {
        return view('corex.properties.import-sold', ['stage' => 'upload', 'result' => null]);
    }

    /** Step 2 — parse the upload and show the review/assign screen. */
    public function preview(Request $request, SoldPropertyImporter $importer)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:51200', // 50 MB
        ]);

        // Stage the file so we can re-read it (incl. embedded images) on confirm.
        $token    = Str::uuid()->toString() . '.xlsx';
        $relative = self::STAGE_DIR . '/' . $token;
        Storage::disk('local')->putFileAs(self::STAGE_DIR, $request->file('file'), $token);
        $absolute = Storage::disk('local')->path($relative);

        try {
            $rows = $importer->preview($absolute, $request->user()?->effectiveAgencyId());
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($relative);
            return back()->withErrors(['file' => 'Could not read the spreadsheet: ' . $e->getMessage()]);
        }

        if (empty($rows)) {
            Storage::disk('local')->delete($relative);
            return back()->withErrors(['file' => 'No data rows found in the spreadsheet.']);
        }

        return view('corex.properties.import-sold', [
            'stage'  => 'review',
            'result' => null,
            'token'  => $token,
            'rows'   => $rows,
            'agents' => $this->agencyAgents($request->user()),
        ]);
    }

    /** Step 3 — create the properties using the confirmed agent assignments. */
    public function run(Request $request, SoldPropertyImporter $importer)
    {
        $data = $request->validate([
            'token'    => 'required|string',
            'agents'   => 'required|array',
            'agents.*' => 'required|integer|exists:users,id',
        ]);

        // Bulk admin import: parsing the workbook and downscaling one photo per
        // row is legitimately long-running. The dominant cost (prospecting match)
        // is now queued off-request, but give the synchronous remainder headroom.
        @set_time_limit(300);

        $relative = self::STAGE_DIR . '/' . basename($data['token']);
        if (!Storage::disk('local')->exists($relative)) {
            return redirect()->route('corex.properties.import-sold')
                ->withErrors(['file' => 'The upload expired. Please upload the file again.']);
        }
        $absolute = Storage::disk('local')->path($relative);

        // Only honour agent ids that belong to this agency.
        $allowed   = $this->agencyAgents($request->user())->pluck('id')->all();
        $agentByRow = [];
        foreach ($data['agents'] as $row => $agentId) {
            if (in_array((int) $agentId, $allowed, true)) {
                $agentByRow[(int) $row] = (int) $agentId;
            }
        }

        try {
            $result = $importer->import($absolute, $request->user(), $agentByRow);
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'Import failed: ' . $e->getMessage()]);
        } finally {
            Storage::disk('local')->delete($relative);
        }

        return view('corex.properties.import-sold', ['stage' => 'done', 'result' => $result])
            ->with('success', "Import complete — {$result['created']} created, {$result['updated']} updated.");
    }

    /**
     * Agency users selectable as the listing agent. System Owner accounts are
     * excluded — PropertyObserver forbids assigning them as a property agent.
     */
    private function agencyAgents(User $actor): \Illuminate\Support\Collection
    {
        $agencyId   = $actor->effectiveAgencyId();
        $ownerRoles = User::ownerRoleNames();

        return User::query()
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->when(!empty($ownerRoles), fn ($q) => $q->whereNotIn('role', $ownerRoles))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->values();
    }
}
