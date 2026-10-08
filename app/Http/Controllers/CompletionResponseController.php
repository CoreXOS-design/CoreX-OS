<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Property;
use App\Models\RentalSecureAccessToken;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalJobCardClientViewService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-work-orders.md §17.10.3 / §17.10.4 — the tenant's one-click "is the work finished?" page. No login:
 * the token in the URL is the credential (64 random characters, only its SHA-256 stored, ONE live link per completion
 * round). Deliberately a TOP-LEVEL controller (like CrewJobLinkController / ContractorSecureLinkController): the caller
 * has no CoreX session, so every query strips global scopes and pins the agency explicitly, and the round (and so the
 * work order) is taken from the TOKEN ROW — never from anything in the URL or body.
 *
 * Unknown / forged / revoked / expired tokens render ONE identical "unavailable" page (never saying which). A live
 * token whose round is answered shows a read-only "You answered …" page and refuses a second POST; once the response
 * window has passed it says "The response period has ended — please report a new fault". Rate-limited on the routes.
 */
class CompletionResponseController extends Controller
{
    public function __construct(private readonly RentalCompletionService $completion)
    {
    }

    /** @return array{0: RentalSecureAccessToken, 1: RentalWorkCompletionRound, 2: RentalWorkOrder}|null */
    private function resolve(string $token): ?array
    {
        $record = app(RentalSecureAccessTokenService::class)->resolveLive($token, RentalSecureAccessToken::PURPOSE_TENANT_COMPLETION);
        $round = $record?->completionRound;
        if (! $record || ! $round) {
            return null;
        }
        $workOrder = RentalWorkOrder::withoutGlobalScopes()->where('agency_id', $record->agency_id)->whereNull('deleted_at')->find($round->rental_work_order_id);
        if (! $workOrder) {
            return null;
        }

        return [$record, $round, $workOrder];
    }

    private function unavailable(?string $message = null): View
    {
        return view('rentals.completion.unavailable', ['message' => $message]);
    }

    public function show(Request $request, string $token): View
    {
        $resolved = $this->resolve($token);
        if (! $resolved) {
            return $this->unavailable();
        }
        [$record, $round, $workOrder] = $resolved;
        $record->forceFill(['last_used_at' => now()])->save();

        $state = $this->completion->responseState($round);

        return match ($state) {
            RentalCompletionService::STATE_OPEN => view('rentals.completion.show', $this->viewData($record, $round, $workOrder, $token)),
            RentalCompletionService::STATE_ANSWERED => view('rentals.completion.answered', $this->viewData($record, $round, $workOrder, $token)),
            RentalCompletionService::STATE_ENDED => $this->unavailable('The response period has ended — please report a new fault.'),
            default => $this->unavailable(),
        };
    }

    public function respond(Request $request, string $token): RedirectResponse|View
    {
        $resolved = $this->resolve($token);
        if (! $resolved) {
            return $this->unavailable();
        }
        [$record, $round] = $resolved;

        $data = $request->validate([
            'answer' => ['required', 'in:fixed,not_fixed'],
            'note' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:' . RentalCompletionService::MAX_DISPUTE_PHOTOS],
            'photos.*' => ['file', 'image', 'max:15360'],
        ], [
            'answer.required' => 'Please choose one of the two answers.',
            'photos.max' => 'You can attach up to ' . RentalCompletionService::MAX_DISPUTE_PHOTOS . ' photos.',
            'photos.*.image' => 'Photos must be image files (JPG, PNG, WEBP or HEIC).',
            'photos.*.max' => 'Each photo can be up to 15 MB.',
        ]);

        try {
            $this->completion->respond($round, $data['answer'] === 'fixed', $data['note'] ?? null, $request->file('photos', []), [
                'via' => RentalWorkCompletionRound::RESPONDED_LINK,
                'ip' => $request->ip(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('rentals.completion.show', $token)->withErrors(['note' => $e->getMessage()])->withInput($request->except('photos'));
        } catch (\LogicException) {
            // Already answered / the window ended / the job was cancelled: the page itself says which.
            return redirect()->route('rentals.completion.show', $token);
        }

        return redirect()->route('rentals.completion.show', $token)->with('success', $data['answer'] === 'fixed'
            ? 'Thank you — we have noted that the work is done.'
            : 'Thank you — we have told the office the work is not complete. They will arrange for it to be put right.');
    }

    /** @return array<string, mixed> */
    private function viewData(RentalSecureAccessToken $record, RentalWorkCompletionRound $round, RentalWorkOrder $workOrder, string $token): array
    {
        $branding = Agency::publicBrandingFor((int) $record->agency_id);
        $property = Property::withoutGlobalScopes()->withTrashed()->find($workOrder->property_id);
        $agency = Agency::withoutGlobalScopes()->find($record->agency_id);
        $tz = $agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        $view = app(RentalJobCardClientViewService::class);

        return [
            'agency' => ['name' => $branding['name'], 'logo_url' => $branding['logoUrl'], 'color' => $branding['colors']['default']],
            'title' => $workOrder->title,
            'address' => $property?->buildDisplayAddress() ?: '',
            // never a crew member's name on the agency's own crew (RentalWorkOrderClientViewService::reportedBy)
            'reportedBy' => app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->reportedBy($round, $workOrder) ?: 'the maintenance crew',
            'reportedOn' => $round->opened_at?->copy()->setTimezone($tz)->format('j M Y'),
            'answerBy' => $round->window_ends_at?->copy()->setTimezone($tz)->format('j M Y'),
            'photos' => $view->photosForWorkOrder($workOrder)->map(fn (RentalWorkOrderPhoto $p) => ['url' => $p->storage_path])->all(),
            'answered' => $round->responded_at ? [
                'fixed' => $round->outcome === RentalWorkCompletionRound::OUTCOME_CONFIRMED,
                'on' => $round->responded_at->copy()->setTimezone($tz)->format('j M Y'),
                'note' => $round->response_note,
            ] : null,
            'maxPhotos' => RentalCompletionService::MAX_DISPUTE_PHOTOS,
            'actionUrl' => route('rentals.completion.respond', $token),
        ];
    }
}
