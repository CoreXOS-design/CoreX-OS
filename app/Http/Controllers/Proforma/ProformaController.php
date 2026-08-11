<?php

namespace App\Http\Controllers\Proforma;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Proforma\ProformaInvoice;
use App\Services\Proforma\ProformaFinancialResolver;
use App\Services\Proforma\ProformaGenerationService;
use App\Services\Proforma\ProformaPdfRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deal-facing proforma actions: generate (any agent, granted-onward), view, download.
 */
class ProformaController extends Controller
{
    /** POST — generate a new proforma for a deal. */
    public function generate(Deal $deal, ProformaGenerationService $generation, ProformaFinancialResolver $resolver, Request $request)
    {
        abort_unless($request->user()?->hasPermission('proforma.generate'), 403);

        // Granted-onward gate (server-authoritative — never trust the hidden button).
        if (! $resolver->isEligible($deal)) {
            return back()->with('error', $resolver->ineligibleReason($deal));
        }

        try {
            $invoice = $generation->generate($deal, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Proforma {$invoice->number} generated and filed on the deal.")
            ->with('proforma_generated_id', $invoice->id);
    }

    /**
     * GET — admin/agent-facing proforma invoice LIST. Scoped via
     * ProformaInvoice::scopeVisibleTo() (own/branch/all, same shape as
     * Deal::scopeVisibleTo) — never a raw agency-wide query. View/Download
     * links reuse the existing show()/download() routes below; no PDF logic here.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user?->hasPermission('proforma.view'), 403);

        $status = $request->get('status', 'all');
        $search = trim((string) $request->get('q', ''));

        $query = ProformaInvoice::visibleTo($user)
            ->with(['creator:id,name', 'agency:id,name'])
            ->latest('id');

        if (in_array($status, [ProformaInvoice::STATUS_ISSUED, ProformaInvoice::STATUS_VOIDED], true)) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                  ->orWhere('reference', 'like', "%{$search}%");
            });
        }

        $invoices = $query->paginate(30)->withQueryString();

        // Agency column only makes sense for the one role that can legitimately cross
        // agencies (AgencyScope's owner-role bypass) — everyone else is single-agency.
        $showAgencyColumn = (bool) $user->isOwnerRole();

        return view('proforma.index', compact('invoices', 'status', 'search', 'showAgencyColumn'));
    }

    /** GET — view a proforma record. */
    public function show(ProformaInvoice $invoice, Request $request)
    {
        abort_unless($request->user()?->hasPermission('proforma.generate'), 403);
        $invoice->load(['lines', 'deal']);

        return view('proforma.show', ['invoice' => $invoice]);
    }

    /** GET — stream the proforma PDF (rendered from the record — deterministic). */
    public function download(ProformaInvoice $invoice, ProformaPdfRenderer $pdf, Request $request): Response
    {
        abort_unless($request->user()?->hasPermission('proforma.generate'), 403);

        $bytes = $pdf->render($invoice);

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $pdf->filename($invoice) . '"',
        ]);
    }
}
