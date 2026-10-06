<?php

namespace App\Http\Controllers;

use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardTask;
use App\Models\RentalSecureAccessToken;
use App\Services\Rentals\CrewJobService;
use App\Services\Rentals\CrewViewContext;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §14.28 — the crew's per-job secure link:
 * no login, no CoreX session, reached from a phone. Deliberately a TOP-LEVEL
 * controller (App\Http\Controllers, not ...\CoreX) and a sibling of
 * ContractorSecureLinkController, same reasoning: the caller has no session.
 *
 * Every action resolves the token explicitly (RentalSecureAccessTokenService::
 * resolveLive — never a route-model-bound {token}) and takes the card from the
 * TOKEN ROW, never from anything in the URL or body, so a crew link can only
 * ever reach its own card. Unknown / forged / expired / revoked / switched-off
 * / closed / archived all render the SAME "unavailable" page — never
 * distinguishing which. All work is delegated to CrewJobService, which refuses
 * a closed card again and writes the audit lines. Rate limits are on the
 * routes (throttle:30,1; photos also throttle:60,10).
 */
class CrewJobLinkController extends Controller
{
    /** @return array{0: RentalSecureAccessToken, 1: RentalJobCard}|null */
    private function resolve(string $token): ?array
    {
        $record = app(RentalSecureAccessTokenService::class)->resolveLive($token, RentalSecureAccessToken::PURPOSE_CREW_JOB_CARD);
        $card = $record?->jobCard;
        if (! $record || ! $card) {
            return null;
        }

        return [$record, $card];
    }

    private function actions(string $token): array
    {
        return [
            'tick' => route('rentals.crew-job.tick', ['token' => $token, 'task' => '__TASK__']),
            'photos' => route('rentals.crew-job.photos', $token),
            'complete' => route('rentals.crew-job.complete', $token),
            // §17.5 (Build 1) — the Parts & labour panel's own actions.
            'line_add' => route('rentals.crew-job.lines.store', $token),
            'line_update' => route('rentals.crew-job.lines.update', ['token' => $token, 'line' => '__LINE__']),
            'line_archive' => route('rentals.crew-job.lines.archive', ['token' => $token, 'line' => '__LINE__']),
            'lines_send' => route('rentals.crew-job.lines.send', $token),
        ];
    }

    private function unavailable(): View
    {
        return view('rentals.crew-link.unavailable');
    }

    public function show(Request $request, string $token): View
    {
        $resolved = $this->resolve($token);
        if (! $resolved) {
            return $this->unavailable();
        }
        [$record, $card] = $resolved;

        // First open is a history line; every open refreshes last_used_at.
        $firstOpen = $record->last_used_at === null;
        $record->forceFill(['last_used_at' => now()])->save();
        if ($firstOpen) {
            $card->logUpdate('link_opened', null, 'Crew opened the link' . ($card->crew?->name ? " — {$card->crew->name}" : ''));
        }

        $ctx = CrewViewContext::forJobLink($record, $card, $request);

        return view('rentals.crew-link.job', [
            'job' => app(CrewJobService::class)->payload($card, $ctx),
            'actions' => $this->actions($token),
        ]);
    }

    public function tick(Request $request, string $token, int $task): RedirectResponse|JsonResponse|View
    {
        $resolved = $this->resolve($token);
        if (! $resolved) {
            return $this->unavailable();
        }
        [$record, $card] = $resolved;

        // Scoped to THIS card by the query itself — a task id of another card is a 404.
        $taskModel = RentalJobCardTask::withoutGlobalScopes()
            ->where('rental_job_card_id', $card->id)->whereKey($task)->firstOrFail();

        app(CrewJobService::class)->tick($card, $taskModel, CrewViewContext::forJobLink($record, $card, $request));

        if ($request->expectsJson()) {
            return response()->json(['is_done' => (bool) $taskModel->fresh()->is_done]);
        }

        return redirect()->route('rentals.crew-job.show', $token);
    }

