<?php

namespace App\Services\Proforma;

use App\Models\Agency;
use App\Models\Deal;
use App\Models\DealV2\DealActivityLog;
use App\Models\Proforma\AgencyProformaSettings;
use App\Models\Proforma\ProformaInvoice;
use App\Models\Proforma\ProformaInvoiceAudit;
use App\Models\Proforma\ProformaInvoiceLine;
use App\Models\SplitterDocType;
use App\Models\User;
use App\Notifications\Proforma\ProformaCreatedNotification;
use App\Models\Scopes\AgencyScope;
use App\Services\DealV2\DealDocumentService;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrates proforma generation: eligibility gate → atomic number → record + locked
 * commission line → PDF → auto-file (3 pillars) → email (Mailpit) → audit + admin notify.
 * The record is the source of truth; PDF/email are side effects that never block it.
 */
class ProformaGenerationService
{
    public function __construct(
        private ProformaFinancialResolver $resolver,
        private ProformaNumberService $numbers,
        private ProformaPdfRenderer $pdf,
        private ProformaMailer $mailer,
        private DealDocumentService $documents,
    ) {}

    /**
     * Generate a new proforma for a deal. Caller must have already checked the
     * `proforma.generate` permission; this enforces the granted-onward gate.
     *
     * @throws \DomainException when the deal is not eligible (pending/declined).
     */
    public function generate(Deal $deal, User $actor): ProformaInvoice
    {
        if (! $this->resolver->isEligible($deal)) {
            throw new \DomainException($this->resolver->ineligibleReason($deal) ?? 'Deal not eligible for a proforma.');
        }

        // ONE active proforma per deal — a new one only via admin void → generate.
        // Fast pre-check (clean UI error); the authoritative race-safe check is inside
        // the transaction, behind the settings-row lock.
        if ($this->hasActiveProforma($deal)) {
            throw new \DomainException('This deal already has an active proforma — void it first to issue a new one.');
        }

        $fin      = $this->resolver->resolve($deal);
        $settings = AgencyProformaSettings::forAgency((int) $deal->agency_id);
        $dueDate  = $settings->resolveDueDate(now());

        // 1) Record + locked commission line + number — one atomic transaction.
        $invoice = DB::transaction(function () use ($deal, $actor, $fin, $dueDate) {
            [$sequence, $number] = $this->numbers->allocate((int) $deal->agency_id);

            // Race-safe re-check: allocate() locked the agency's settings row FOR UPDATE, so a
            // concurrent generate for this deal is serialised behind it and sees the committed
            // active proforma here. Throwing rolls back the allocation (no number wasted).
            if ($this->hasActiveProforma($deal)) {
                throw new \DomainException('This deal already has an active proforma.');
            }

            $invoice = new ProformaInvoice();
            $invoice->forceFill([
                'agency_id'            => $deal->agency_id,  // explicit (AT-203 landmine)
                'deal_id'              => $deal->id,
                'number'               => $number,
                'sequence_no'          => $sequence,
                'status'               => ProformaInvoice::STATUS_ISSUED,
                'issued_to_contact_id' => $fin['seller_contact_id'],
                'issued_to_name'       => $fin['seller_name'],
                'care_of_provider_id'  => $fin['attorney_provider_id'],
                'care_of_name'         => $fin['attorney_name'],
                'reference'            => $fin['reference'],
                'due_date'             => $dueDate,
                'vat_registered'       => $fin['vat_registered'],
                'vat_rate'             => $fin['vat_rate'],
                'created_by_id'        => $actor->id,
            ])->save();

            ProformaInvoiceLine::create([
                'proforma_invoice_id' => $invoice->id,
                'agency_id'           => $deal->agency_id,
                'description'         => 'Sales commission',
                'amount_excl'         => $fin['commission_excl'],
                'vat_amount'          => $fin['commission_vat'],
                'amount_incl'         => $fin['commission_incl'],
                'kind'                => ProformaInvoiceLine::KIND_COMMISSION,
                'is_locked'           => true,   // agents/BMs cannot edit
                'created_by_id'       => $actor->id,
                'sort_order'          => 0,
            ]);

            $invoice->recalcTotals();
            $invoice->save();

            return $invoice;
        });

        // 2) Side effects (never block the record).
        $this->renderFileEmail($deal, $invoice, $actor);

        // 3) Audit + deal timeline + admin notify.
        ProformaInvoiceAudit::record($invoice, ProformaInvoiceAudit::EVENT_GENERATED, $actor->id, [
            'number'     => $invoice->number,
            'total_incl' => (float) $invoice->total_incl,
        ]);
        $this->logActivity($deal, $actor, 'proforma_generated', "Generated proforma {$invoice->number}", $invoice);
        $this->notifyAdmins($deal, $invoice, $actor, $fin['reference']);

        return $invoice;
    }

    /** The deal's current active (issued, non-voided) proforma, if any. */
    public function activeProforma(Deal $deal): ?ProformaInvoice
    {
        return ProformaInvoice::withoutGlobalScopes()
            ->where('deal_id', $deal->id)
            ->where('status', ProformaInvoice::STATUS_ISSUED)
            ->latest('id')
            ->first();
    }

