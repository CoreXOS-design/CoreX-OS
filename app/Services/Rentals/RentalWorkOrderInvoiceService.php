<?php

namespace App\Services\Rentals;

use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderInvoice;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * .ai/specs/rental-work-orders.md §17.31 — the supplier's invoice DOCUMENT filed against a work order. ONE place does every
 * write (upload, change, replace the file, share with the owner, archive, restore), and every write leaves a row in the work
 * order's history (`rental_work_order_updates`, update_types invoice_*) — that history is the audit trail.
 *
 * Rules, all enforced here so the office screen, the tests and anything added later agree:
 *   - Files live on the PRIVATE `local` disk under rental-work-order-invoices/{agency}/{work order}/ — the same disk and the
 *     same gated-download pattern as quotes and the portal's rental documents. A path is never handed to a browser.
 *   - Nothing is hard-deleted. A replaced file stays on disk and is listed in `superseded_documents`; an archived invoice
 *     is soft-deleted and can be restored.
 *   - The amount is evidence for the cost / who-pays record. It NEVER writes `cost_amount`, `paid_by` or the approval state;
 *     it only pre-fills the Complete form's cost box ({@see self::suggestedCost()}) and is shown beside the recorded cost.
 *   - The owner sees an invoice only while the agent has ticked "share with owner"; the tenant never sees one
 *     ({@see self::ownerPayload()} is the only client-facing shape, and the tenant payload never calls it).
 */
class RentalWorkOrderInvoiceService
{
    public const STORAGE_ROOT = 'rental-work-order-invoices';

    /** Inline-viewable types on the portal; everything else downloads. */
    public const INLINE_MIMES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /**
     * File a new invoice. $data: invoice_number, invoice_date, amount, optional agency_service_provider_id / supplier_name,
     * optional share_with_owner. Throws \InvalidArgumentException for a duplicate invoice number on the same work order.
     *
     * @param array<string, mixed> $data
     */
    public function upload(RentalWorkOrder $workOrder, array $data, UploadedFile $file, User $by): RentalWorkOrderInvoice
    {
        $number = trim((string) $data['invoice_number']);
        $this->assertNumberFree($workOrder, $number, null);

        $stored = $this->storeFile($workOrder, $file);
        $share = (bool) ($data['share_with_owner'] ?? false);

        return DB::transaction(function () use ($workOrder, $data, $number, $stored, $share, $by) {
            $invoice = $workOrder->invoices()->create([
                'agency_id' => $workOrder->agency_id,
                'agency_service_provider_id' => $data['agency_service_provider_id'] ?? null,
                'supplier_name' => $this->cleanName($data['supplier_name'] ?? null),
                'invoice_number' => $number,
                'invoice_date' => $data['invoice_date'],
                'amount' => round((float) $data['amount'], 2),
                'share_with_owner' => $share,
                'shared_at' => $share ? now() : null,
                'uploaded_by_user_id' => $by->id,
            ] + $stored);

            $this->log($workOrder, 'invoice_uploaded', $invoice->describe() . ($share ? ' — shared with the owner' : ''), $by);

            return $invoice;
        });
    }

