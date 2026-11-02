<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsResultOption;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ResultOptionService;
use App\Services\RoadToSold\Track;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Road to Sold v4 (road-to-sold.md 23.10) - the agency's ONE result list: rename, add, switch off, change the revisit days and the reason it is set aside with,
 * archive and restore (soft delete only). Agency-wide, gated by road_to_sold.manage_settings; the list is shared by the evaluation feedback form, the
 * presentation outcome panel and the belt card. The four classes (won / undecided / lost / not selling) are fixed: they decide what the result does.
 */
class ResultSettingsController extends Controller
{
    private function agencyId(Request $request): int
    {
        $id = (int) ($request->user()->effectiveAgencyId() ?: 0);
        abort_if($id <= 0, 403, 'Switch into an agency to change its results.');

        return $id;
    }

    public function index(Request $request)
    {
        $agencyId = $this->agencyId($request);
        $track = Track::fromRequest($request);
        $svc = app(ResultOptionService::class);
        $ready = ResultOptionService::tableReady();
        $status = in_array($request->query('status'), ['active', 'off', 'archived', 'all'], true) ? $request->query('status') : 'all';
        $q = trim((string) $request->query('q', ''));
        $sort = in_array($request->query('sort'), ['order', 'name', 'class'], true) ? $request->query('sort') : 'order';
        $rows = collect();
        if ($ready) {
            $svc->seed($agencyId, $track);
            $query = RtsResultOption::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->where('track', $track);
            match ($status) {
                'active' => $query->whereNull('deleted_at')->where('is_active', true),
                'off' => $query->whereNull('deleted_at')->where('is_active', false),
                'archived' => $query->whereNotNull('deleted_at'),
                default => null,
            };
            if ($q !== '') {
                $query->where('label', 'like', '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%');
            }
            match ($sort) {
                'name' => $query->orderBy('label'),
                'class' => $query->orderBy('class')->orderBy('sort_order'),
                default => $query->orderBy('sort_order')->orderBy('id'),
            };
            $rows = $query->paginate(50)->withQueryString();
        }

        return view('road-to-sold.settings.results', [
            'rows' => $rows, 'ready' => $ready, 'track' => $track, 'status' => $status, 'q' => $q, 'sort' => $sort,
            'classes' => ['won' => 'Won - the mandate is agreed', 'pending' => 'Undecided - follow up', 'lost' => 'Lost - set aside with a reason', 'not_selling' => 'Not selling'],
            'reasons' => app(ParkReasonService::class)->choices($agencyId, $track),
        ]);
    }

    private function rules(int $agencyId, string $track, ?int $ignore = null): array
    {
        return [
            'label' => ['required', 'string', 'max:120'],
            'class' => ['required', Rule::in(RtsResultOption::CLASSES)],
            'park_reason_key' => ['nullable', 'string', 'max:40'],
            'revisit_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $agencyId = $this->agencyId($request);
        $track = Track::fromRequest($request);
        abort_unless(ResultOptionService::tableReady(), 503, 'The result list is not switched on yet.');
        app(ResultOptionService::class)->seed($agencyId, $track);
        $data = $request->validate($this->rules($agencyId, $track), ['label.required' => 'Give the result a name.']);
        $key = 'custom_' . substr(md5($data['label'] . microtime()), 0, 8);
        RtsResultOption::withoutAgencyStamping(fn () => RtsResultOption::create([
            'agency_id' => $agencyId, 'track' => $track, 'key' => $key, 'label' => trim($data['label']), 'class' => $data['class'],
            'park_reason_key' => $data['park_reason_key'] ?? null, 'needs_date' => $data['class'] !== 'won', 'revisit_days' => $data['revisit_days'] ?? null,
            'is_system' => false, 'is_active' => true, 'updated_by_user_id' => $request->user()->id,
            'sort_order' => (int) RtsResultOption::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->where('track', $track)->max('sort_order') + 10,
        ]));
        ResultOptionService::forgetMemo();

        return back()->with('status', 'Added.');
    }

    public function update(Request $request, int $option): RedirectResponse
    {
        $row = $this->find($request, $option);
        $data = $request->validate($this->rules((int) $row->agency_id, (string) $row->track));
        // a system result keeps its class (it is what the result does); its words, reason and days are the agency's to change
        $row->forceFill([
            'label' => trim($data['label']), 'class' => $row->is_system ? $row->class : $data['class'], 'park_reason_key' => $data['park_reason_key'] ?? null,
            'revisit_days' => $data['revisit_days'] ?? null, 'updated_by_user_id' => $request->user()->id,
        ])->save();
        ResultOptionService::forgetMemo();

        return back()->with('status', 'Saved.');
    }

    public function toggle(Request $request, int $option): RedirectResponse
    {
        $row = $this->find($request, $option);
        $row->forceFill(['is_active' => ! $row->is_active, 'updated_by_user_id' => $request->user()->id])->save();
        ResultOptionService::forgetMemo();

        return back()->with('status', $row->is_active ? 'Switched on.' : 'Switched off.');
    }

    public function archive(Request $request, int $option): RedirectResponse
    {
        $row = $this->find($request, $option);
        $row->delete();   // soft delete: records that used it keep its wording
        ResultOptionService::forgetMemo();

        return back()->with('status', 'Archived. Records that used it keep their wording.');
    }

    public function restore(Request $request, int $option): RedirectResponse
    {
        $row = $this->find($request, $option, true);
        $row->restore();
        ResultOptionService::forgetMemo();

        return back()->with('status', 'Restored.');
    }

    private function find(Request $request, int $id, bool $trashed = false): RtsResultOption
    {
        $q = RtsResultOption::withoutGlobalScopes()->where('agency_id', $this->agencyId($request));
        $row = ($trashed ? $q->withTrashed() : $q)->whereKey($id)->first();
        abort_unless($row, 404);

        return $row;
    }
}
