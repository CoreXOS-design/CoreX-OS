<?php

namespace App\Services\Auctions;

use App\Events\Auction\AuctionInvoiceIssued;
use App\Events\Auction\AuctionPaymentRecorded;
use App\Mail\Auctions\AuctionBidderMail;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionBid;
use App\Models\AuctionInvoice;
use App\Models\AuctionInvoicePayment;
use App\Models\AuctionLot;
use App\Models\AuctionRegistration;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Bidder invoices (.ai/specs/auctions.md §17). A draft is created when a lot is sold; staff issue it (which numbers it and
 * emails the buyer the PDF), record payments by hand, tick the bank guarantee, and may cancel it. The figures are a snapshot of the
 * sum at the moment of sale, so changing a setting later never rewrites an invoice.
 */
class AuctionInvoiceService
{
    public function __construct(private AuctionFeeCalculator $fees) {}

    /** Create the draft invoice for a sold lot. Idempotent: a lot that already has a live invoice gets none. */
    public function createDraftForSoldLot(AuctionLot $lot): ?AuctionInvoice
    {
        $lot = AuctionLot::withoutGlobalScopes()->find($lot->id);
        if (! $lot || $lot->status !== AuctionLot::STATUS_SOLD || ! $lot->winning_bid_id) {
            return null;
        }
        $existing = AuctionInvoice::withoutGlobalScopes()->where('lot_id', $lot->id)->where('status', '!=', AuctionInvoice::STATUS_CANCELLED)->first();
        if ($existing) {
            return $existing;
        }

        $bid = AuctionBid::withoutGlobalScopes()->find($lot->winning_bid_id);
        $auction = Auction::withTrashed()->withoutGlobalScopes()->with('increments')->find($lot->auction_id);
        if (! $bid || ! $auction) {
            return null;
        }
        $sum = $this->fees->forSale($auction, (float) $lot->hammer_price, now());

        return AuctionInvoice::withoutAgencyStamping(fn () => AuctionInvoice::create($sum + [
            'agency_id' => $lot->agency_id, 'auction_id' => $lot->auction_id, 'lot_id' => $lot->id, 'registration_id' => $bid->registration_id,
            'deal_id' => $lot->deal_id, 'status' => AuctionInvoice::STATUS_DRAFT,
            'payment_details' => AgencyAuctionSettings::paymentDetailsFor((int) $lot->agency_id) ?: null,
        ]));
    }

    /** Number the invoice, mark it issued and email the buyer the PDF. */
    public function issue(AuctionInvoice $invoice, User $by): AuctionInvoice
    {
        $invoice = DB::transaction(function () use ($invoice, $by) {
            $invoice = AuctionInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== AuctionInvoice::STATUS_DRAFT) {
                throw $this->refuse('Only a draft invoice can be issued.');
            }
            if ((float) $invoice->total_amount <= 0) {
                throw $this->refuse('This invoice has nothing to pay.');
            }
            $invoice->forceFill([
                'number' => $invoice->number ?: $this->nextNumber((int) $invoice->agency_id),
                'status' => AuctionInvoice::STATUS_ISSUED, 'issued_at' => now(), 'issued_by' => $by->id,
            ])->save();

            return $invoice;
        });

        AuctionInvoiceIssued::dispatch($invoice, $by->id);
        $this->email($invoice);

