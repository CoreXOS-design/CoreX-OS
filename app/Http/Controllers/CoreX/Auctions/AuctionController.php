<?php

namespace App\Http\Controllers\CoreX\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionDocument;
use App\Models\Branch;
use App\Services\Auctions\AuctionLifecycleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Screen S1 — Auctions (.ai/specs/auctions.md §5): list, create/edit, archive/restore, clone,
 * publish, cancel, complete, and the auction's documents.
 */
class AuctionController extends Controller
{
    private const SORTABLE = ['starts_at', 'reference', 'title', 'status', 'created_at'];

    public function index(Request $request)
    {
        $user = $request->user();

        $search = trim((string) $request->query('search', ''));
        $status = trim((string) $request->query('status', ''));
        $format = trim((string) $request->query('format', ''));
        $branchId = trim((string) $request->query('branch_id', ''));
        $dateFrom = trim((string) $request->query('date_from', ''));
        $dateTo = trim((string) $request->query('date_to', ''));
        $archived = $request->boolean('archived');
        $sort = in_array($request->query('sort'), self::SORTABLE, true) ? $request->query('sort') : 'starts_at';
        $dir = $request->query('dir', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = Auction::visibleTo($user)->withCount(['lots as live_lots_count' => fn ($q) => $q->live()])->with('branch');
        if ($archived) {
            $query->onlyTrashed();
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('title', 'like', "%{$search}%")
                    ->orWhere('venue_name', 'like', "%{$search}%");
            });
        }
        if (in_array($status, Auction::STATUSES, true)) {
            $query->where('status', $status);
        }
        if (array_key_exists($format, Auction::FORMATS)) {
            $query->where('format', $format);
        }
        if ($branchId !== '' && ctype_digit($branchId)) {
            $query->where('branch_id', (int) $branchId);
        }
        if ($dateFrom !== '') {
            $query->whereDate('starts_at', '>=', $dateFrom);
        }
        if ($dateTo !== '') {
            $query->whereDate('starts_at', '<=', $dateTo);
        }

        $auctions = $query->orderBy($sort, $dir)->orderByDesc('id')->paginate(25)->withQueryString();

