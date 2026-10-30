<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\PropertyMatchDecision;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Prospecting\PropertyIdentityResolver;
use App\Services\Prospecting\PropertyMatchDecisionService;
use App\Services\Prospecting\TrackedPropertyMerger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Possible matches — the review list of .ai/specs/property-identity.md §9.
 *
 * One line per pair CoreX could not be sure about: one of our own listings and a captured property that might be the
 * same house, or two captured properties that carry the same portal advert. One click each way: "Same property" or
 * "Not the same". Rows are the pending `identity_possible` decisions of the viewer's own agency.
 *
 * Scope: permission `prospecting_setup.manage` on the route AND every query and every by-id lookup is filtered to the
 * viewer's agency — a direct URL by id outside it is a 404.
 *
 * Search: street, street number, suburb, complex, erf, advert number, record number (either side of the pair).
 * Sort: confidence, source, address, decided date (default: highest confidence first, then newest).
 * Filter: outcome, kind of pair, source (how it was found), decided-between dates. Pagination: 25.
 * Actions: Same property (links / merges, recorded) · Not the same (remembered as a veto) · Restore (undo a "not the same").
 * No create (rows derive from the matcher) and no delete anywhere — a "same property" merge flags the loser `duplicate`.
 */
class PossibleMatchesController extends Controller
{
    private const SORTS = ['confidence' => 'd.confidence_score', 'source' => 'd.strategy', 'address' => 'r.street_name', 'decided' => 'd.decided_at'];

    public const KINDS = ['own_stock' => 'Our listing ↔ captured property', 'advert_clash' => 'Same advert on two captured properties', 'capture' => 'New capture ↔ existing property'];

    private function agencyId(Request $request): int
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        abort_if($agencyId === 0, 403);

