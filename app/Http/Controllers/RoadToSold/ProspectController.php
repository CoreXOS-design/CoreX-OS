<?php

namespace App\Http\Controllers\RoadToSold;

use App\Http\Controllers\Controller;
use App\Models\RoadToSold\RtsProspect;
use App\Models\RoadToSold\RtsProspectEvent;
use App\Services\RoadToSold\ParkReasonService;
use App\Services\RoadToSold\ProspectService;
use App\Services\RoadToSold\ProspectStages;
use App\Services\RoadToSold\ProspectStepService;
use App\Services\RoadToSold\Track;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Road to Sold v3 - what the agent does with a prospect from the page (road-to-sold.md section 22.4 / 22.9 / 22.12).
 *
 * Every route finds the prospect through RtsProspect::visibleTo() (own / branch / agency breadth in the QUERY, so an id outside it is a
 * 404) and every WRITE then needs the prospect to be on the viewer's own list (an assistant's: their agent's). A manager looking at an
 * agent's page is read-only: the route refuses with 403, the page simply does not draw the buttons.
 */
class ProspectController extends Controller
{
    public function __construct(
        private readonly ProspectService $prospects,
        private readonly ProspectStepService $steps,
        private readonly ParkReasonService $reasons,
    ) {
    }

    private function find(Request $request, int $id, bool $write): RtsProspect
    {
        $viewer = $request->user();
        $p = RtsProspect::query()->visibleTo($viewer)->where('rts_prospects.track', Track::fromRequest($request))->whereKey($id)->first();
        abort_unless($p, 404);
        if ($write) {
            abort_unless((int) $p->user_id === (int) $viewer->ownershipUserId(), 403, 'Only the agent who owns this property can work it.');
        }

        return $p;
    }

    /** The pop-up: the real screen for one step, pre-filled, in embed mode (the page shows it in an iframe). */
    public function step(Request $request, int $prospect, string $step): RedirectResponse
    {
        $p = $this->find($request, $prospect, true);
        abort_unless($this->steps->exists($step), 404);
        $url = $this->steps->urlFor($p, $step);
        abort_unless($url, 404, 'That step has no screen yet.');

        return redirect()->to($url);
    }

    /** Set a prospect aside with a reason and a date it comes back. */
    public function park(Request $request, int $prospect): RedirectResponse
    {
        $p = $this->find($request, $prospect, true);
        $this->reasons->ensureDefaults((int) $p->agency_id, (string) $p->track);
        $keys = $this->reasons->choices((int) $p->agency_id, (string) $p->track)->pluck('key')->all();
        $data = $request->validate([
            'reason' => ['required', 'string', 'in:' . implode(',', $keys ?: ['other'])],
            'until'  => ['nullable', 'date', 'after:today'],
            'note'   => ['nullable', 'string', 'max:255'],
        ], [
            'reason.required' => 'Pick why you are setting it aside.',
            'reason.in'       => 'Pick one of the reasons shown.',
            'until.after'     => 'Pick a day after today for it to come back.',
            'until.date'      => 'We could not read that date. Pick it again.',
        ]);
        $this->prospects->park($p, $data['reason'], ! empty($data['until']) ? Carbon::parse($data['until']) : null, $data['note'] ?? null, $request->user());

        return back()->with('status', 'Set aside. It comes back on ' . $p->fresh()->parked_until->format('j M Y') . '.');
    }

    /** Bring a parked prospect back now: a follow-up due today. */
    public function unpark(Request $request, int $prospect): RedirectResponse
    {
        $this->prospects->unpark($this->find($request, $prospect, true), $request->user());

        return back()->with('status', 'Brought back - it is in today\'s follow-ups.');
    }

    /** Change the next action and its date. */
    public function nextAction(Request $request, int $prospect): RedirectResponse
    {
        $p = $this->find($request, $prospect, true);
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'at'    => ['nullable', 'date'],
        ], ['label.required' => 'Say what you will do next.', 'at.date' => 'We could not read that date. Pick it again.']);
        $this->prospects->setNextAction($p, trim($data['label']), ! empty($data['at']) ? Carbon::parse($data['at']) : null, $request->user());

        return back()->with('status', 'Next action saved.');
    }

    /**
     * The pop-up's own capture (22.9): a step that raised no CoreX event is captured by hand - what happened, which stage it reached, the next
     * action and its date. Forward only (ProspectService::advance).
     */
    public function capture(Request $request, int $prospect): RedirectResponse
    {
        $p = $this->find($request, $prospect, true);
        $data = $request->validate([
            'stage' => ['required', 'string'],
            'note'  => ['required', 'string', 'max:255'],
            'next_label' => ['required', 'string', 'max:120'],
            'next_at'    => ['required', 'date'],
        ], [
            'stage.required' => 'Pick the stage it reached.', 'note.required' => 'Say what happened.',
            'next_label.required' => 'Say what you will do next.', 'next_at.required' => 'Pick the date for the next action.',
            'next_at.date' => 'We could not read that date. Pick it again.',
        ]);
        if (! ProspectStages::exists($data['stage'])) {
            throw ValidationException::withMessages(['stage' => 'Pick one of the stages shown.']);
        }
        $moved = $this->prospects->advance($p, $data['stage'], $request->user(), [
            'note' => $data['note'] ?? null, 'event_class' => 'captured', 'subject_type' => 'capture', 'subject_id' => (int) now()->format('Uu'),
            'next_label' => $data['next_label'],
            'next_at' => Carbon::parse($data['next_at']),
        ]);
        if (! $moved) {
            throw ValidationException::withMessages(['stage' => 'It is already at or past that stage.']);
        }

        return back()->with('status', 'Saved: ' . ProspectStages::label($data['stage']) . '.');
    }

    /**
     * Give the property back to the pool (spec 22.6). Needs the release key, the prospect inside the viewer's breadth (404 outside), and a
     * reason - which is kept on the prospect's history together with who gave it back. Not limited to the viewer's own list: a manager releases
     * an agent's property.
     */
    public function release(Request $request, int $prospect): RedirectResponse
    {
        $viewer = $request->user();
        abort_unless($viewer->hasPermission('road_to_sold.release_prospects'), 403, 'You may not give a property back.');
        $p = $this->find($request, $prospect, false);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']], [
            'reason.required' => 'Say why you are giving it back.',
            'reason.min'      => 'Say a little more about why you are giving it back.',
            'reason.max'      => 'Keep the reason under 255 characters.',
        ]);
        if ($p->closed_at !== null || $p->released_at !== null) {
            return back()->with('rts_error', 'That property is not held by an agent any more.');
        }
        $this->prospects->release($p, $viewer, trim($data['reason']));

        return back()->with('status', 'Given back. The property can be offered to another agent again.');
    }

    /** A prospect's story, in a pop-up (chrome-less when framed). A manager may read it: scoping is the query. */
    public function history(Request $request, int $prospect)
    {
        $p = $this->find($request, $prospect, false);
        $events = RtsProspectEvent::query()->where('prospect_id', $p->id)->orderByDesc('occurred_at')->orderByDesc('id')->limit(100)->get();

        return view('road-to-sold.prospect-history', [
            'prospect' => $p->load('trackedProperty'),
            'events' => $events,
            'stageLabel' => fn (?string $s) => $s ? ProspectStages::label($s) : '-',
        ]);
    }
}