    public function photos(Request $request, string $token): RedirectResponse|View
    {
        $resolved = $this->resolve($token);
        if (! $resolved) {
            return $this->unavailable();
        }
        [$record, $card] = $resolved;

        $data = $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:' . CrewJobService::MAX_PHOTOS_PER_SUBMIT],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'photo_type' => ['required', 'in:' . implode(',', CrewJobService::PHOTO_TYPES)],
            'caption' => ['nullable', 'string', 'max:255'],
            'client_keys' => ['nullable', 'array'],
            'client_keys.*' => ['nullable', 'uuid'],
        ], [
            'photos.max' => 'You can add up to ' . CrewJobService::MAX_PHOTOS_PER_SUBMIT . ' photos at a time.',
            'photos.*.mimes' => 'Photos must be JPG, PNG, WEBP or HEIC.',
            'photos.*.max' => 'Each photo can be up to 50 MB.',
        ]);

        try {
            $count = app(CrewJobService::class)->addPhotos(
                $card,
                array_values($request->file('photos', [])),
                $data['photo_type'],
                $data['caption'] ?? null,
                CrewViewContext::forJobLink($record, $card, $request),
                array_values($data['client_keys'] ?? []),
            );
        } catch (\LogicException) {
            return $this->unavailable();
        }

        return redirect()->route('rentals.crew-job.show', $token)
            ->with('success', $count > 0 ? ($count === 1 ? 'Photo added.' : "{$count} photos added.") : 'Those photos were already added.');
    }

    public function complete(Request $request, string $token): RedirectResponse|View
    {
        $resolved = $this->resolve($token);
        if (! $resolved) {
            return $this->unavailable();
        }
        [$record, $card] = $resolved;

        $data = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:191'],
            'confirm' => ['accepted'],
        ], [
            'full_name.required' => 'Type your full name to sign.',
            'confirm.accepted' => 'Tick the box to confirm the work is completed.',
        ]);

        try {
            app(CrewJobService::class)->markCompleted($card, $data['full_name'], true, CrewViewContext::forJobLink($record, $card, $request));
        } catch (\InvalidArgumentException|\LogicException $e) {
            return redirect()->route('rentals.crew-job.show', $token)->withErrors(['full_name' => $e->getMessage()])->withInput();
        }

        return redirect()->route('rentals.crew-job.show', $token)->with('success', 'Thank you — the work has been marked completed. The agency will check it.');
    }
    // ───────────── §17.5 (Build 1) — the crew's Parts & labour: add / change / remove a draft, send to the office ─────────────

    /** The validation that is about the REQUEST's shape; what the numbers and words mean is CrewJobService's (it speaks plain language). */
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

    public function addLine(Request $request, string $token): RedirectResponse|View
    {
        $resolved = $this->resolve($token);
        if (! $resolved) {
            return $this->unavailable();
        }
        [$record, $card] = $resolved;
        $request->validate($this->lineRules(), $this->lineMessages());

        try {
            app(CrewJobService::class)->addLine(
                $card,
                $request->only(['type', 'description', 'rental_catalogue_item_id', 'quantity', 'unit', 'unit_cost', 'note', 'is_extra']),
                array_values($request->file('photos', [])),
                CrewViewContext::forJobLink($record, $card, $request),
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('rentals.crew-job.show', $token)->withErrors(['crew' => $e->getMessage()])->withInput();
        } catch (\LogicException) {
            return $this->unavailable();
        }

        return redirect()->route('rentals.crew-job.show', $token)->with('success', 'Line added. Press "Send to office" when you have added everything.');
    }

    public function updateLine(Request $request, string $token, int $line): RedirectResponse|View
    {
        $resolved = $this->resolve($token);
        if (! $resolved) {
            return $this->unavailable();
        }
        [$record, $card] = $resolved;
        $request->validate($this->lineRules(), $this->lineMessages());
        // Scoped to THIS card by the query itself — a line id of another card is a 404.
        $lineModel = RentalJobCardLine::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->whereKey($line)->firstOrFail();

        try {
            app(CrewJobService::class)->editDraft(
                $card,
                $lineModel,
                $request->only(['type', 'description', 'rental_catalogue_item_id', 'quantity', 'unit', 'unit_cost', 'note']),
                array_values($request->file('photos', [])),
                CrewViewContext::forJobLink($record, $card, $request),
            );
        } catch (\InvalidArgumentException|\LogicException $e) {
            return redirect()->route('rentals.crew-job.show', $token)->withErrors(['crew' => $e->getMessage()]);
        }

        return redirect()->route('rentals.crew-job.show', $token)->with('success', 'Line changed.');
    }

    public function archiveLine(Request $request, string $token, int $line): RedirectResponse|View
    {
        $resolved = $this->resolve($token);
        if (! $resolved) {
            return $this->unavailable();
        }
        [$record, $card] = $resolved;
        $lineModel = RentalJobCardLine::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->whereKey($line)->firstOrFail();

        try {
            app(CrewJobService::class)->archiveDraft($card, $lineModel, CrewViewContext::forJobLink($record, $card, $request));
        } catch (\LogicException $e) {
            return redirect()->route('rentals.crew-job.show', $token)->withErrors(['crew' => $e->getMessage()]);
        }

        return redirect()->route('rentals.crew-job.show', $token)->with('success', 'Line removed.');
    }

    public function sendLines(Request $request, string $token): RedirectResponse|View
    {
        $resolved = $this->resolve($token);
        if (! $resolved) {
            return $this->unavailable();
        }
        [$record, $card] = $resolved;
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => 'Tick the box to confirm before you send.']);

        try {
            $sent = app(CrewJobService::class)->sendToOffice($card, CrewViewContext::forJobLink($record, $card, $request));
        } catch (\InvalidArgumentException|\LogicException $e) {
            return redirect()->route('rentals.crew-job.show', $token)->withErrors(['crew' => $e->getMessage()]);
        }

        return redirect()->route('rentals.crew-job.show', $token)->with('success', $sent === 1 ? 'Sent to the office. They will check it and price it.' : "Sent {$sent} lines to the office. They will check them and price them.");
    }
}