        return $agencyId;
    }

    public function index(Request $request)
    {
        $agencyId = $this->agencyId($request);
        $status = in_array($request->query('status'), ['pending', 'confirmed', 'rejected', 'all'], true) ? (string) $request->query('status') : 'pending';
        $kind = array_key_exists((string) $request->query('kind'), self::KINDS) ? (string) $request->query('kind') : null;
        $source = trim((string) $request->query('source', ''));
        $term = trim((string) $request->query('q', ''));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));
        $sortKey = array_key_exists((string) $request->query('sort'), self::SORTS) ? (string) $request->query('sort') : 'confidence';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $q = $this->base($agencyId);
        match ($status) {
            'pending'   => $q->whereNull('d.confirmed_at')->whereNull('d.rejected_at'),
            'confirmed' => $q->whereNotNull('d.confirmed_at'),
            'rejected'  => $q->whereNotNull('d.rejected_at'),
            default     => null,
        };
        if ($kind === 'own_stock') {
            $q->where('d.subject_key', 'like', 'property:%');
        } elseif ($kind === 'advert_clash') {
            $q->where('d.subject_key', 'not like', 'property:%')->where('d.incoming_facts', 'like', '%advert_clash%');
        } elseif ($kind === 'capture') {
            $q->where('d.subject_key', 'not like', 'property:%')->where(fn ($w) => $w->whereNull('d.incoming_facts')->orWhere('d.incoming_facts', 'not like', '%advert_clash%'));
        }
        if ($source !== '') {
            $q->where('d.strategy', $source);
        }
        if ($term !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $q->where(function ($w) use ($like, $term) {
                foreach (['r', 'l'] as $a) {
                    $w->orWhere("{$a}.street_name", 'like', $like)->orWhere("{$a}.street_number", 'like', $like)->orWhere("{$a}.suburb", 'like', $like)
                        ->orWhere("{$a}.complex_name", 'like', $like)->orWhere("{$a}.erf_number", 'like', $like);
                }
                $w->orWhere('lp.street_name', 'like', $like)->orWhere('lp.street_number', 'like', $like)->orWhere('lp.suburb', 'like', $like)
                    ->orWhere('lp.complex_name', 'like', $like)->orWhere('lp.erf_number', 'like', $like)->orWhere('lp.address', 'like', $like)
                    ->orWhere('d.subject_key', 'like', $like);
                if (ctype_digit($term)) {
                    $w->orWhere('d.matched_id', (int) $term)->orWhere('l.id', (int) $term)->orWhere('lp.id', (int) $term);
                }
            });
        }
        if ($from !== null) {
            $q->where('d.decided_at', '>=', $from . ' 00:00:00');
        }
        if ($to !== null) {
            $q->where('d.decided_at', '<=', $to . ' 23:59:59');
        }

        $rows = $q->orderBy(self::SORTS[$sortKey], $dir)->orderByDesc('d.decided_at')->orderByDesc('d.id')->paginate(25)->withQueryString();

        $sources = DB::table('property_match_decisions')->where('agency_id', $agencyId)->where('subject_type', PropertyIdentityResolver::DECISION_SUBJECT)
            ->whereNull('deleted_at')->distinct()->orderBy('strategy')->pluck('strategy');
        $counts = DB::table('property_match_decisions')->where('agency_id', $agencyId)->where('subject_type', PropertyIdentityResolver::DECISION_SUBJECT)->whereNull('deleted_at')
            ->selectRaw('SUM(confirmed_at IS NULL AND rejected_at IS NULL) as pending, SUM(confirmed_at IS NOT NULL) as confirmed, SUM(rejected_at IS NOT NULL) as rejected')->first();

        return view('corex.possible-matches.index', [
            'rows'      => $rows,
            'kinds'     => self::KINDS,
            'sources'   => $sources,
            'counts'    => $counts,
            'filters'   => ['q' => $term, 'status' => $status, 'kind' => $kind, 'source' => $source, 'from' => $from, 'to' => $to, 'sort' => $sortKey, 'dir' => $dir],
            'anyFilter' => $term !== '' || $kind !== null || $source !== '' || $from !== null || $to !== null || $status !== 'pending',
            'labels'    => fn (object $r) => $this->pairLabels($r),
        ]);
    }

    /** "Same property": link the listing / merge the two files. One click. */
    public function confirm(Request $request, int $id, PropertyMatchDecisionService $decisions, TrackedPropertyMerger $merger): RedirectResponse
    {
        $agencyId = $this->agencyId($request);
        $d = $this->decision($agencyId, $id);
        if ($d->confirmed_at || $d->rejected_at) {
            return back()->with('error', 'This pair has already been decided.');
        }
        $target = TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $agencyId)->find($d->matched_id);
        if ($target === null || $target->status === TrackedProperty::STATUS_DUPLICATE) {
            return back()->with('error', 'The captured property in this pair has already been merged into another one. Press "Not the same" to clear the line, or refresh the list.');
        }

        $propertyId = $this->ownPropertyId($d);
        $message = null;
        try {
        DB::transaction(function () use ($d, $agencyId, $propertyId, $target, $merger, $decisions, $request, &$message) {
            if ($propertyId !== null) {
                $updated = DB::table('properties')->where('id', $propertyId)->where('agency_id', $agencyId)->update([
                    'tracked_property_id' => $target->id, 'identity_confidence' => 'confirmed', 'identity_method' => 'agent_confirmed',
                ]);
                $message = $updated ? 'Linked: our listing now sits on that captured property.' : 'That listing no longer exists.';
                // the other candidates for the same listing are moot — closed, not vetoed
                DB::table('property_match_decisions')->where('agency_id', $agencyId)->where('subject_type', PropertyIdentityResolver::DECISION_SUBJECT)
                    ->where('subject_key', $d->subject_key)->where('id', '<>', $d->id)->whereNull('confirmed_at')->whereNull('rejected_at')
                    ->update(['outcome' => 'superseded', 'updated_at' => now()]);
            } else {
                $other = TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $agencyId)->find($this->incomingFileId($d));
                if ($other === null || $other->status === TrackedProperty::STATUS_DUPLICATE) {
                    $message = 'The other captured property has already been merged; nothing left to join.';
                } else {
                    [$win, $lose] = $merger->chooseWinner($target, $other);
                    $res = $merger->merge($win, $lose, true, 'possible-matches-confirm', (int) $request->user()->id);
                    $message = $res['merged'] ? 'Merged: the two captured properties are now one.' : 'Not merged — ' . $res['reason'] . '. The pair stays on the list.';
                    if (! $res['merged']) {
                        throw new \RuntimeException($message);
                    }
                }
            }
            $decisions->confirm($d, (int) $request->user()->id);
            $decisions->recordOutcome($d->refresh(), 'confirmed');
        });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', $message);
    }

    /** "Not the same": remembered as a veto, never offered again. */
    public function reject(Request $request, int $id, PropertyMatchDecisionService $decisions): RedirectResponse
    {
        $agencyId = $this->agencyId($request);
        $d = $this->decision($agencyId, $id);
        if ($d->confirmed_at || $d->rejected_at) {
            return back()->with('error', 'This pair has already been decided.');
        }
        $decisions->reject($d, (int) $request->user()->id, 'Not the same property (possible-matches review)', null, null, 'other');
        $decisions->recordOutcome($d->refresh(), 'rejected');

        return back()->with('status', 'Noted: these are different properties and will not be offered again.');
    }

    /** Undo a "not the same", or unlink a confirmed own listing. A confirmed merge cannot be undone from here. */
    public function restore(Request $request, int $id): RedirectResponse
    {
        $agencyId = $this->agencyId($request);
        $d = $this->decision($agencyId, $id);

        if ($d->rejected_at) {
            $d->update(['rejected_at' => null, 'rejected_by_user_id' => null, 'rejected_reason' => null, 'reject_reason_code' => null, 'outcome' => 'pending']);

            return back()->with('status', 'Back on the list.');
        }
        if ($d->confirmed_at && ($propertyId = $this->ownPropertyId($d)) !== null) {
            DB::table('properties')->where('id', $propertyId)->where('agency_id', $agencyId)->where('tracked_property_id', $d->matched_id)
                ->update(['tracked_property_id' => null, 'identity_confidence' => null, 'identity_method' => null]);
            $d->update(['confirmed_at' => null, 'confirmed_by_user_id' => null, 'outcome' => 'pending']);

            return back()->with('status', 'Unlinked: back on the list.');
        }

        return back()->with('error', 'A merge of two captured properties cannot be undone from this list.');
    }

    // ---------------------------------------------------------------------------------------------------------------

    private function base(int $agencyId)
    {
        return DB::table('property_match_decisions as d')
            ->where('d.agency_id', $agencyId)->where('d.subject_type', PropertyIdentityResolver::DECISION_SUBJECT)->whereNull('d.deleted_at')
            ->leftJoin('tracked_properties as r', fn ($j) => $j->on('r.id', '=', 'd.matched_id')->where('r.agency_id', '=', $agencyId))
            ->leftJoin('tracked_properties as l', fn ($j) => $j->whereRaw("l.id = CAST(JSON_UNQUOTE(JSON_EXTRACT(d.incoming_facts, '$.incoming_tracked_property_id')) AS UNSIGNED)")->where('l.agency_id', '=', $agencyId))
            ->leftJoin('properties as lp', fn ($j) => $j->whereRaw("d.subject_key LIKE 'property:%' AND lp.id = CAST(SUBSTRING(d.subject_key, 10) AS UNSIGNED)")->where('lp.agency_id', '=', $agencyId))
            ->select([
                'd.id', 'd.subject_key', 'd.matched_id', 'd.strategy', 'd.confidence_score', 'd.reason', 'd.decided_at', 'd.confirmed_at', 'd.rejected_at', 'd.incoming_facts',
                'r.street_number as r_number', 'r.street_name as r_street', 'r.unit_number as r_unit', 'r.complex_name as r_complex', 'r.suburb as r_suburb', 'r.erf_number as r_erf', 'r.status as r_status',
                'l.id as l_id', 'l.street_number as l_number', 'l.street_name as l_street', 'l.unit_number as l_unit', 'l.complex_name as l_complex', 'l.suburb as l_suburb', 'l.erf_number as l_erf',
                'lp.id as lp_id', 'lp.address as lp_address', 'lp.suburb as lp_suburb', 'lp.status as lp_status',
            ]);
    }

    /** @return array{kind:string, left:string, left_url:?string, right:string, right_url:string, confidence:string} */
    private function pairLabels(object $r): array
    {
        $line = fn (?string $unit, ?string $num, ?string $street, ?string $complex, ?string $suburb, ?string $erf) => trim(implode(', ', array_filter([
            $unit ? 'Unit ' . $unit : null, trim(trim((string) $num) . ' ' . trim((string) $street)) ?: $complex, $suburb,
        ]))) ?: ($erf ? 'Erf ' . $erf : '(no address held)');

        $isOwn = str_starts_with((string) $r->subject_key, 'property:');
        $facts = json_decode((string) $r->incoming_facts, true) ?: [];
        $kind = $isOwn ? 'own_stock' : (($facts['kind'] ?? null) === 'advert_clash' ? 'advert_clash' : 'capture');

        if ($isOwn) {
            $left = $r->lp_id ? ($r->lp_address ?: '(no address)') . ($r->lp_suburb && ! str_contains((string) $r->lp_address, (string) $r->lp_suburb) ? ', ' . $r->lp_suburb : '') : 'Listing #' . substr((string) $r->subject_key, 9) . ' (gone)';
            $leftUrl = $r->lp_id ? route('corex.properties.show', $r->lp_id) : null;
            $leftTag = 'Our listing #' . substr((string) $r->subject_key, 9);
        } else {
            $left = $r->l_id ? $line($r->l_unit, $r->l_number, $r->l_street, $r->l_complex, $r->l_suburb, $r->l_erf) : (string) $r->subject_key;
            $leftUrl = $r->l_id ? route('corex.tracked-properties.show', $r->l_id) : null;
            $leftTag = $r->l_id ? 'Captured #' . $r->l_id : 'Advert';
        }
        $conf = '';
        if (preg_match('/^(\w+) match/i', (string) $r->reason, $m)) {
            $conf = ucfirst(strtolower($m[1]));
        }

        return [
            'kind' => $kind, 'left_tag' => $leftTag, 'left' => $left, 'left_url' => $leftUrl,
            'right_tag' => 'Captured #' . $r->matched_id, 'right' => $line($r->r_unit, $r->r_number, $r->r_street, $r->r_complex, $r->r_suburb, $r->r_erf),
            'right_url' => route('corex.tracked-properties.show', $r->matched_id), 'confidence' => $conf !== '' ? $conf : '—',
        ];
    }

    private function decision(int $agencyId, int $id): PropertyMatchDecision
    {
        return PropertyMatchDecision::queryWithoutAgencyScope()->where('agency_id', $agencyId)
            ->where('subject_type', PropertyIdentityResolver::DECISION_SUBJECT)->findOrFail($id);
    }

    private function ownPropertyId(PropertyMatchDecision $d): ?int
    {
        return str_starts_with($d->subject_key, 'property:') ? (int) substr($d->subject_key, 9) : null;
    }

    private function incomingFileId(PropertyMatchDecision $d): int
    {
        return (int) (($d->incoming_facts['incoming_tracked_property_id'] ?? 0));
    }

    private function date(mixed $v): ?string
    {
        $v = is_string($v) ? trim($v) : '';

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
