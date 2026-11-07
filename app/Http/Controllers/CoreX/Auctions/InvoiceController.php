<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\AuctionInvoice;
use App\Models\AuctionInvoicePayment;
use App\Models\AuctionLot;
use App\Services\Auctions\AuctionInvoiceService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Screen S10 — Invoices (.ai/specs/auctions.md §17): the winning bidders' invoices. Review the draft, issue it (numbers it and
 * emails the buyer the PDF), record the deposit and balance as they arrive, tick the bank guarantee, cancel or restore.
 */
class InvoiceController extends Controller
{
    private const SORTABLE = ['created_at', 'number', 'total_amount', 'status', 'balance_due_at'];

    public function __construct(private AuctionInvoiceService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $auctionId = trim((string) $request->query('auction_id', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $only = trim((string) $request->query('only', ''));        // outstanding | overdue
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'created_at';
        $dir = $request->query('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = AuctionInvoice::visibleTo($user)->with(['auction', 'lot.property', 'registration.contact', 'registration.clientUser']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('number', 'like', "%{$search}%")
                    ->orWhereHas('lot', fn ($l) => $l->withTrashed()->where('lot_number', $search)->orWhere('title', 'like', "%{$search}%")
                        ->orWhereHas('property', fn ($p) => $p->where('title', 'like', "%{$search}%")->orWhere('address', 'like', "%{$search}%")->orWhere('street_name', 'like', "%{$search}%")))
                    ->orWhereHas('registration', fn ($r) => $r->withTrashed()->where('company_name', 'like', "%{$search}%")
                        ->orWhere('bidder_number', ctype_digit($search) ? (int) $search : -1)
                        ->orWhereHas('clientUser', fn ($c) => $c->where('email', 'like', "%{$search}%"))
                        ->orWhereHas('contact', fn ($c) => $c->withoutGlobalScopes()->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")));
            });
        }
        if (in_array($status, AuctionInvoice::STATUSES, true)) {
            $query->where('status', $status);
        }
        if ($auctionId !== '' && ctype_digit($auctionId)) {
            $query->where('auction_id', (int) $auctionId);
        }
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }
        if (in_array($only, ['outstanding', 'overdue'], true)) {
            $query->whereIn('status', [AuctionInvoice::STATUS_ISSUED, AuctionInvoice::STATUS_PART_PAID]);
        }

        $query->orderBy($sort, $dir)->orderByDesc('id');

        // "overdue" depends on what was paid, so it is decided on the rows (a few hundred at most per agency).
        if ($only === 'overdue') {
            $all = $query->get()->filter(fn (AuctionInvoice $i) => $i->isOverdue())->values();
            $page = max(1, (int) $request->query('page', 1));
            $invoices = new \Illuminate\Pagination\LengthAwarePaginator($all->forPage($page, 25)->values(), $all->count(), 25, $page, ['path' => $request->url(), 'query' => $request->query()]);
        } else {
            $invoices = $query->paginate(25)->withQueryString();
        }

        $outstanding = AuctionInvoice::visibleTo($user)->whereIn('status', [AuctionInvoice::STATUS_ISSUED, AuctionInvoice::STATUS_PART_PAID])->get();

        return view('corex.auctions.invoices.index', [
            'invoices' => $invoices,
            'filters' => compact('search', 'status', 'auctionId', 'dateFrom', 'dateTo', 'only', 'sort', 'dir'),
            'auctions' => Auction::visibleTo($user)->orderByDesc('starts_at')->get(['id', 'reference', 'title']),
            'owing' => round($outstanding->sum(fn (AuctionInvoice $i) => $i->outstanding()), 2),
            'overdueCount' => $outstanding->filter(fn (AuctionInvoice $i) => $i->isOverdue())->count(),
            'draftCount' => AuctionInvoice::visibleTo($user)->where('status', AuctionInvoice::STATUS_DRAFT)->count(),
        ]);
    }

    public function show(Request $request, int $invoice)
    {
        $invoice = $this->find($request, $invoice);
        $invoice->load(['auction', 'lot.property', 'registration.contact', 'registration.clientUser', 'payments.recorder']);

        return view('corex.auctions.invoices.show', [
            'invoice' => $invoice,
            'voided' => AuctionInvoicePayment::onlyTrashed()->where('invoice_id', $invoice->id)->orderBy('id')->get(),
        ]);
    }

    public function pdf(Request $request, int $invoice)
    {
        $invoice = $this->find($request, $invoice);

        return response($this->service->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $this->service->filename($invoice) . '"',
        ]);
    }

