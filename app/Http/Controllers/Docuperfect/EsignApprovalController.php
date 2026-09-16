<?php

declare(strict_types=1);

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Models\Compliance\OfficerAppointment;
use App\Models\Docuperfect\EsignApproval;
use App\Services\Compliance\OfficerRegistry;
use App\Services\Docuperfect\EsignApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Documents › Approvals — the e-sign officer queue. Spec esign-compliance-approval-gate.md §8.4.
 *
 * Route middleware gates the page on esign_approvals.view; every decision is re-checked in the
 * service (officer appointment + own / branch / all scope). Route-model binding runs through
 * AgencyScope, so another agency's approval id 404s.
 */
class EsignApprovalController extends Controller
{
    public function __construct(
        private EsignApprovalService $approvals,
        private OfficerRegistry $registry,
    ) {}

    public function index(Request $request)
    {
        $user     = Auth::user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $isOfficer = $agencyId > 0 && $this->registry->isOfficer($user, OfficerAppointment::MODULE_ESIGN, $agencyId);
        $isCo      = $agencyId > 0 && $this->registry->isCo($user, OfficerAppointment::MODULE_ESIGN, $agencyId);
        $routeOn   = $agencyId > 0 && $this->registry->esignRouteIsRoCo($agencyId);

        $tab    = $request->query('tab', 'pending') === 'declined' ? 'declined' : 'pending';
        $search = trim((string) $request->query('q', ''));

        $query = $this->approvals->queueQuery($user, [$tab === 'declined' ? EsignApproval::STATUS_DECLINED : EsignApproval::STATUS_PENDING]);
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->whereHas('signatureTemplate.document', fn ($d) => $d->where('name', 'like', "%{$search}%"))
                  ->orWhereHas('requester', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        $approvals = $isOfficer ? $query->paginate(20)->withQueryString() : null;

        $pendingCount  = $isOfficer ? $this->approvals->queueQuery($user)->count() : 0;
        $declinedCount = $isOfficer ? $this->approvals->queueQuery($user, [EsignApproval::STATUS_DECLINED])->count() : 0;

        return view('docuperfect.approvals.index', compact(
            'approvals', 'isOfficer', 'isCo', 'routeOn', 'tab', 'search', 'pendingCount', 'declinedCount'
        ));
    }

    public function approve(Request $request, EsignApproval $approval)
    {
        $note = trim((string) $request->input('note', ''));

        return $this->act($approval, fn ($template) => $this->approvals->approve($template, Auth::user(), $note ?: null),
            'Approved — the document has been sent to the first party.');
    }

    public function decline(Request $request, EsignApproval $approval)
    {
        $request->validate(['reason' => 'required|string|min:3|max:2000']);

        return $this->act($approval, fn ($template) => $this->approvals->decline($template, Auth::user(), (string) $request->input('reason')),
            'Declined — the sender has been told why.');
    }

    public function override(Request $request, EsignApproval $approval)
    {
        $request->validate(['reason' => 'required|string|min:3|max:2000']);

        return $this->act($approval, fn ($template) => $this->approvals->override($template, Auth::user(), (string) $request->input('reason')),
            'Override recorded — the document has been sent to the first party.');
    }

    /** The sender asks again after a decline. */
    public function resubmit(EsignApproval $approval)
    {
        return $this->act($approval, fn ($template) => $this->approvals->resubmit($template, Auth::user()),
            'Sent for approval again — the officers have been told.');
    }

    private function act(EsignApproval $approval, \Closure $action, string $successMessage)
    {
        // A ledger row can outlive its (soft-deleted) document; that is a sentence, not a 500.
        $template = $approval->signatureTemplate;
        if (! $template) {
            return back()->with('error', 'That document no longer exists, so there is nothing to decide.');
        }

        try {
            $action($template);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        } catch (HttpException $e) {
            // A refused decision is a plain sentence on the page, never a 403 screen.
            return back()->with('error', $e->getMessage() ?: 'You cannot do that on this document.');
        }

        return back()->with('success', $successMessage);
    }
}
