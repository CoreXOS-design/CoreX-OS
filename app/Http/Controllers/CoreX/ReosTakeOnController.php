<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\RentalTakeOnImportRow;
use App\Models\RentalTakeOnImportRun;
use App\Services\Rentals\TakeOnImport\Reos\ReosAgentMatcher;
use App\Services\Rentals\TakeOnImport\Reos\ReosLayoutException;
use App\Services\Rentals\TakeOnImport\Reos\ReosRowQuery;
use App\Services\Rentals\TakeOnImport\Reos\ReosRunDefaults;
use App\Services\Rentals\TakeOnImport\Reos\ReosRunSummary;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyConfirmService;
use App\Services\Rentals\TakeOnImport\Reos\ReosTenancyRunService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-479 — .ai/specs/rental-takeon-import.md "reOS Tenancy Schedule import": upload, the review screen, and Import.
 * Same permissions, same own/branch/agency visibility (RentalTakeOnImportRun::visibleTo) and same feature toggle as the
 * existing take-on import (the routes sit inside its group). The review screen's saves are JSON calls on
 * ReosTakeOnApiController (they live under /api/v1 so the API catalogue lists them).
 */
class ReosTakeOnController extends Controller
{
    use EnforcesRecordVisibility;
    public function upload(Request $request, ReosTenancyRunService $service): RedirectResponse
    {
        $request->validate([
            'schedule' => 'required|file|mimes:xlsx|max:51200',
            'rent_roll' => 'nullable|file|mimes:xlsx|max:51200',
        ], [
            'schedule.required' => 'Choose the reOS Tenancy Schedule (.xlsx) to upload.',
            'schedule.mimes' => 'The Tenancy Schedule must be an Excel (.xlsx) file, as exported from reOS.',
            'rent_roll.mimes' => 'The Rent Roll must be an Excel (.xlsx) file, as exported from reOS.',
        ]);

        $user = $request->user();
        $agencyId = $user->effectiveAgencyId();
        if (! $agencyId) {
            return back()->withErrors(['schedule' => 'Switch into an agency before importing from reOS.']);
        }

        try {
            $run = $service->createRun($user, (int) $agencyId, $user->effectiveBranchId(), $request->file('schedule'), $request->file('rent_roll'));
        } catch (ReosLayoutException $e) {
            return back()->withErrors(['schedule' => $e->getMessage()]);
        }

        return redirect()->route('corex.rentals.take-on-import.preview', $run)
            ->with('status', 'Read ' . ($run->counts_json['total'] ?? 0) . ' leases from the reOS Tenancy Schedule. Nothing has been imported yet.');
    }

    /** The review screen. Also serves the finished-batch view (rows show what became of them). */
    public function review(Request $request, RentalTakeOnImportRun $run): View
    {
        $this->assertVisible($request, $run);
        abort_unless((int) $run->agency_id === (int) $request->user()->effectiveAgencyId(), 404);

        // A batch parsed under older name-flag rules is re-checked once, so the screen never shows verdicts the current rules would not give.
        app(\App\Services\Rentals\TakeOnImport\Reos\ReosRowResolver::class)->recheckNameFlags($run);
        $run->refresh();

        $filters = ReosRowQuery::only($request->query());
        $canManage = $request->user()->hasPermission('rentals_take_on_import.manage');

        // The "for all" choices shown in the defaults bar are always what the leases carry: a lease they never reached is caught up here.
        if ($canManage && ! $run->trashed()) {
            app(\App\Services\Rentals\TakeOnImport\Reos\ReosDecisionService::class)->reconcileDefaults($run);
        }

        // ONE list of the leases (sheet order), 50 a page, narrowed by the search box and the Show switch.
        $rows = ReosRowQuery::apply(RentalTakeOnImportRow::query()->where('run_id', $run->id), $filters)->paginate(50)->withQueryString();

        return view('corex.rentals.take-on-import.preview-reos', [
            'run' => $run,
            'filters' => $filters,
            'show' => $filters['show'] ?? 'all',
            'rows' => $rows,
            'summary' => ReosRunSummary::for($run),
            'defaults' => ReosRunDefaults::get($run),
            'agents' => app(ReosAgentMatcher::class)->users((int) $run->agency_id),
            'canManage' => $canManage,
            'continueImport' => (bool) session('reos_continue'),
        ]);
    }

    /** Import the ticked leases — in chunks, the page carrying on by itself until none are left. */
    public function import(Request $request, RentalTakeOnImportRun $run, ReosTenancyConfirmService $service): RedirectResponse
    {
        $this->assertVisible($request, $run);
        abort_unless($run->isReos() && ! $run->trashed() && (int) $run->agency_id === (int) $request->user()->effectiveAgencyId(), 404);

        if (in_array($run->status, [RentalTakeOnImportRun::STATUS_CANCELLED, RentalTakeOnImportRun::STATUS_FAILED], true)) {
            return back()->withErrors(['run' => 'This batch was cancelled, so it cannot be imported.']);
        }

        $summary = ReosRunSummary::for($run);
        if (! $request->boolean('continue') && ! $summary['can_import']) {
            return back()->withErrors(['run' => $summary['needs'] > 0 ? 'No lease is ready yet — answer the questions first.' : 'There is no lease ready to import.']);
        }

        $result = $service->importChunk($run, $request->user(), retryFailed: ! $request->boolean('continue'));

        $run->refresh();
        $c = $run->counts_json ?? [];
        $message = 'Imported so far: ' . (int) ($c['imported_created'] ?? 0) . ' new, ' . (int) ($c['imported_updated'] ?? 0) . ' updated'
            . ((int) ($c['imported_failed'] ?? 0) > 0 ? ', ' . (int) $c['imported_failed'] . ' could not be imported (each says why)' : '') . '.';

        if ($result['remaining'] > 0) {
            return redirect()->route('corex.rentals.take-on-import.preview', $run)
                ->with('reos_continue', true)
                ->with('status', $message . ' Carrying on — please keep this page open…');
        }

        return redirect()->route('corex.rentals.take-on-import.preview', $run)->with('status', $message . ' Finished.');
    }
}
