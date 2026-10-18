<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Address\AddressStructurer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * "Could not read this address" — the admin-only review list of the structured-address-matching build
 * (.ai/specs/structured-address-matching.md §10).
 *
 * One row per property / tracked property whose address CoreX could not read confidently
 * (`address_parse_status` review | unparseable; `dismissed` and `manual` rows are shown on request).
 * Agency-admin scope ONLY: the route needs `address_review.manage` (admin / super_admin by default) AND every
 * query and every by-id lookup is filtered to the viewer's own agency — a direct URL by id outside it is a 404.
 *
 * Search: street name, street number, suburb, complex, erf, record id.
 * Sort: record kind, address, suburb, status, last updated (default: newest-updated first, then id).
 * Filter: status, reason, record kind, updated-between dates. Pagination: 25.
 * Actions: open the record · Fix (edit the address; re-read; marked `manual`) · Dismiss / Restore.
 * No create (the rows derive from data) and NO delete anywhere — nothing here removes a record.
 */
class AddressReviewController extends Controller
{
    /** reason filter => the phrase the parser / structurer puts in the note */
    public const REASONS = [
        'suburb'  => 'not a Property24 suburb',
        'number'  => 'street number',
        'streets' => 'more than one street',
        'several' => 'different addresses',
        'erf'     => 'LPI code',
        'nothing' => 'No street, scheme or erf',
    ];

    public const REASON_LABELS = [
        'suburb'  => 'Suburb not recognised',
        'number'  => 'Street number found in two places that disagree',
        'streets' => 'More than one street in the address',
        'several' => 'Record may be several properties merged',
        'erf'     => 'Erf and LPI code disagree',
        'nothing' => 'No street, scheme or erf',
    ];

    private const SORTS = ['kind' => 'kind', 'address' => 'street_name', 'suburb' => 'suburb', 'status' => 'status', 'updated' => 'updated_at'];

    private function agencyId(Request $request): int
    {
        $agencyId = (int) ($request->user()->effectiveAgencyId() ?: 0);
        abort_if($agencyId === 0, 403);

        return $agencyId;
    }

    public function index(Request $request)
    {
        $agencyId = $this->agencyId($request);

        $status = (string) $request->query('status', 'open');
        $statuses = match ($status) {
            'review'      => ['review'],
            'unparseable' => ['unparseable'],
            'dismissed'   => ['dismissed'],
            'manual'      => ['manual'],
            'all'         => ['review', 'unparseable', 'dismissed', 'manual'],
            default       => ['review', 'unparseable'], // 'open' — what needs a look
        };
        $kind = in_array($request->query('kind'), ['property', 'tracked'], true) ? (string) $request->query('kind') : null;
        $reason = array_key_exists((string) $request->query('reason'), self::REASONS) ? (string) $request->query('reason') : null;
        $term = trim((string) $request->query('q', ''));
        $from = $this->date($request->query('from'));
        $to = $this->date($request->query('to'));

        $sortKey = array_key_exists((string) $request->query('sort'), self::SORTS) ? (string) $request->query('sort') : 'updated';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';

        $part = fn (string $table, string $kindName) => DB::table($table)
            ->where($table . '.agency_id', $agencyId)
            ->whereNull($table . '.deleted_at')
            ->whereIn($table . '.address_parse_status', $statuses)
            ->selectRaw(
                "'{$kindName}' as kind, {$table}.id as id, {$table}.street_number as street_number, {$table}.street_name as street_name, " .
                "{$table}.suburb as suburb, {$table}.complex_name as complex_name, {$table}.erf_number as erf_number, " .
                "{$table}.address_parse_status as status, {$table}.address_parse_note as note, {$table}.updated_at as updated_at"
            );
        $union = $part('properties', 'property')->unionAll($part('tracked_properties', 'tracked'));

        $q = DB::query()->fromSub($union, 'u');
        if ($kind !== null) {
            $q->where('u.kind', $kind);
        }
        if ($reason !== null) {
            $q->where('u.note', 'like', '%' . self::REASONS[$reason] . '%');
        }
        if ($term !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
            $q->where(function ($w) use ($like, $term) {
                $w->where('u.street_name', 'like', $like)
                    ->orWhere('u.street_number', 'like', $like)
                    ->orWhere('u.suburb', 'like', $like)
                    ->orWhere('u.complex_name', 'like', $like)
                    ->orWhere('u.erf_number', 'like', $like);
                if (ctype_digit($term)) {
                    $w->orWhere('u.id', (int) $term);
                }
            });
        }
        if ($from !== null) {
            $q->where('u.updated_at', '>=', $from . ' 00:00:00');
        }
        if ($to !== null) {
            $q->where('u.updated_at', '<=', $to . ' 23:59:59');
        }

        $rows = $q->orderBy('u.' . self::SORTS[$sortKey], $dir)->orderByDesc('u.updated_at')->orderByDesc('u.id')
            ->paginate(25)->withQueryString();

        return view('corex.address-review.index', [
            'rows'         => $rows,
            'filters'      => ['status' => $status, 'kind' => $kind, 'reason' => $reason, 'q' => $term, 'from' => $from, 'to' => $to, 'sort' => $sortKey, 'dir' => $dir],
            'reasons'      => self::REASON_LABELS,
            'anyFilter'    => $kind !== null || $reason !== null || $term !== '' || $from !== null || $to !== null || ! in_array($status, ['open'], true),
            'canSettings'  => $request->user()->hasPermission('prospecting_setup.manage'),
        ]);
    }

