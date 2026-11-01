<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\Auction;
use App\Models\ClientUser;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use App\Models\AuctionRegistration;
use App\Models\AuctionRegistrationDocument;
use App\Services\Auctions\AuctionRegistrationService;
use Illuminate\Http\Request;

/**
 * Screen S5 — Bidders (.ai/specs/auctions.md §5): every registration across all auctions, with the Registered / Approved /
 * Winning / Under-bidders views, document review, and approve / decline / withdraw / restore.
 */
class BidderController extends Controller
{
    private const SORTABLE = ['created_at', 'bidder_number', 'status', 'auction'];

    private const VIEWS = ['all' => 'Registered', 'pending' => 'Awaiting approval', 'approved' => 'Approved', 'winning' => 'Winning', 'underbidders' => 'Under-bidders'];

    public function __construct(private AuctionRegistrationService $service) {}

    public function index(Request $request)
    {
        $user = $request->user();

        $view = array_key_exists($request->query('view'), self::VIEWS) ? $request->query('view') : 'all';
        $search = trim((string) $request->query('search', ''));
        $auctionId = trim((string) $request->query('auction_id', ''));
        $status = trim((string) $request->query('status', ''));
        $type = trim((string) $request->query('bidder_type', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $archived = $request->boolean('archived');
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'created_at';
        $dir = $request->query('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = AuctionRegistration::visibleTo($user)->with(['auction', 'contact', 'clientUser'])->withCount(['bids as bids_count' => fn ($q) => $q->valid()]);
        if ($archived) {
            $query->onlyTrashed();
        }

        match ($view) {
            'pending' => $query->where('status', AuctionRegistration::STATUS_PENDING),
            'approved' => $query->where('status', AuctionRegistration::STATUS_APPROVED),
            'winning' => $query->whereRaw($this->leadingSql('='), []),
            'underbidders' => $query->whereRaw($this->leadingSql('<'), []),
            default => null,
        };

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('company_name', 'like', "%{$search}%")
                    ->orWhere('bidder_number', ctype_digit($search) ? (int) $search : -1)
                    ->orWhereHas('clientUser', fn ($c) => $c->where('email', 'like', "%{$search}%"))
                    ->orWhereHas('contact', fn ($c) => $c->withoutGlobalScopes()->where(fn ($w) => $w
                        ->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")->orWhere('id_number', 'like', "%{$search}%")));
            });
        }
        if ($auctionId !== '' && ctype_digit($auctionId)) {
            $query->where('auction_id', (int) $auctionId);
        }
        if (in_array($status, AuctionRegistration::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (array_key_exists($type, AuctionRegistration::TYPES)) {
            $query->where('bidder_type', $type);
        }
        if ($dateFrom !== '') {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        if ($sort === 'auction') {
            $query->orderBy(Auction::withTrashed()->select('title')->whereColumn('auctions.id', 'auction_registrations.auction_id')->limit(1), $dir);
        } else {
            $query->orderBy($sort, $dir);
        }

        return view('corex.auctions.bidders.index', [
            'registrations' => $query->orderByDesc('id')->paginate(25)->withQueryString(),
            'filters' => compact('view', 'search', 'auctionId', 'status', 'type', 'dateFrom', 'dateTo', 'archived', 'sort', 'dir'),
            'views' => self::VIEWS,
            'canSeeSignedUp' => $user->hasPermission('auctions.bidders.view_all'),
            'auctions' => Auction::visibleTo($user)->orderByDesc('starts_at')->get(['id', 'reference', 'title']),
            'pendingCount' => AuctionRegistration::visibleTo($user)->where('status', AuctionRegistration::STATUS_PENDING)->count(),
        ]);
    }

    private const SIGNED_UP_SORTABLE = ['signed_up_at', 'contact_name', 'last_login_at', 'registered_count'];

    /**
     * The "Signed up" tab (.ai/specs/auctions.md §19.1): every buyer who signed up on, or logged into, THIS agency's public auction site,
     * whether or not they have registered for an auction yet. Agency level - the people belong to no auction or agent.
     */
    public function signedUp(Request $request)
    {
        $user = $request->user();
        abort_unless($user->hasPermission('auctions.bidders.view_all'), 403);
        $agencyId = (int) $user->effectiveAgencyId();

        $search = trim((string) $request->query('search', ''));
        $registered = in_array($request->query('registered'), ['yes', 'no'], true) ? $request->query('registered') : '';
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $sort = in_array($request->query('sort'), self::SIGNED_UP_SORTABLE, true) ? $request->query('sort') : 'signed_up_at';
        $dir = $request->query('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $first = \App\Services\Auctions\SignedUpBuyers::firstSeen($agencyId);
        $contact = fn (string $expr) => Contact::withoutGlobalScopes()->whereNull('deleted_at')->where('agency_id', $agencyId)
            ->whereColumn('contacts.client_user_id', 'client_users.id')->orderBy('contacts.id')->limit(1)->selectRaw($expr);

        $query = ClientUser::query()->joinSub($first, 'su', 'su.client_user_id', '=', 'client_users.id')
            ->select('client_users.*', 'su.signed_up_at')
            ->selectSub($contact("TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))"), 'contact_name')
            ->selectSub($contact('phone'), 'contact_phone')
            ->selectSub($contact('contacts.id'), 'contact_id')
            ->selectSub(\App\Models\AuctionRegistration::withoutGlobalScopes()->selectRaw('count(*)')->where('agency_id', $agencyId)
                ->whereColumn('auction_registrations.client_user_id', 'client_users.id')->whereNull('deleted_at'), 'registered_count');

        if ($search !== '') {
            $query->where(function ($q) use ($search, $agencyId) {
                $q->where('client_users.email', 'like', "%{$search}%")
                    ->orWhereExists(fn ($c) => $c->from('contacts')->whereColumn('contacts.client_user_id', 'client_users.id')
                        ->where('contacts.agency_id', $agencyId)->whereNull('contacts.deleted_at')
                        ->where(fn ($w) => $w->where('first_name', 'like', "%{$search}%")->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")->orWhereRaw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) like ?", ["%{$search}%"])));
            });
        }
        if ($registered === 'yes') {
            $query->whereExists(fn ($r) => $r->from('auction_registrations')->whereColumn('auction_registrations.client_user_id', 'client_users.id')
                ->where('auction_registrations.agency_id', $agencyId)->whereNull('auction_registrations.deleted_at'));
        } elseif ($registered === 'no') {
            $query->whereNotExists(fn ($r) => $r->from('auction_registrations')->whereColumn('auction_registrations.client_user_id', 'client_users.id')
                ->where('auction_registrations.agency_id', $agencyId)->whereNull('auction_registrations.deleted_at'));
        }
        if ($dateFrom !== '') {
            $query->whereDate('su.signed_up_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('su.signed_up_at', '<=', $dateTo);
        }

        $query->orderBy($sort === 'signed_up_at' ? 'su.signed_up_at' : $sort, $dir);

        return view('corex.auctions.bidders.signed-up', [
            'people' => $query->orderByDesc('client_users.id')->paginate(25)->withQueryString(),
            'filters' => compact('search', 'registered', 'dateFrom', 'dateTo', 'sort', 'dir'),
            'views' => self::VIEWS,
            'canSeeSignedUp' => true,
        ]);
    }

    public function show(Request $request, int $registration)
    {
        $reg = $this->find($request, $registration, true);
        $reg->load(['auction', 'contact', 'clientUser', 'documents.document', 'approver']);

        return view('corex.auctions.bidders.show', [
            'reg' => $reg,
            'missing' => $this->service->missingKinds($reg),
            'bids' => $reg->bids()->with('lot')->orderByDesc('id')->limit(100)->get(),
            'kinds' => \App\Models\AgencyAuctionSettings::DOCUMENT_KINDS,
        ]);
    }

    public function approve(Request $request, int $registration)
    {
        $this->service->approve($this->find($request, $registration), $request->user());

        return back()->with('status', 'Bidder approved. They have been emailed their bidder number.');
    }

    public function decline(Request $request, int $registration)
    {
        $data = $request->validate(['reason' => 'required|string|max:300']);
        $this->service->decline($this->find($request, $registration), $request->user(), $data['reason']);

        return back()->with('status', 'Registration declined. The buyer has been emailed the reason.');
    }

    public function reviewDocument(Request $request, int $registration, int $document)
    {
        $reg = $this->find($request, $registration);
        $doc = $reg->documents()->whereKey($document)->firstOrFail();
        $data = $request->validate(['decision' => 'required|in:accept,reject', 'reason' => 'nullable|string|max:300']);

        $this->service->reviewDocument($doc, $request->user(), $data['decision'] === 'accept', $data['reason'] ?? null);

        return back()->with('status', 'Document ' . ($data['decision'] === 'accept' ? 'accepted.' : 'rejected.'));
    }

    public function acceptAll(Request $request, int $registration)
    {
        $reg = $this->find($request, $registration);
        $reg->documents()->where('status', AuctionRegistrationDocument::STATUS_SUBMITTED)->get()
            ->each(fn ($d) => $this->service->reviewDocument($d, $request->user(), true));

        return back()->with('status', 'All submitted documents accepted.');
    }

    public function downloadDocument(Request $request, int $registration, int $document)
    {
        $reg = $this->find($request, $registration, true);
        $doc = $reg->documents()->whereKey($document)->firstOrFail();

        return $this->service->view($doc->document);
    }

    public function withdraw(Request $request, int $registration)
    {
        $reg = $this->find($request, $registration);
        if ($reg->bids()->valid()->exists()) {
            return back()->withErrors(['registration' => 'This bidder has live bids. Retract them first.']);
        }
        $reg->forceFill(['status' => AuctionRegistration::STATUS_WITHDRAWN])->save();
        $reg->delete();

        return redirect()->route('corex.auctions.bidders.index')->with('status', 'Registration withdrawn and archived.');
    }

    public function restore(Request $request, int $registration)
    {
        $reg = $this->find($request, $registration, true);
        $reg->restore();
        $reg->forceFill(['status' => AuctionRegistration::STATUS_PENDING, 'bidder_number' => null, 'approved_by' => null, 'approved_at' => null])->save();

        return redirect()->route('corex.auctions.bidders.show', $reg->id)->with('status', 'Registration restored. It is awaiting approval again.');
    }

    private function find(Request $request, int $id, bool $withTrashed = false): AuctionRegistration
    {
        $q = AuctionRegistration::visibleTo($request->user());
        if ($withTrashed) {
            $q->withTrashed();
        }

        return $q->findOrFail($id);
    }

    /** EXISTS(a valid bid of this registration whose amount is $op the lot's highest valid bid). '=' = leading, '<' = outbid. */
    private function leadingSql(string $op): string
    {
        return "EXISTS (SELECT 1 FROM auction_bids b WHERE b.registration_id = auction_registrations.id AND b.status = 'valid' AND b.deleted_at IS NULL "
            . "AND b.amount {$op} (SELECT MAX(b2.amount) FROM auction_bids b2 WHERE b2.lot_id = b.lot_id AND b2.status = 'valid' AND b2.deleted_at IS NULL))";
    }
}
