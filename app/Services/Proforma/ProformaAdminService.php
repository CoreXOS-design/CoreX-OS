<?php

namespace App\Services\Proforma;

use App\Models\Deal;
use App\Models\Proforma\AgencyProformaSettings;
use App\Models\Proforma\ProformaInvoice;
use App\Models\Proforma\ProformaInvoiceAudit;
use App\Models\Proforma\ProformaInvoiceLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ADMIN-ONLY proforma operations (add/remove lines, void, regenerate, change number).
 * Every method is audited. Figures on the locked commission line are never touched here —
 * admins add ADJUSTMENT lines (e.g. "Discount on commission", negative excl allowed) or
 * void/renumber; they do not hand-edit the deal-derived commission.
 */
class ProformaAdminService
{
    public function __construct(private ProformaGenerationService $generation) {}

    /** Add an admin adjustment line and recompute totals. */
    public function addLine(ProformaInvoice $invoice, User $admin, string $description, float $amountExcl): ProformaInvoiceLine
    {
        return DB::transaction(function () use ($invoice, $admin, $description, $amountExcl) {
            $excl = round($amountExcl, 2);
            $vat  = $invoice->vat_registered ? round($excl * ((float) $invoice->vat_rate / 100), 2) : 0.0;
            $incl = round($excl + $vat, 2);

            $line = ProformaInvoiceLine::create([
                'proforma_invoice_id' => $invoice->id,
                'agency_id'           => $invoice->agency_id,
                'description'         => $description,
                'amount_excl'         => $excl,
                'vat_amount'          => $vat,
                'amount_incl'         => $incl,
                'kind'                => ProformaInvoiceLine::KIND_ADJUSTMENT,
                'is_locked'           => false,
                'created_by_id'       => $admin->id,
                'sort_order'          => (int) ($invoice->lines()->max('sort_order')) + 1,
            ]);

            $invoice->recalcTotals();
            $invoice->save();

            ProformaInvoiceAudit::record($invoice, ProformaInvoiceAudit::EVENT_LINE_ADDED, $admin->id, [
                'description' => $description, 'amount_excl' => $excl,
            ]);

            return $line;
        });
    }

    /** Remove an admin adjustment line (never the locked commission line). */
    public function removeLine(ProformaInvoice $invoice, ProformaInvoiceLine $line, User $admin): void
    {
        if ($line->is_locked || $line->kind === ProformaInvoiceLine::KIND_COMMISSION) {
            throw new \DomainException('The commission line is locked and cannot be removed.');
        }
        DB::transaction(function () use ($invoice, $line, $admin) {
            $meta = ['description' => $line->description, 'amount_excl' => (float) $line->amount_excl];
            $line->delete(); // soft delete (no hard delete)
            $invoice->recalcTotals();
            $invoice->save();
            ProformaInvoiceAudit::record($invoice, ProformaInvoiceAudit::EVENT_LINE_REMOVED, $admin->id, $meta);
        });
    }

    /** Void a proforma. Record kept; number never reused. */
    public function void(ProformaInvoice $invoice, User $admin, string $reason): void
    {
        if ($invoice->isVoided()) {
            return;
        }
        $invoice->forceFill([
            'status'      => ProformaInvoice::STATUS_VOIDED,
            'voided_by_id' => $admin->id,
            'voided_at'   => now(),
            'void_reason' => $reason,
        ])->save();

        ProformaInvoiceAudit::record($invoice, ProformaInvoiceAudit::EVENT_VOIDED, $admin->id, ['reason' => $reason]);
    }

    /** Re-render + re-file the PDF for the current record (e.g. after adding a line). */
    public function regenerate(ProformaInvoice $invoice, User $admin): void
    {
        $deal = Deal::withoutGlobalScopes()->findOrFail($invoice->deal_id);
        $this->generation->renderFileEmail($deal, $invoice, $admin);
        ProformaInvoiceAudit::record($invoice, ProformaInvoiceAudit::EVENT_REGENERATED, $admin->id, []);
    }

    /**
     * Advance the agency's next start number (admin-only, forward only). Does not
     * change already-issued records; affects the NEXT allocation. Audited on the
     * most recent invoice for a trail (a settings-level change).
     */
    public function advanceStartNumber(int $agencyId, User $admin, int $newNext): void
    {
        $settings = AgencyProformaSettings::forAgency($agencyId);
        if ($newNext <= $settings->next_number) {
            throw new \DomainException('The start number can only be advanced forward (never backward — numbers are never reused).');
        }
        $old = $settings->next_number;
        $settings->update(['next_number' => $newNext]);

        $last = ProformaInvoice::withoutGlobalScopes()->where('agency_id', $agencyId)->latest('id')->first();
        if ($last) {
            ProformaInvoiceAudit::record($last, ProformaInvoiceAudit::EVENT_NUMBER_CHANGED, $admin->id, [
                'from_next' => $old, 'to_next' => $newNext,
            ]);
        }
    }
}
