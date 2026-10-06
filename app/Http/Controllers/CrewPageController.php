<?php

namespace App\Http\Controllers;

use App\Models\RentalCrewLinkEvent;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardTask;
use App\Models\RentalSecureAccessToken;
use App\Services\Rentals\CrewJobService;
use App\Services\Rentals\CrewViewContext;
use App\Services\Rentals\RentalCrewScheduleService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §14.29 — the crew's general page: ONE standing
 * link per crew, no CoreX login. The token IS the crew: agency + crew are read
 * from the token row and nothing in the URL or request can widen them. Every
 * card a request names is resolved through RentalCrewScheduleService::
 * findOpenCard() — it must be one of THAT crew's open cards or the identical
 * "unavailable" page comes back (same page for unknown / expired / revoked /
 * crew archived / crew links off / card not theirs / card closed — nothing
 * here tells those apart).
 *
 * Mirrors ContractorSecureLinkController's no-session, no-route-model-binding
 * shape. Opening a card shows Build 1's shared per-job view (CrewJobService +
 * the _job-body partial), authorised by the crew token; the crew's actions go
 * through the same CrewJobService methods with a `crew_page` context, and each
 * writes the card's own history line AND a row in the crew's link log.
 */
class CrewPageController extends Controller
{
    public function __construct(
        private readonly RentalSecureAccessTokenService $tokens,
        private readonly RentalCrewScheduleService $schedule,
        private readonly CrewJobService $jobs,
    ) {}

    private function resolve(string $token): ?RentalSecureAccessToken
    {
        return $this->tokens->resolveLive($token, RentalSecureAccessToken::PURPOSE_CREW_STANDING);
    }

    private function unavailable(): Response
    {
        return response()->view('rentals.crew-link.crew-page-unavailable', [], 404);
    }

    public function show(Request $request, string $token): View|Response
    {
        $record = $this->resolve($token);
        if (! $record) {
            return $this->unavailable();
        }

        $record->forceFill(['last_used_at' => now()])->save();
        if (! RentalCrewLinkEvent::recentlyOpened($record->id)) {
            RentalCrewLinkEvent::record(RentalCrewLinkEvent::EVENT_OPENED, $record->agency_id, $record->rental_crew_id, $record->id, null, 'Crew page opened', null, $request);
        }

        return view('rentals.crew-link.crew-page', [
            'token' => $token,
            'crew' => $record->crew,
            'page' => $this->schedule->schedule($record->agency_id, $record->rental_crew_id),
        ]);
    }

    public function job(Request $request, string $token, int $card): View|Response
    {
        [$record, $jobCard] = $this->resolveCard($token, $card);
        if (! $record || ! $jobCard) {
            return $this->unavailable();
        }

        $ctx = CrewViewContext::forCrewPage($record, $jobCard, $request);
        $record->forceFill(['last_used_at' => now()])->save();
        if (! RentalCrewLinkEvent::recentlyOpened($record->id, RentalCrewLinkEvent::EVENT_JOB_OPENED, $jobCard->id)) {
            RentalCrewLinkEvent::record(RentalCrewLinkEvent::EVENT_JOB_OPENED, $record->agency_id, $record->rental_crew_id, $record->id, $jobCard->id, 'Opened: ' . $jobCard->title, null, $request);
        }

        return view('rentals.crew-link.crew-page-job', [
            'token' => $token,
            'crew' => $record->crew,
            'job' => $this->jobs->payload($jobCard, $ctx),
            'actions' => $this->actionUrls($token, $jobCard->id),
        ]);
    }

