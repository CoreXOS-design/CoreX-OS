<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsParkReason;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\Track;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Road to Sold v4 (road-to-sold.md 23.25 / 23.11): the agency's own off-ramp reasons - "Stops here (say why)" on every step of the pitch popup. Code, words, step, what it
 * counts as, days until it comes back; search, sort, filter, archive and restore (soft delete only). "Other" (one per step) can be reworded but never removed or moved.
 * Agency-wide; gated by road_to_sold.manage_offramp_reasons.
 */
class OffRampSettingsController extends Controller
{
    private function agencyId(Request $request): int
    {
        $id = (int) ($request->user()->effectiveAgencyId() ?: 0);
        abort_if($id <= 0, 403, 'Switch into an agency to change its reasons.');

        return $id;
    }

    public function index(Request $request)
    {
        $agencyId = $this->agencyId($request);
        $track = Track::fromRequest($request);
        $ready = RtsParkReason::hasStepColumns();
        $status = in_array($request->query('status'), ['active', 'off', 'archived', 'all'], true) ? $request->query('status') : 'all';
        $step = (int) $request->query('step', 0);
        $q = trim((string) $request->query('q', ''));
        $sort = in_array($request->query('sort'), ['order', 'label', 'step', 'code'], true) ? $request->query('sort') : 'step';
        $rows = collect();
        if ($ready) {
            app(ParkReasonService::class)->ensureStepReasons($agencyId, $track);
            $query = RtsParkReason::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->where('track', $track)->where('kind', RtsParkReason::KIND_OFF_RAMP);
            match ($status) {
                'active' => $query->whereNull('deleted_at')->where('is_active', true),
                'off' => $query->whereNull('deleted_at')->where('is_active', false),
                'archived' => $query->whereNotNull('deleted_at'),
                default => null,
            };
            if ($step >= 1 && $step <= 9) {
                $query->where('step', $step);
            } elseif ($step === -1) {
                $query->whereNull('step');
            }
            if ($q !== '') {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
                $query->where(fn ($w) => $w->where('label', 'like', $like)->orWhere('key', 'like', $like));
            }
            match ($sort) {
                'label' => $query->orderBy('label'),
                'code' => $query->orderBy('key'),
                'order' => $query->orderBy('sort_order')->orderBy('id'),
                default => $query->orderByRaw('step IS NULL')->orderBy('step')->orderBy('is_other')->orderBy('sort_order'),
            };
            $rows = $query->paginate(60)->withQueryString();
        }

        return view('road-to-sold.settings.offramps', [
            'rows' => $rows, 'ready' => $ready, 'track' => $track, 'status' => $status, 'stepFilter' => $step, 'q' => $q, 'sort' => $sort,
            'tabs' => SettingsController::tabsFor($request->user(), 'offramps'), 'steps' => ParkReasonService::STEPS, 'countsAs' => RtsParkReason::COUNTS_AS,
        ]);
    }

    private function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:120'],
            'step' => ['nullable', 'integer', 'min:1', 'max:9'],
            'counts_as' => ['required', Rule::in(array_keys(RtsParkReason::COUNTS_AS))],
            'revisit_days' => ['nullable', 'integer', 'min:0', 'max:730'],
        ];
    }

    public function store(Request $request): RedirectResponse
    {
        $agencyId = $this->agencyId($request);
        $track = Track::fromRequest($request);
        abort_unless(RtsParkReason::hasStepColumns(), 503, 'The reason list is not switched on yet.');
        app(ParkReasonService::class)->ensureStepReasons($agencyId, $track);
        $data = $request->validate($this->rules() + ['step' => ['required', 'integer', 'min:1', 'max:9']], ['label.required' => 'Give the reason a name.', 'step.required' => 'Pick the step it belongs to.']);
        $key = 'custom_' . substr(md5($data['label'] . microtime()), 0, 8);
        RtsParkReason::withoutAgencyStamping(fn () => RtsParkReason::create([
            'agency_id' => $agencyId, 'track' => $track, 'station' => 'popup', 'step' => (int) $data['step'], 'kind' => RtsParkReason::KIND_OFF_RAMP, 'counts_as' => $data['counts_as'], 'is_other' => false,
            'key' => $key, 'label' => trim($data['label']), 'default_revisit_days' => (int) ($data['revisit_days'] ?? 30), 'is_system' => false, 'is_active' => true, 'updated_by_user_id' => $request->user()->id,
            'sort_order' => (int) RtsParkReason::withoutGlobalScopes()->withTrashed()->where('agency_id', $agencyId)->where('track', $track)->max('sort_order') + 10,
        ]));
        ParkReasonService::forgetMemo();

        return back()->with('status', 'Added.');
    }

    public function update(Request $request, int $reason): RedirectResponse
    {
        $row = $this->find($request, $reason);
        $data = $request->validate($this->rules());
        $row->forceFill([
            'label' => trim($data['label']), 'counts_as' => $data['counts_as'], 'default_revisit_days' => (int) ($data['revisit_days'] ?? $row->default_revisit_days), 'updated_by_user_id' => $request->user()->id,
            // "Other" stays on its own step; any other reason may move to another step (or out of the popup lists)
            'step' => $row->is_other ? $row->step : ($data['step'] ?? null),
        ])->save();
        ParkReasonService::forgetMemo();

        return back()->with('status', 'Saved.');
    }

    public function toggle(Request $request, int $reason): RedirectResponse
    {
        $row = $this->find($request, $reason);
        abort_if($row->is_other, 422, '"Other" cannot be switched off.');
        $row->forceFill(['is_active' => ! $row->is_active, 'updated_by_user_id' => $request->user()->id])->save();
        ParkReasonService::forgetMemo();

        return back()->with('status', $row->is_active ? 'Switched on.' : 'Switched off.');
    }

    public function archive(Request $request, int $reason): RedirectResponse
    {
        $row = $this->find($request, $reason);
        abort_if($row->is_other, 422, '"Other" cannot be removed.');
        $row->delete();   // soft delete: records that used it keep its wording
        ParkReasonService::forgetMemo();

        return back()->with('status', 'Archived. Records that used it keep its wording.');
    }

    public function restore(Request $request, int $reason): RedirectResponse
    {
        $this->find($request, $reason, true)->restore();
        ParkReasonService::forgetMemo();

        return back()->with('status', 'Restored.');
    }

    private function find(Request $request, int $id, bool $trashed = false): RtsParkReason
    {
        $q = RtsParkReason::withoutGlobalScopes()->where('agency_id', $this->agencyId($request))->where('kind', RtsParkReason::KIND_OFF_RAMP);
        $row = ($trashed ? $q->withTrashed() : $q)->whereKey($id)->first();
        abort_unless($row, 404);

        return $row;
    }
}
