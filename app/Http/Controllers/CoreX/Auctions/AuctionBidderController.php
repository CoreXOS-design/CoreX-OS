<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionBidder;
use App\Models\Contact;
use App\Services\Auctions\BidderGateService;
use App\Services\Auctions\PaddleNumberService;
use Illuminate\Http\Request;

/**
 * AT-432 Phase 2 — .ai/specs/auctions.md §7 screens 5-6, §8.3, §10.2. The
 * Bidder Register and the staff-side registration/approval workflow. The
 * PUBLIC self-service registration page (§10.1 — tokenised link, e-sign
 * ceremony) is NOT built here; this covers "at the door" / staff-entered
 * registration only. Every paddle-gate decision defers to
 * BidderGateService — never re-implemented inline here.
 */
class AuctionBidderController extends Controller
{
    /** §8.3 — search, sort, filter, pagination, empty state. Register is always OF an auction. */
    public function index(Request $request, Auction $auction)
    {
        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $ficaStatus = trim((string) $request->query('fica_status', ''));
        $depositReceived = $request->query('deposit_received', '');
        $rulesSigned = $request->query('rules_signed', '');
        $sort = $request->query('sort', 'paddle_number');
        $dir = $request->query('dir', 'asc');

        $query = AuctionBidder::where('auction_id', $auction->id)->with('contact', 'entityContact');

        if ($search !== '') {
            // Reuses Contact::scopeSearch() (name/id/phone/email, punctuation-
            // aware) rather than re-inventing a second, narrower matcher.
            $query->where(function ($q) use ($search) {
                $q->whereHas('contact', fn ($c) => $c->search($search))
                    ->orWhere('paddle_number', 'like', "%{$search}%");
            });
        }
        if ($status !== '') $query->where('status', $status);
        if ($ficaStatus !== '') $query->where('fica_status', $ficaStatus);
        if ($depositReceived === '1') $query->whereNotNull('deposit_received_at');
        if ($depositReceived === '0') $query->whereNull('deposit_received_at');
        if ($rulesSigned === '1') $query->whereNotNull('rules_signed_at');
        if ($rulesSigned === '0') $query->whereNull('rules_signed_at');

        $sortable = ['paddle_number', 'status', 'created_at'];
        if (! in_array($sort, $sortable, true)) $sort = 'paddle_number';
        $query->orderBy($sort, $dir === 'desc' ? 'desc' : 'asc');

        $bidders = $query->paginate(50)->withQueryString();

        // §8.3 — bidder PII is branch-restricted unless the viewer holds
        // .view_all. The auction itself has no single "branch" a bidder
        // belongs to (they're scoped by the AUCTION's branch), so the check
        // is against the auction's own branch, not each bidder individually.
        $canSeeContactDetails = auth()->user()->hasPermission('auctions.bidders.view_all')
            || $auction->branch_id === null
            || $auction->branch_id === auth()->user()->effectiveBranchId();

        return view('corex.auctions.bidders.index', [
            'auction' => $auction,
            'bidders' => $bidders,
            'filters' => compact('search', 'status', 'ficaStatus', 'depositReceived', 'rulesSigned', 'sort', 'dir'),
            'canSeeContactDetails' => $canSeeContactDetails,
            'canSeeFica' => auth()->user()->hasPermission('auctions.bidders.verify_fica'),
        ]);
    }

    public function create(Auction $auction)
    {
        return view('corex.auctions.bidders.create', ['auction' => $auction]);
    }

    /**
     * "At the door" / staff registration (§10.1's staff-entry path — the
     * public tokenised link is a separate, not-yet-built path). Matches an
     * existing contact by id when given one; creates a new Contact only
     * when the staff member explicitly chose to (new_contact=1) — never a
     * silent duplicate.
     */
    public function store(Request $request, Auction $auction)
    {
        $data = $request->validate([
            'contact_id' => 'nullable|integer|exists:contacts,id',
            'new_contact' => 'boolean',
            'new_contact_first_name' => 'required_if:new_contact,1|nullable|string|max:255',
            'new_contact_last_name' => 'nullable|string|max:255',
            'new_contact_email' => 'nullable|email|max:255',
            'new_contact_phone' => 'nullable|string|max:30',
            'bidding_for' => 'required|in:self,entity,agent_for_third_party',
            'entity_contact_id' => 'nullable|integer|exists:contacts,id',
        ]);

        if ($request->boolean('new_contact')) {
            $contact = Contact::create([
                'agency_id' => $auction->agency_id,
                'first_name' => $data['new_contact_first_name'],
                'last_name' => $data['new_contact_last_name'] ?? null,
                'email' => $data['new_contact_email'] ?? null,
                'phone' => $data['new_contact_phone'] ?? null,
            ]);
        } else {
            if (empty($data['contact_id'])) {
                return back()->withErrors(['contact_id' => 'Pick an existing contact or tick "new contact".'])->withInput();
            }
            $contact = Contact::where('id', $data['contact_id'])->where('agency_id', $auction->agency_id)->firstOrFail();
        }

        $bidder = AuctionBidder::create([
            'agency_id' => $auction->agency_id,
            'auction_id' => $auction->id,
            'contact_id' => $contact->id,
            'bidding_for' => $data['bidding_for'],
            'entity_contact_id' => $data['entity_contact_id'] ?? null,
            'status' => AuctionBidder::STATUS_SUBMITTED,
            'registration_source' => 'at_door',
            'deposit_required' => AgencyAuctionSettings::registrationDepositRequiredFor((int) $auction->agency_id),
            'deposit_amount' => AgencyAuctionSettings::registrationDepositAmountFor((int) $auction->agency_id),
        ]);

        return redirect()->route('corex.auctions.bidders.show', $bidder)->with('status', 'Bidder registered — complete the gates below before issuing a paddle.');
    }

