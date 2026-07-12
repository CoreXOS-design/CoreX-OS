<?php

namespace App\Http\Controllers\Proforma;

use App\Http\Controllers\Controller;
use App\Models\Proforma\ProformaInvoice;
use App\Models\Proforma\ProformaInvoiceLine;
use App\Services\Proforma\ProformaAdminService;
use Illuminate\Http\Request;

/**
 * ADMIN-ONLY proforma overrides (permission:proforma.manage). Every action audited
 * inside the service. Agents/BMs never reach here.
 */
class ProformaAdminController extends Controller
{
    // Gated by route middleware `permission:proforma.manage` (admin only).
    public function __construct(private ProformaAdminService $admin) {}

    /** Add an admin adjustment line (e.g. "Discount on commission"; negative allowed). */
    public function addLine(ProformaInvoice $invoice, Request $request)
    {
        $data = $request->validate([
            'description' => ['required', 'string', 'max:191'],
            'amount_excl' => ['required', 'numeric'],
        ]);
        $this->admin->addLine($invoice, $request->user(), $data['description'], (float) $data['amount_excl']);

        return back()->with('success', 'Line added to proforma ' . $invoice->number . '.');
    }

    public function removeLine(ProformaInvoice $invoice, ProformaInvoiceLine $line, Request $request)
    {
        abort_unless($line->proforma_invoice_id === $invoice->id, 404);
        try {
            $this->admin->removeLine($invoice, $line, $request->user());
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }
        return back()->with('success', 'Line removed.');
    }

    public function void(ProformaInvoice $invoice, Request $request)
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:191']]);
        $this->admin->void($invoice, $request->user(), $data['void_reason']);

        return back()->with('success', 'Proforma ' . $invoice->number . ' voided (record kept; number not reused).');
    }

    public function regenerate(ProformaInvoice $invoice, Request $request)
    {
        $this->admin->regenerate($invoice, $request->user());

        return back()->with('success', 'Proforma ' . $invoice->number . ' PDF regenerated and re-filed.');
    }
}