    public function edit(Request $request, string $kind, int $id)
    {
        $model = $this->find($request, $kind, $id);

        $structure = (new AddressStructurer())->structure((new AddressStructurer())->inputFrom($model));

        return view('corex.address-review.edit', [
            'kind'      => $kind,
            'model'     => $model,
            'structure' => $structure,
            'recordUrl' => $kind === 'property' ? route('corex.properties.show', $model->id) : route('corex.tracked-properties.show', $model->id),
        ]);
    }

    public function fix(Request $request, string $kind, int $id)
    {
        $model = $this->find($request, $kind, $id);

        $v = $request->validate([
            'street_number' => ['nullable', 'string', 'max:50'],
            'street_name'   => ['nullable', 'string', 'max:200'],
            'unit_number'   => ['nullable', 'string', 'max:50'],
            'complex_name'  => ['nullable', 'string', 'max:150'],
            'suburb'        => ['nullable', 'string', 'max:100'],
            'erf_number'    => ['nullable', 'string', 'max:100'],
        ]);
        $values = array_map(fn ($x) => ($x === null || trim((string) $x) === '') ? null : trim((string) $x), $v);

        try {
            // A real edit: the model's own save re-reads the address (derived columns refreshed, audit trail kept) …
            $model->fill($values)->save();
            // … then the admin's decision is recorded. saveQuietly: no second re-read overwriting "manual".
            $model->forceFill(['address_parse_status' => 'manual', 'address_parse_note' => null])->saveQuietly();
        } catch (\Throwable $e) {
            return back()->withInput()->with('error', 'Could not save this address: ' . $e->getMessage());
        }

        return redirect()->route('corex.address-review.index')->with('status', 'Address fixed and marked as checked.');
    }

    public function dismiss(Request $request, string $kind, int $id)
    {
        $model = $this->find($request, $kind, $id);
        DB::table($model->getTable())->where('id', $model->getKey())->where('agency_id', $this->agencyId($request))
            ->update(['address_parse_status' => 'dismissed']);

        return redirect()->route('corex.address-review.index')->with('status', 'Dismissed — it stays on file and can be restored from the Dismissed filter.');
    }

    public function restore(Request $request, string $kind, int $id)
    {
        $model = $this->find($request, $kind, $id);
        DB::table($model->getTable())->where('id', $model->getKey())->where('agency_id', $this->agencyId($request))
            ->update(['address_parse_status' => 'review']);

        return redirect()->route('corex.address-review.index', ['status' => 'dismissed'])->with('status', 'Put back on the list to review.');
    }

    /** The record, only inside the viewer's own agency (a direct URL by id elsewhere is a 404). */
    private function find(Request $request, string $kind, int $id): Property|TrackedProperty
    {
        $agencyId = $this->agencyId($request);

        return $kind === 'property'
            ? Property::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->findOrFail($id)
            : TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $agencyId)->whereNull('deleted_at')->findOrFail($id);
    }

    private function date(mixed $v): ?string
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            return null;
        }

        return checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4)) ? $v : null;
    }
}
