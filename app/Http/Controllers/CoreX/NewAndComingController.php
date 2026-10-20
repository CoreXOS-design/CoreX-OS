<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Services\FeatureRollout\FeatureRolloutUpdates;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * "New and coming" (AT-478 §12.5 coming-soon + §12.9 what's-new history). Every signed-in person in an
 * agency on gradual rollout: what is still to switch on (with "Ask for this early") and every group that
 * switched on, showing only what they can open. Own agency only — 404 for an agency not on a rollout.
 */
class NewAndComingController extends Controller
{
    public function __construct(private readonly FeatureRolloutUpdates $updates)
    {
    }

    public function index(Request $request)
    {
        $data = $this->updates->forUser($request->user());
        abort_unless($data !== null, 404);

        $filters = $request->validate([
            'q'    => 'nullable|string|max:100',
            'show' => 'nullable|in:all,coming,on',
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);
        $show = $filters['show'] ?? 'all';
        $q = mb_strtolower((string) ($filters['q'] ?? ''));

        $matchesText = fn (array $group) => $q === '' || str_contains(mb_strtolower($group['wave']->title), $q)
            || collect($group['features'])->contains(fn ($f) => str_contains(mb_strtolower($f['label']), $q));
        $inRange = function (?\Carbon\CarbonInterface $d) use ($filters) {
            if ($d === null) {
                return empty($filters['from']) && empty($filters['to']);
            }

            return (empty($filters['from']) || $d->toDateString() >= $filters['from'])
                && (empty($filters['to']) || $d->toDateString() <= $filters['to']);
        };

        $coming = $show === 'on' ? [] : array_values(array_filter($data['coming'], fn ($g) => $matchesText($g) && $inRange($g['when'])));
        $on = $show === 'coming' ? [] : array_values(array_filter($data['on'], fn ($g) => $matchesText($g) && $inRange($g['wave']->released_at)));

        $page = max(1, (int) $request->query('page', 1));
        $onPage = new LengthAwarePaginator(array_slice($on, ($page - 1) * 10, 10), count($on), 10, $page,
            ['path' => $request->url(), 'query' => $request->query()]);

        return view('corex.feature-rollout.new-and-coming', [
            'plan'     => $data['plan'],
            'coming'   => $coming,
            'on'       => $onPage,
            'filters'  => $filters + ['show' => $show],
            'filtered' => $q !== '' || ! empty($filters['from']) || ! empty($filters['to']) || $show !== 'all',
        ]);
    }

    public function requestEarly(Request $request, int $item)
    {
        abort_unless($this->updates->planFor($request->user()) !== null, 404);
        try {
            $message = $this->updates->requestEarly($request->user(), $item);
        } catch (\DomainException $e) {
            return redirect()->route('corex.new-and-coming.index')->with('error', $e->getMessage());
        }

        return redirect()->route('corex.new-and-coming.index')->with('success', $message);
    }
}