        return $invoice;
    }

    /**
     * Record money received. Never more than is owing.
     *
     * @throws ValidationException
     */
    public function recordPayment(AuctionInvoice $invoice, string $kind, float $amount, string $receivedAt, ?string $reference, User $by): AuctionInvoicePayment
    {
        $payment = DB::transaction(function () use ($invoice, $kind, $amount, $receivedAt, $reference, $by) {
            $invoice = AuctionInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            if (! in_array($invoice->status, [AuctionInvoice::STATUS_ISSUED, AuctionInvoice::STATUS_PART_PAID], true)) {
                throw $this->refuse('Payments can only be recorded on an issued invoice that is not yet fully paid.');
            }
            $amount = round($amount, 2);
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Enter the amount received.']);
            }
            if ($amount - $invoice->outstanding() > 0.005) {
                throw ValidationException::withMessages(['amount' => 'That is more than is owing (R ' . number_format($invoice->outstanding(), 2, '.', ' ') . ').']);
            }

            $payment = AuctionInvoicePayment::withoutAgencyStamping(fn () => AuctionInvoicePayment::create([
                'agency_id' => $invoice->agency_id, 'invoice_id' => $invoice->id,
                'kind' => array_key_exists($kind, AuctionInvoicePayment::KINDS) ? $kind : 'other',
                'amount' => $amount, 'received_at' => Carbon::parse($receivedAt)->toDateString(),
                'reference' => $reference !== null ? mb_substr(trim($reference), 0, 120) ?: null : null, 'recorded_by' => $by->id,
            ]));
            $this->recompute($invoice);

            return $payment;
        });

        AuctionPaymentRecorded::dispatch($invoice->refresh(), $payment);

        return $payment;
    }

    /** Undo a payment recorded by mistake. It is archived with its reason; the invoice's status follows. */
    public function voidPayment(AuctionInvoice $invoice, AuctionInvoicePayment $payment, string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Say why the payment is being voided.']);
        }
        DB::transaction(function () use ($invoice, $payment, $reason) {
            $invoice = AuctionInvoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            $payment->forceFill(['void_reason' => mb_substr(trim($reason), 0, 300)])->save();
            $payment->delete();
            $this->recompute($invoice);
        });
    }

    public function setGuarantee(AuctionInvoice $invoice, bool $received, ?string $note): AuctionInvoice
    {
        $invoice->forceFill([
            'guarantee_received_at' => $received ? ($invoice->guarantee_received_at ?? now()) : null,
            'guarantee_note' => $received && $note !== null ? mb_substr(trim($note), 0, 300) ?: null : null,
        ])->save();

        return $invoice;
    }

    public function cancel(AuctionInvoice $invoice, string $reason): AuctionInvoice
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Give a reason for cancelling the invoice.']);
        }
        if ($invoice->status === AuctionInvoice::STATUS_CANCELLED) {
            return $invoice;
        }
        if ($invoice->paidTotal() > 0) {
            throw $this->refuse('Money has been recorded against this invoice. Void the payments first.');
        }
        $invoice->forceFill(['status' => AuctionInvoice::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => mb_substr(trim($reason), 0, 300)])->save();

        return $invoice;
    }

    /** A cancelled invoice comes back as a draft (keeping its number, which is never reused) if the lot has no other live invoice. */
    public function restore(AuctionInvoice $invoice): AuctionInvoice
    {
        if ($invoice->status !== AuctionInvoice::STATUS_CANCELLED) {
            return $invoice;
        }
        $other = AuctionInvoice::withoutGlobalScopes()->where('lot_id', $invoice->lot_id)->where('id', '!=', $invoice->id)->where('status', '!=', AuctionInvoice::STATUS_CANCELLED)->exists();
        if ($other) {
            throw $this->refuse('This lot already has a newer invoice.');
        }
        $invoice->forceFill(['status' => AuctionInvoice::STATUS_DRAFT, 'cancelled_at' => null, 'cancel_reason' => null, 'issued_at' => null, 'issued_by' => null])->save();

        return $invoice;
    }

    /** Raw PDF bytes of the invoice. */
    public function pdf(AuctionInvoice $invoice): string
    {
        $invoice->loadMissing(['auction', 'lot.property', 'registration.contact', 'registration.clientUser', 'payments']);
        $agency = Agency::withoutGlobalScopes()->find($invoice->agency_id);

        return (string) Pdf::setOptions(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'dpi' => 96, 'defaultFont' => 'DejaVu Sans'])
            ->loadView('corex.auctions.invoices.pdf', ['invoice' => $invoice, 'agency' => $agency, 'logoData' => $this->logoDataUri($agency)])
            ->setPaper('a4', 'portrait')->output();
    }

    public function filename(AuctionInvoice $invoice): string
    {
        return 'Invoice-' . str_replace(['/', ' '], '-', $invoice->number ?: 'draft-' . $invoice->id) . '.pdf';
    }

    /** Email the buyer their invoice as the agency, with the PDF attached. A failed send is logged and never blocks. */
    public function email(AuctionInvoice $invoice): bool
    {
        try {
            $reg = AuctionRegistration::withoutGlobalScopes()->with(['clientUser' => fn ($q) => $q->withoutGlobalScopes(), 'contact'])->find($invoice->registration_id);
            $auction = Auction::withTrashed()->withoutGlobalScopes()->find($invoice->auction_id);
            $lot = AuctionLot::withTrashed()->withoutGlobalScopes()->find($invoice->lot_id);
            $agency = Agency::withoutGlobalScopes()->find($invoice->agency_id);
            $to = $reg?->clientUser?->email;
            if (! $to || ! $auction || ! $agency || ! filter_var($to, FILTER_VALIDATE_EMAIL) || str_ends_with($to, '@' . config('clientauth.fake_email_domain', 'corexclient.co.za'))) {
                return false;
            }

            $money = fn ($v) => 'R ' . number_format((float) $v, 2, '.', ' ');
            Mail::to($to)->send(new AuctionBidderMail('invoice', $reg->displayName(), $auction, $lot, route('public.auctions.account', $agency->slug), [
                'number' => $invoice->number, 'total' => $money($invoice->total_amount), 'deposit' => $money($invoice->deposit_amount),
                'deposit_due' => $invoice->deposit_due_at?->format('j M Y'), 'balance' => $money($invoice->balance_amount),
                'balance_due' => $invoice->balance_due_at?->format('j M Y'), 'payment_details' => $invoice->payment_details,
            ], $this->pdf($invoice), $this->filename($invoice)));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Auction invoice email failed: ' . $e->getMessage(), ['invoice' => $invoice->id]);

            return false;
        }
    }

    // ── internals ──────────────────────────────────────────────────────────

    /** Status follows the money: nothing = issued, some = part paid, all = paid. */
    private function recompute(AuctionInvoice $invoice): void
    {
        if (! in_array($invoice->status, [AuctionInvoice::STATUS_ISSUED, AuctionInvoice::STATUS_PART_PAID, AuctionInvoice::STATUS_PAID], true)) {
            return;
        }
        $paid = $invoice->paidTotal();
        if ($paid + 0.005 >= (float) $invoice->total_amount) {
            $invoice->forceFill(['status' => AuctionInvoice::STATUS_PAID, 'paid_at' => $invoice->paid_at ?? now()])->save();
        } else {
            $invoice->forceFill(['status' => $paid > 0 ? AuctionInvoice::STATUS_PART_PAID : AuctionInvoice::STATUS_ISSUED, 'paid_at' => null])->save();
        }
    }

    /** {PREFIX}-INV-{year}-{0001}, counted within the agency; a number is never reused. */
    private function nextNumber(int $agencyId): string
    {
        $prefix = AgencyAuctionSettings::referencePrefixFor($agencyId) . '-INV-' . now()->year . '-';
        $n = AuctionInvoice::withTrashed()->withoutGlobalScopes()->where('agency_id', $agencyId)->where('number', 'like', $prefix . '%')->count() + 1;

        do {
            $number = $prefix . str_pad((string) $n++, 4, '0', STR_PAD_LEFT);
        } while (AuctionInvoice::withTrashed()->withoutGlobalScopes()->where('agency_id', $agencyId)->where('number', $number)->exists());

        return $number;
    }

    private function logoDataUri(?Agency $agency): ?string
    {
        $path = $agency?->logo_path;
        $full = $path ? storage_path('app/public/' . ltrim($path, '/')) : null;
        if (! $full || ! is_file($full) || ! is_readable($full)) {
            return null;
        }
        $mime = match (strtolower(pathinfo($full, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', default => 'image/jpeg',
        };

        return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($full));
    }

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['invoice' => $message]);
    }
}