    public function tick(Request $request, string $token, int $card, int $task): JsonResponse|RedirectResponse|Response
    {
        [$record, $jobCard] = $this->resolveCard($token, $card);
        if (! $record || ! $jobCard) {
            return $this->unavailable();
        }

        $taskModel = RentalJobCardTask::withoutGlobalScopes()->where('rental_job_card_id', $jobCard->id)->find($task);
        abort_unless($taskModel, 404);

        $ctx = CrewViewContext::forCrewPage($record, $jobCard, $request);
        try {
            $this->jobs->tick($jobCard, $taskModel, $ctx);
        } catch (\LogicException $e) {
            return $this->failed($request, $token, $jobCard, $e->getMessage());
        }
        $taskModel->refresh();
        $this->logAction($record, $jobCard, ($taskModel->is_done ? 'Ticked: ' : 'Unticked: ') . $taskModel->description, $request);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['is_done' => (bool) $taskModel->is_done]);
        }

        return redirect()->route('rentals.crew-page.job', [$token, $jobCard->id]);
    }

    public function photos(Request $request, string $token, int $card): RedirectResponse|Response
    {
        [$record, $jobCard] = $this->resolveCard($token, $card);
        if (! $record || ! $jobCard) {
            return $this->unavailable();
        }

        $data = $request->validate([
            'photo_type' => ['required', 'in:' . implode(',', CrewJobService::PHOTO_TYPES)],
            'photos' => ['required', 'array', 'min:1', 'max:' . CrewJobService::MAX_PHOTOS_PER_SUBMIT],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'caption' => ['nullable', 'string', 'max:255'],
            'client_keys' => ['nullable', 'array'],
            'client_keys.*' => ['nullable', 'uuid'],
        ], [
            'photos.required' => 'Choose at least one photo.',
            'photos.max' => 'You can add up to ' . CrewJobService::MAX_PHOTOS_PER_SUBMIT . ' photos at a time.',
            'photos.*.mimes' => 'Photos must be JPG, PNG, WEBP or HEIC.',
            'photos.*.max' => 'A photo is too large — each one can be up to 50 MB.',
        ]);

        $ctx = CrewViewContext::forCrewPage($record, $jobCard, $request);
        try {
            $stored = $this->jobs->addPhotos($jobCard, array_values($request->file('photos', [])), $data['photo_type'], $data['caption'] ?? null, $ctx, array_values($data['client_keys'] ?? []));
        } catch (\LogicException|\InvalidArgumentException $e) {
            return $this->failed($request, $token, $jobCard, $e->getMessage());
        }
        $this->logAction($record, $jobCard, $stored . ' photo' . ($stored === 1 ? '' : 's') . ' added (' . str_replace('_', ' ', $data['photo_type']) . ')', $request);

        return redirect()->route('rentals.crew-page.job', [$token, $jobCard->id])
            ->with('success', $stored > 0 ? ($stored === 1 ? 'Photo uploaded.' : "{$stored} photos uploaded.") : 'Those photos were already uploaded.');
    }

    public function complete(Request $request, string $token, int $card): RedirectResponse|Response
    {
        [$record, $jobCard] = $this->resolveCard($token, $card);
        if (! $record || ! $jobCard) {
            return $this->unavailable();
        }

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:191'],
            'confirm' => ['accepted'],
        ], [
            'full_name.required' => 'Type your full name to sign.',
            'full_name.min' => 'Type your full name to sign.',
            'confirm.accepted' => 'Tick the box to confirm the work is completed.',
        ]);

        $ctx = CrewViewContext::forCrewPage($record, $jobCard, $request);
        try {
            $this->jobs->markCompleted($jobCard, $data['full_name'], true, $ctx);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return $this->failed($request, $token, $jobCard, $e->getMessage());
        }
        $this->logAction($record, $jobCard, 'Marked work completed — signed by ' . trim($data['full_name']), $request);

        return redirect()->route('rentals.crew-page.job', [$token, $jobCard->id])->with('success', 'Marked as completed. The office has been told.');
    }

    // ───────────── §17.5 (Build 1) — Parts & labour from the crew page: same service, `crew_page` context ─────────────

    private function lineRules(): array
    {
        return [
            'photos' => ['nullable', 'array', 'max:' . CrewJobService::MAX_LINE_PHOTOS],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
        ];
    }

    private function lineMessages(): array
    {
        return [
            'photos.max' => 'You can add up to ' . CrewJobService::MAX_LINE_PHOTOS . ' photos to one line.',
            'photos.*.mimes' => 'Photos must be JPG, PNG, WEBP or HEIC.',
            'photos.*.max' => 'A photo is too large — each one can be up to 50 MB.',
        ];
    }

    public function addLine(Request $request, string $token, int $card): RedirectResponse|JsonResponse|Response
    {
        [$record, $jobCard] = $this->resolveCard($token, $card);
        if (! $record || ! $jobCard) {
            return $this->unavailable();
        }
        $request->validate($this->lineRules(), $this->lineMessages());

        try {
            $line = $this->jobs->addLine(
                $jobCard,
                $request->only(['type', 'description', 'rental_catalogue_item_id', 'quantity', 'unit', 'unit_cost', 'note', 'is_extra']),
                array_values($request->file('photos', [])),
                CrewViewContext::forCrewPage($record, $jobCard, $request),
            );
        } catch (\InvalidArgumentException|\LogicException $e) {
            return redirect()->route('rentals.crew-page.job', [$token, $jobCard->id])->withErrors(['crew' => $e->getMessage()])->withInput();
        }
        $this->logAction($record, $jobCard, 'Added a ' . $line->type . ' line (draft): ' . $line->description, $request);

        return redirect()->route('rentals.crew-page.job', [$token, $jobCard->id])->with('success', 'Line added. Press "Send to office" when you have added everything.');
    }

    public function updateLine(Request $request, string $token, int $card, int $line): RedirectResponse|Response
    {
        [$record, $jobCard] = $this->resolveCard($token, $card);
        if (! $record || ! $jobCard) {
            return $this->unavailable();
        }
        $request->validate($this->lineRules(), $this->lineMessages());
        $lineModel = RentalJobCardLine::withoutGlobalScopes()->where('rental_job_card_id', $jobCard->id)->whereKey($line)->firstOrFail();

        try {
            $this->jobs->editDraft(
                $jobCard,
                $lineModel,
                $request->only(['type', 'description', 'rental_catalogue_item_id', 'quantity', 'unit', 'unit_cost', 'note']),
                array_values($request->file('photos', [])),
                CrewViewContext::forCrewPage($record, $jobCard, $request),
            );
        } catch (\InvalidArgumentException|\LogicException $e) {
            return redirect()->route('rentals.crew-page.job', [$token, $jobCard->id])->withErrors(['crew' => $e->getMessage()]);
        }
        $this->logAction($record, $jobCard, 'Changed a draft line: ' . $lineModel->description, $request);

        return redirect()->route('rentals.crew-page.job', [$token, $jobCard->id])->with('success', 'Line changed.');
    }

    public function archiveLine(Request $request, string $token, int $card, int $line): RedirectResponse|Response
    {
        [$record, $jobCard] = $this->resolveCard($token, $card);
        if (! $record || ! $jobCard) {
            return $this->unavailable();
        }
        $lineModel = RentalJobCardLine::withoutGlobalScopes()->where('rental_job_card_id', $jobCard->id)->whereKey($line)->firstOrFail();

        try {
            $this->jobs->archiveDraft($jobCard, $lineModel, CrewViewContext::forCrewPage($record, $jobCard, $request));
        } catch (\LogicException $e) {
            return redirect()->route('rentals.crew-page.job', [$token, $jobCard->id])->withErrors(['crew' => $e->getMessage()]);
        }
        $this->logAction($record, $jobCard, 'Removed a draft line: ' . $lineModel->description, $request);

        return redirect()->route('rentals.crew-page.job', [$token, $jobCard->id])->with('success', 'Line removed.');
    }

    public function sendLines(Request $request, string $token, int $card): RedirectResponse|Response
    {
        [$record, $jobCard] = $this->resolveCard($token, $card);
        if (! $record || ! $jobCard) {
            return $this->unavailable();
        }
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm before you send.']);

        try {
            $sent = $this->jobs->sendToOffice($jobCard, CrewViewContext::forCrewPage($record, $jobCard, $request));
        } catch (\InvalidArgumentException|\LogicException $e) {
            return redirect()->route('rentals.crew-page.job', [$token, $jobCard->id])->withErrors(['crew' => $e->getMessage()]);
        }
        $this->logAction($record, $jobCard, "Sent {$sent} part/labour line" . ($sent === 1 ? '' : 's') . ' to the office', $request);

        return redirect()->route('rentals.crew-page.job', [$token, $jobCard->id])->with('success', $sent === 1 ? 'Sent to the office. They will check it and price it.' : "Sent {$sent} lines to the office. They will check them and price them.");
    }

    /** @return array{0: ?RentalSecureAccessToken, 1: ?RentalJobCard} */
    private function resolveCard(string $token, int $cardId): array
    {
        $record = $this->resolve($token);
        if (! $record) {
            return [null, null];
        }

        return [$record, $this->schedule->findOpenCard($record->agency_id, $record->rental_crew_id, $cardId)];
    }

    private function actionUrls(string $token, int $cardId): array
    {
        return [
            'tick' => route('rentals.crew-page.tick', [$token, $cardId, '__TASK__']),
            'photos' => route('rentals.crew-page.photos', [$token, $cardId]),
            'complete' => route('rentals.crew-page.complete', [$token, $cardId]),
            // §17.5 (Build 1) — the Parts & labour panel's own actions.
            'line_add' => route('rentals.crew-page.lines.store', [$token, $cardId]),
            'line_update' => route('rentals.crew-page.lines.update', ['token' => $token, 'card' => $cardId, 'line' => '__LINE__']),
            'line_archive' => route('rentals.crew-page.lines.archive', ['token' => $token, 'card' => $cardId, 'line' => '__LINE__']),
            'lines_send' => route('rentals.crew-page.lines.send', [$token, $cardId]),
        ];
    }

    private function logAction(RentalSecureAccessToken $record, RentalJobCard $card, string $note, Request $request): void
    {
        RentalCrewLinkEvent::record(RentalCrewLinkEvent::EVENT_ACTION, $record->agency_id, $record->rental_crew_id, $record->id, $card->id, $note . ' — ' . $card->title, null, $request);
    }

    private function failed(Request $request, string $token, RentalJobCard $card, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['message' => $message], 422);
        }

        return redirect()->route('rentals.crew-page.job', [$token, $card->id])->withErrors(['crew' => $message]);
    }
}