    /**
     * Change the facts on an invoice and/or replace its file. A new file takes over as the current document; the old one is kept
     * (never deleted) in `superseded_documents`. Sharing is changed here too, so one form can do it all.
     *
     * @param array<string, mixed> $data
     */
    public function update(RentalWorkOrderInvoice $invoice, array $data, ?UploadedFile $file, User $by): RentalWorkOrderInvoice
    {
        $workOrder = $invoice->workOrder()->withoutGlobalScopes()->firstOrFail();
        $before = $invoice->describe();
        $number = trim((string) ($data['invoice_number'] ?? $invoice->invoice_number));
        $this->assertNumberFree($workOrder, $number, $invoice->id);

        $changes = [
            'invoice_number' => $number,
            'invoice_date' => $data['invoice_date'] ?? $invoice->invoice_date,
            'amount' => isset($data['amount']) ? round((float) $data['amount'], 2) : $invoice->amount,
            'agency_service_provider_id' => array_key_exists('agency_service_provider_id', $data) ? $data['agency_service_provider_id'] : $invoice->agency_service_provider_id,
            'supplier_name' => array_key_exists('supplier_name', $data) ? $this->cleanName($data['supplier_name']) : $invoice->supplier_name,
        ];

        $replaced = false;
        if ($file) {
            $superseded = $invoice->superseded_documents ?? [];
            $superseded[] = [
                'path' => $invoice->document_storage_path,
                'original_name' => $invoice->document_original_name,
                'replaced_at' => now()->toIso8601String(),
                'replaced_by' => $by->id,
            ];
            $changes += $this->storeFile($workOrder, $file) + ['superseded_documents' => $superseded];
            $replaced = true;
        }

        return DB::transaction(function () use ($invoice, $workOrder, $changes, $replaced, $before, $by, $data) {
            $invoice->forceFill($changes);
            $changed = $replaced || $invoice->isDirty();
            $invoice->save();

            $after = $invoice->describe();
            if ($changed) {
                $this->log(
                    $workOrder,
                    $replaced ? 'invoice_replaced' : 'invoice_changed',
                    $replaced ? "File replaced — {$after}" : "{$before} → {$after}",
                    $by
                );
            }

            if (array_key_exists('share_with_owner', $data)) {
                $this->setSharing($invoice, (bool) $data['share_with_owner'], $by);
            }

            return $invoice->fresh();
        });
    }

    /** Tick / untick "share with owner". Logged only when it actually changes. */
    public function setSharing(RentalWorkOrderInvoice $invoice, bool $share, User $by): void
    {
        if ($invoice->share_with_owner === $share) {
            return;
        }
        $invoice->forceFill(['share_with_owner' => $share, 'shared_at' => $share ? now() : null])->save();
        $workOrder = $invoice->workOrder()->withoutGlobalScopes()->firstOrFail();
        $this->log($workOrder, $share ? 'invoice_shared' : 'invoice_unshared', $invoice->describe(), $by);
    }

    public function archive(RentalWorkOrderInvoice $invoice, User $by): void
    {
        $workOrder = $invoice->workOrder()->withoutGlobalScopes()->firstOrFail();
        $invoice->forceFill(['archived_by_user_id' => $by->id])->save();
        $invoice->delete();
        $this->log($workOrder, 'invoice_archived', $invoice->describe(), $by);
    }

    public function restore(RentalWorkOrderInvoice $invoice, User $by): void
    {
        $workOrder = $invoice->workOrder()->withoutGlobalScopes()->firstOrFail();
        $this->assertNumberFree($workOrder, $invoice->invoice_number, $invoice->id);
        $invoice->restore();
        $invoice->forceFill(['archived_by_user_id' => null])->save();
        $this->log($workOrder, 'invoice_restored', $invoice->describe(), $by);
    }

    /** Sum of the live (not archived) invoices — the figure offered to the Complete form and shown beside the recorded cost. */
    public function total(RentalWorkOrder $workOrder): float
    {
        return round((float) $workOrder->invoices()->sum('amount'), 2);
    }

    /**
     * What the Complete form's cost box starts with: the invoices' total, but only while no cost has been recorded and at
     * least one live invoice exists. A suggestion only — the agent still presses Complete, and every completion rule
     * (approval gate, final cost vs approved amount, paid-by) runs exactly as before on whatever figure is submitted.
     */
    public function suggestedCost(RentalWorkOrder $workOrder): ?float
    {
        if ($workOrder->cost_amount !== null || ! $workOrder->invoices()->exists()) {
            return null;
        }

        return $this->total($workOrder);
    }

    /**
     * The ONLY client-facing shape of an invoice: the owner's portal, shared invoices only. No uploader, no internal
     * supplier id, no storage path; the link is an authorised in-portal route, never a file URL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function ownerPayload(RentalWorkOrder $workOrder): array
    {
        return $this->sharedInvoices($workOrder)->map(fn (RentalWorkOrderInvoice $i) => [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'invoice_date' => $i->invoice_date?->toDateString(),
            'amount' => (float) $i->amount,
            'supplier' => $i->supplierLabel(),
            'file_name' => $i->document_original_name,
            'view_url' => route('client.rentals.landlord.work-orders.invoices.file', ['workOrder' => $workOrder->id, 'invoice' => $i->id], false),
            'download_url' => route('client.rentals.landlord.work-orders.invoices.file', ['workOrder' => $workOrder->id, 'invoice' => $i->id], false) . '?download=1',
        ])->values()->all();
    }

    /** @return Collection<int, RentalWorkOrderInvoice> live invoices the agent has shared, newest first (no request scope — the caller resolved the work order). */
    public function sharedInvoices(RentalWorkOrder $workOrder): Collection
    {
        return RentalWorkOrderInvoice::withoutGlobalScopes()
            ->where('agency_id', $workOrder->agency_id)
            ->where('rental_work_order_id', $workOrder->id)
            ->where('share_with_owner', true)
            ->whereNull('deleted_at')
            ->with('supplier')
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->get();
    }