    /** Make the draft for a sold lot that has none (a sale made before invoices existed, or a cancelled one). */
    public function createForLot(Request $request, int $lot)
    {
        $lot = AuctionLot::visibleTo($request->user())->findOrFail($lot);
        if ($lot->status !== AuctionLot::STATUS_SOLD) {
            throw ValidationException::withMessages(['invoice' => 'Only a sold lot can be invoiced.']);
        }
        $invoice = $this->service->createDraftForSoldLot($lot);

        return redirect()->route('corex.auctions.invoices.show', $invoice->id)->with('status', 'Invoice drafted. Check it, then issue it.');
    }

    public function issue(Request $request, int $invoice)
    {
        $this->service->issue($this->find($request, $invoice), $request->user());

        return back()->with('status', 'Invoice issued and emailed to the buyer.');
    }

    public function resend(Request $request, int $invoice)
    {
        $invoice = $this->find($request, $invoice);
        abort_unless(in_array($invoice->status, AuctionInvoice::BUYER_VISIBLE, true), 422, 'Only an issued invoice can be sent.');

        return back()->with('status', $this->service->email($invoice) ? 'Invoice emailed again.' : 'The invoice could not be emailed (the buyer has no usable email address).');
    }

    public function notes(Request $request, int $invoice)
    {
        $invoice = $this->find($request, $invoice);
        $data = $request->validate(['notes' => 'nullable|string|max:2000', 'payment_details' => 'nullable|string|max:2000']);
        abort_unless(in_array($invoice->status, [AuctionInvoice::STATUS_DRAFT], true), 422, 'Only a draft invoice can be edited.');
        $invoice->forceFill(['notes' => $data['notes'] ?? null, 'payment_details' => $data['payment_details'] ?? null])->save();

        return back()->with('status', 'Draft updated.');
    }

    public function recordPayment(Request $request, int $invoice)
    {
        $invoice = $this->find($request, $invoice);
        $data = $request->validate([
            'kind' => 'required|in:deposit,balance,other',
            'amount' => 'required|numeric|min:0.01|max:999999999',
            'received_at' => 'required|date|before_or_equal:today',
            'reference' => 'nullable|string|max:120',
        ]);

        $this->service->recordPayment($invoice, $data['kind'], (float) $data['amount'], $data['received_at'], $data['reference'] ?? null, $request->user());

        return back()->with('status', 'Payment recorded.');
    }

    public function voidPayment(Request $request, int $invoice, int $payment)
    {
        $invoice = $this->find($request, $invoice);
        $row = $invoice->payments()->whereKey($payment)->firstOrFail();
        $data = $request->validate(['reason' => 'required|string|max:300']);

        $this->service->voidPayment($invoice, $row, $data['reason']);

        return back()->with('status', 'Payment voided.');
    }

    public function guarantee(Request $request, int $invoice)
    {
        $invoice = $this->find($request, $invoice);
        $request->validate(['note' => 'nullable|string|max:300']);
        $this->service->setGuarantee($invoice, $request->boolean('received'), $request->input('note'));

        return back()->with('status', $request->boolean('received') ? 'Bank guarantee marked as received.' : 'Bank guarantee marked as not received.');
    }

    public function cancel(Request $request, int $invoice)
    {
        $data = $request->validate(['reason' => 'required|string|max:300']);
        $this->service->cancel($this->find($request, $invoice), $data['reason']);

        return back()->with('status', 'Invoice cancelled.');
    }

    public function restore(Request $request, int $invoice)
    {
        $this->service->restore($this->find($request, $invoice));

        return back()->with('status', 'Invoice restored as a draft.');
    }

    private function find(Request $request, int $id): AuctionInvoice
    {
        return AuctionInvoice::visibleTo($request->user())->findOrFail($id);
    }
}