        return view('corex.auctions.index', [
            'auctions' => $auctions,
            'filters' => compact('search', 'status', 'format', 'branchId', 'dateFrom', 'dateTo', 'archived', 'sort', 'dir'),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request)
    {
        $agencyId = (int) $request->user()->effectiveAgencyId();

        return view('corex.auctions.form', [
            'auction' => new Auction(['format' => 'timed', 'type' => 'real_estate', 'currency' => 'ZAR', 'status' => Auction::STATUS_DRAFT]),
            'rungs' => AgencyAuctionSettings::defaultIncrementsFor($agencyId),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $agencyId = (int) $user->effectiveAgencyId();

        $data = $this->validated($request);
        $rungs = $this->rungs($request);

        $auction = Auction::create($data + [
            'agency_id' => $agencyId,
            'reference' => Auction::nextReference($agencyId),
            'status' => Auction::STATUS_DRAFT,
            'created_by' => $user->id,
        ]);
        $auction->syncIncrements($rungs);

        return redirect()->route('corex.auctions.edit', $auction)->with('status', 'Auction created. Add its lots, then publish it when it is ready.');
    }

    public function edit(Request $request, int $auction)
    {
        $auction = $this->find($request, $auction, withTrashed: true);
        $auction->load(['lots.property', 'increments', 'documents']);

        return view('corex.auctions.form', [
            'auction' => $auction,
            'rungs' => $auction->increments->map(fn ($i) => [(float) $i->from_amount, (float) $i->increment])->all(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, int $auction)
    {
        $auction = $this->find($request, $auction);
        abort_unless($auction->isEditable(), 422, 'A finished or cancelled auction can no longer be changed.');

        $data = $this->validated($request);
        $rungs = $this->rungs($request);

        $auction->fill($data)->save();
        $auction->syncIncrements($rungs);

        return back()->with('status', 'Auction saved.');
    }

    public function archive(Request $request, int $auction)
    {
        $auction = $this->find($request, $auction);
        if ($auction->lots()->live()->exists()) {
            return back()->withErrors(['archive' => 'Cancel the auction, or withdraw its lots, before archiving it.']);
        }
        $auction->delete();

        return redirect()->route('corex.auctions.index')->with('status', 'Auction archived.');
    }

    public function restore(Request $request, int $auction)
    {
        $auction = $this->find($request, $auction, withTrashed: true);
        $auction->restore();

        return redirect()->route('corex.auctions.edit', $auction)->with('status', 'Auction restored.');
    }

    public function publish(Request $request, int $auction, AuctionLifecycleService $lifecycle)
    {
        $lifecycle->publish($this->find($request, $auction));

        return back()->with('status', 'Auction published. It is now on your public auction page.');
    }

    public function cancel(Request $request, int $auction, AuctionLifecycleService $lifecycle)
    {
        $request->validate(['cancel_reason' => 'nullable|string|max:300']);
        $lifecycle->cancel($this->find($request, $auction), $request->input('cancel_reason'));

        return back()->with('status', 'Auction cancelled. Its lots were withdrawn and the properties released.');
    }

    public function complete(Request $request, int $auction, AuctionLifecycleService $lifecycle)
    {
        $lifecycle->complete($this->find($request, $auction));

        return back()->with('status', 'Auction completed.');
    }

    public function duplicate(Request $request, int $auction, AuctionLifecycleService $lifecycle)
    {
        $copy = $lifecycle->cloneAuction($this->find($request, $auction), $request->user()->id);

        return redirect()->route('corex.auctions.edit', $copy)->with('status', 'Auction cloned as a new draft. Set its dates and add its lots.');
    }

    public function storeDocument(Request $request, int $auction)
    {
        $auction = $this->find($request, $auction);
        $data = $request->validate([
            'kind' => 'required|in:' . implode(',', array_keys(AuctionDocument::KINDS)),
            'name' => 'nullable|string|max:200',
            'visibility' => 'nullable|in:public,registered',
            'file' => 'required|file|max:20480|mimes:pdf,doc,docx,jpg,jpeg,png',
        ]);

        $file = $request->file('file');
        $path = $file->store("auctions/{$auction->agency_id}/{$auction->id}", 'local');

        AuctionDocument::create([
            'agency_id' => $auction->agency_id,
            'auction_id' => $auction->id,
            'kind' => $data['kind'],
            'name' => trim((string) ($data['name'] ?? '')) ?: AuctionDocument::KINDS[$data['kind']],
            'path' => $path,
            'visibility' => $data['visibility'] ?? (in_array($data['kind'], AuctionDocument::PUBLIC_BY_DEFAULT, true) ? 'public' : 'registered'),
            'sort_order' => (int) $auction->documents()->max('sort_order') + 1,
            'uploaded_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Document added.');
    }

    public function destroyDocument(Request $request, int $auction, int $document)
    {
        $auction = $this->find($request, $auction);
        $auction->documents()->whereKey($document)->firstOrFail()->delete();

        return back()->with('status', 'Document removed.');
    }

    public function downloadDocument(Request $request, int $auction, int $document)
    {
        $auction = $this->find($request, $auction);
        $doc = $auction->documents()->whereKey($document)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($doc->path), 404);

        return Storage::disk('local')->download($doc->path, $doc->name . '.' . pathinfo($doc->path, PATHINFO_EXTENSION));
    }

    /** Direct-URL access is blocked at the query layer: an auction outside the user's scope is a 404, not merely unlinked. */
    private function find(Request $request, int $id, bool $withTrashed = false): Auction
    {
        $q = Auction::visibleTo($request->user());
        if ($withTrashed) {
            $q->withTrashed();
        }

        return $q->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
            'description' => 'nullable|string|max:20000',
            'format' => 'required|in:' . implode(',', array_keys(Auction::FORMATS)),
            'type' => 'required|in:' . implode(',', array_keys(Auction::TYPES)),
            'currency' => 'required|string|size:3',
            'branch_id' => 'nullable|integer',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'registration_closes_at' => 'nullable|date',
            'venue_name' => 'nullable|string|max:200',
            'venue_address' => 'nullable|string|max:300',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'stream_url' => 'nullable|url|max:500',
            'soft_close_minutes' => 'nullable|integer|min:0|max:60',
        ]);

        if (! empty($data['branch_id']) && ! Branch::query()->whereKey($data['branch_id'])->exists()) {
            throw ValidationException::withMessages(['branch_id' => 'Pick one of your branches.']);
        }
        $data['currency'] = strtoupper($data['currency']);

        return $data;
    }

    /** Bid ladder from the form: parallel from[] / increment[] arrays, blank rows ignored, ascending. */
    private function rungs(Request $request): array
    {
        $from = (array) $request->input('inc_from', []);
        $inc = (array) $request->input('inc_amount', []);
        $rungs = [];
        foreach ($from as $i => $f) {
            $a = $inc[$i] ?? null;
            if ($f === null || $f === '' || $a === null || $a === '') {
                continue;
            }
            if (! is_numeric($f) || ! is_numeric($a) || (float) $a <= 0 || (float) $f < 0) {
                throw ValidationException::withMessages(['inc_from' => 'Each bid increment needs a starting amount and an increment above zero.']);
            }
            $rungs[(string) (float) $f] = [(float) $f, (float) $a];
        }
        ksort($rungs, SORT_NUMERIC);

        return array_values($rungs);
    }
}