    public function show(AuctionBidder $bidder)
    {
        $gate = new BidderGateService();

        return view('corex.auctions.bidders.show', [
            'bidder' => $bidder->load('contact', 'entityContact', 'auction', 'ficaVerifiedBy', 'approvedBy'),
            'unmetGates' => $gate->unmetGates($bidder),
            'canApprove' => auth()->user()->hasPermission('auctions.bidders.approve'),
            'canVerifyFica' => auth()->user()->hasPermission('auctions.bidders.verify_fica'),
            'canRecordDeposits' => auth()->user()->hasPermission('auctions.bidders.deposits'),
            'paddleNumberMode' => AgencyAuctionSettings::paddleNumberModeFor((int) $bidder->agency_id),
        ]);
    }

    public function verifyFica(AuctionBidder $bidder)
    {
        $bidder->update(['fica_status' => 'approved', 'fica_verified_at' => now(), 'fica_verified_by_id' => auth()->id()]);

        return back()->with('status', 'FICA verified.');
    }

    public function recordDeposit(Request $request, AuctionBidder $bidder)
    {
        $data = $request->validate(['deposit_reference' => 'required|string|max:100']);
        $bidder->update(['deposit_received_at' => now(), 'deposit_reference' => $data['deposit_reference']]);

        return back()->with('status', 'Deposit recorded.');
    }

    public function refundDeposit(Request $request, AuctionBidder $bidder)
    {
        $data = $request->validate(['deposit_refund_reference' => 'required|string|max:100']);
        $bidder->update(['deposit_refunded_at' => now(), 'deposit_refund_reference' => $data['deposit_refund_reference']]);

        return back()->with('status', 'Deposit refund recorded.');
    }

    public function markRulesSigned(AuctionBidder $bidder)
    {
        // Standalone "wet-ink / marked signed by staff" path. The e-signed
        // Rules of Auction ceremony itself (§13) is not built yet — this
        // records the fact once signed by whatever means, so the paddle
        // gate can still be exercised end to end before that pipeline lands.
        $bidder->update(['rules_signed_at' => now()]);

        return back()->with('status', 'Rules of Auction marked signed.');
    }

    /** §10.2 — every gate above must already pass; issues the paddle and moves the bidder to approved. */
    public function approve(Request $request, AuctionBidder $bidder)
    {
        $gate = new BidderGateService();
        $unmet = array_filter($gate->unmetGates($bidder), fn ($g) => $g !== 'Not yet approved by staff.');

        if (! empty($unmet)) {
            return back()->withErrors(['bidder' => 'Cannot approve — outstanding: '.implode(' ', $unmet)]);
        }

        $manualPaddle = $request->input('paddle_number');
        $paddle = (new PaddleNumberService())->issueFor($bidder, $manualPaddle);

        $bidder->update([
            'status' => AuctionBidder::STATUS_APPROVED,
            'paddle_number' => $paddle,
            'approved_at' => now(),
            'approved_by_id' => auth()->id(),
        ]);

        return back()->with('status', "Approved — paddle #{$paddle} issued.");
    }

    public function decline(Request $request, AuctionBidder $bidder)
    {
        $data = $request->validate(['declined_reason' => 'nullable|string|max:2000']);
        $bidder->update(['status' => AuctionBidder::STATUS_DECLINED, 'declined_reason' => $data['declined_reason'] ?? null]);

        return back()->with('status', 'Registration declined.');
    }

    public function withdraw(AuctionBidder $bidder)
    {
        $bidder->update(['status' => AuctionBidder::STATUS_WITHDRAWN]);

        return back()->with('status', 'Registration withdrawn.');
    }
}