    /** One shared invoice of an already-resolved work order, or null (archived, unshared, or another work order's). */
    public function sharedInvoice(RentalWorkOrder $workOrder, int $invoiceId): ?RentalWorkOrderInvoice
    {
        return $this->sharedInvoices($workOrder)->firstWhere('id', $invoiceId);
    }

    /** Is this file a type / size this agency allows? Used by the controller's validation rules. @return array<int, string> */
    public function fileRules(?int $agencyId, bool $required): array
    {
        $kb = RentalWorkOrderSetting::invoiceMaxFileMbFor($agencyId) * 1024;

        return [
            $required ? 'required' : 'nullable',
            'file',
            'mimes:' . implode(',', RentalWorkOrderSetting::invoiceAllowedExtensionsFor($agencyId)),
            'max:' . $kb,
        ];
    }

    /** The plain-words version of the limits, for the form help text. */
    public function limitsText(?int $agencyId): string
    {
        return strtoupper(implode(', ', RentalWorkOrderSetting::invoiceAllowedExtensionsFor($agencyId)))
            . ' — up to ' . RentalWorkOrderSetting::invoiceMaxFileMbFor($agencyId) . ' MB';
    }

    /** @return array{document_storage_path: string, document_original_name: string, document_mime: ?string, document_size: ?int} */
    private function storeFile(RentalWorkOrder $workOrder, UploadedFile $file): array
    {
        $path = $file->store(self::STORAGE_ROOT . "/{$workOrder->agency_id}/{$workOrder->id}", 'local');
        if (! $path) {
            throw new \RuntimeException('The invoice file could not be saved.');
        }

        return [
            'document_storage_path' => $path,
            'document_original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'document_mime' => $file->getMimeType() ?: $file->getClientMimeType(),
            'document_size' => $file->getSize() ?: null,
        ];
    }

    private function assertNumberFree(RentalWorkOrder $workOrder, string $number, ?int $exceptId): void
    {
        $taken = $workOrder->invoices()
            ->whereRaw('LOWER(invoice_number) = ?', [mb_strtolower($number)])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
        if ($taken) {
            throw new \InvalidArgumentException("Invoice {$number} is already filed against this work order.");
        }
    }

    private function cleanName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : mb_substr($name, 0, 191);
    }

    private function log(RentalWorkOrder $workOrder, string $type, string $note, User $by): void
    {
        $workOrder->updates()->create([
            'agency_id' => $workOrder->agency_id, 'update_type' => $type, 'note' => $note, 'created_by_user_id' => $by->id,
        ]);
    }

    /** Streams one invoice file from the private disk with the same headers the portal's rental documents use. */
    public function response(RentalWorkOrderInvoice $invoice, bool $download, ?string $name = null): ?\Symfony\Component\HttpFoundation\Response
    {
        $disk = Storage::disk('local');
        if (! $invoice->document_storage_path || ! $disk->exists($invoice->document_storage_path)) {
            return null;
        }
        $mime = $invoice->document_mime ?: ($disk->mimeType($invoice->document_storage_path) ?: 'application/octet-stream');
        $inline = ! $download && in_array(strtolower($mime), self::INLINE_MIMES, true);
        $headers = ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0'];
        $ext = pathinfo($invoice->document_storage_path, PATHINFO_EXTENSION);
        $filename = $name ?: ('Invoice-' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $invoice->invoice_number) . ($ext ? ".{$ext}" : ''));

        return $inline
            ? $disk->response($invoice->document_storage_path, $filename, $headers, 'inline')
            : $disk->download($invoice->document_storage_path, $filename, $headers);
    }
}
