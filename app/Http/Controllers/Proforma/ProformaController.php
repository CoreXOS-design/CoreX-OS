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