    /** One active proforma per deal — true when a new generate must be refused. */
    public function hasActiveProforma(Deal $deal): bool
    {
        return ProformaInvoice::withoutGlobalScopes()
            ->where('deal_id', $deal->id)
            ->where('status', ProformaInvoice::STATUS_ISSUED)
            ->exists();
    }

    /** Render the PDF, file it on the deal (3 pillars), email it. Re-usable by regenerate. */
    public function renderFileEmail(Deal $deal, ProformaInvoice $invoice, User $actor): void
    {
        try {
            $bytes    = $this->pdf->render($invoice);
            $filename = $this->pdf->filename($invoice);
            $disk     = config('filesystems.default', 'local');
            $path     = "deals/{$deal->id}/proforma/{$invoice->number}.pdf";
            Storage::disk($disk)->put($path, $bytes);

            $doc = $this->documents->fileDealDocumentFromDeal($deal, [
                'original_name'    => $filename,
                'storage_path'     => $path,
                'disk'             => $disk,
                'mime_type'        => 'application/pdf',
                'size'             => strlen($bytes),
                'document_type_id' => $this->proformaDocumentTypeId(),
                'source_type'      => 'proforma_generated',
            ], $actor);

            $invoice->document_id = $doc->id;
            $invoice->save();

            // Ruling 3 — the proforma files to deal + property + SELLER. The bridge links the
            // property's contacts; guarantee the resolved seller is reached even on thin data.
            if ($invoice->issued_to_contact_id) {
                $doc->contacts()->syncWithoutDetaching([$invoice->issued_to_contact_id]);
            }

            if ($this->mailer->send($invoice, $bytes, $filename)) {
                ProformaInvoiceAudit::record($invoice, ProformaInvoiceAudit::EVENT_EMAILED, $actor->id, [
                    'to_contact_id' => $invoice->issued_to_contact_id,
                ]);
            }
        } catch (\Throwable $e) {
            // The record stands; an admin can regenerate. Never surface a 500 to the agent.
            Log::error('Proforma side-effects failed for ' . $invoice->number . ': ' . $e->getMessage());
        }
    }

    private function proformaDocumentTypeId(): ?int
    {
        return SplitterDocType::withoutGlobalScopes()->where('slug', 'proforma_invoice')->value('id');
    }

    private function logActivity(Deal $deal, User $actor, string $action, string $description, ProformaInvoice $invoice): void
    {
        try {
            DealActivityLog::create([
                'agency_id'   => $deal->agency_id,
                'deal_id'     => $deal->deal_v2_id ?: null,
                'dr1_deal_id' => $deal->id,
                'user_id'     => $actor->id,
                'action'      => $action,
                'description' => $description,
                'metadata'    => ['proforma_id' => $invoice->id, 'number' => $invoice->number],
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Proforma activity log skipped: ' . $e->getMessage());
        }
    }

    /**
     * AT-235 (S1) — CITIZEN #1 OF THE NOTIFICATION GATEWAY.
     *
     * This used to be `Notification::send($admins, new ProformaCreatedNotification(…))`
     * — a raw send that bypassed everything. An admin could not switch it off (it had
     * no catalogue row, so no settings toggle existed), it honoured no preference, no
     * open-hours window and no cooldown, and it wrote nothing to
     * notification_dispatch_log — so nothing recorded that it had fired.
     *
     * The AT-235 build guard caught it on its very first merge, which is fitting: it
     * is the exact feature the findings recommended be gateway-native from the start.
     *
     * It now goes through the gateway. That buys, for free and with no code here:
     *   - the admin can turn it off (Settings → Notifications → "Proforma invoice generated"),
     *   - open-hours suppression and the per-user cooldown,
     *   - an idempotency ledger row, so we can prove what was sent and to whom,
     *   - and channel resolution in ONE place rather than inside via().
     *
     * This is the worked example every other producer migration copies.
     *
     * The dedup key is `now()` — passed EXPLICITLY, not defaulted. Generating a
     * proforma is a DISCRETE event: each generation is a genuinely new fact and
     * should notify. (A persistent condition — "this deal still has no proforma" —
     * would need a STABLE key instead, or it would re-notify on every scan tick.
     * That distinction is what let contact.fica_missing fire 1.9M times; see R3.)
     */
    private function notifyAdmins(Deal $deal, ProformaInvoice $invoice, User $actor, string $reference): void
    {
        try {
            $admins = User::withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $deal->agency_id)
                ->whereIn('role', ['super_admin', 'admin', 'owner'])
                ->get();

            $gateway = app(NotificationDispatcher::class);

            foreach ($admins as $admin) {
                $gateway->send(
                    $admin,
                    'proforma.created',
                    $invoice,
                    new ProformaCreatedNotification($invoice, $actor->name ?? 'An agent', $reference),
                    ['threshold_hit_at' => now()], // discrete event — each generation is its own fact
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Proforma admin notify skipped: ' . $e->getMessage());
        }
    }
}
