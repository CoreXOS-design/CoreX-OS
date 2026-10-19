<?php

namespace App\Http\Controllers\Concerns;

use App\Models\RentalJobCard;
use App\Models\RentalJobCardCrewReport;
use App\Services\Rentals\CrewLineEntryService;
use App\Services\Rentals\CrewLineLooksDuplicate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §17.43 — how the two crew controllers answer a line request: the same banner-and-redirect a plain form
 * post always got (JavaScript off keeps working), or JSON for the no-reload row. The work itself is CrewLineEntryService's.
 */
trait AnswersCrewLineRequests
{
    /**
     * @param array{line: \App\Models\RentalJobCardLine, row: array<string, mixed>, draft_count: int, message: string, audit: string, replayed: bool} $result
     * @param array<string, string> $actions the page's action URLs (only lines_send is read)
     */
    protected function lineAnswer(Request $request, RentalJobCard $card, array $result, string $redirectTo, array $actions): RedirectResponse|JsonResponse
    {
        if (! $this->wantsLineJson($request)) {
            return redirect($redirectTo)->with('success', $result['message']);
        }

        $sendBlock = ['draft_count' => $result['draft_count'], 'first_send' => ! RentalJobCardCrewReport::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->exists()];

        return response()->json([
            'ok' => true,
            'message' => $result['message'],
            'line' => $result['row'],
            'draft_count' => $result['draft_count'],
            'replayed' => $result['replayed'],
            'send_html' => $result['draft_count'] > 0 ? view('rentals.crew-link._pricing-send', ['block' => $sendBlock, 'actions' => $actions])->render() : '',
        ]);
    }

    protected function lineFailure(Request $request, string $message, string $redirectTo, bool $keepInput = false): RedirectResponse|JsonResponse
    {
        if ($this->wantsLineJson($request)) {
            return response()->json(['ok' => false, 'message' => $message], 422);
        }
        $back = redirect($redirectTo)->withErrors(['crew' => $message]);

        return $keepInput ? $back->withInput() : $back;
    }

    /** §17.43 (C-E3): not a failure - a question. 409 + the words to show and what the office already has; the page sends the same add again with add_anyway=1. */
    protected function lineDuplicate(CrewLineLooksDuplicate $e): JsonResponse
    {
        return response()->json(['ok' => false, 'duplicate' => true, 'message' => $e->getMessage(), 'existing' => $e->summary()], 409);
    }

    protected function wantsLineJson(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }

    /** The service, resolved once for both controllers. */
    protected function lineEntry(): CrewLineEntryService
    {
        return app(CrewLineEntryService::class);
    }
}
