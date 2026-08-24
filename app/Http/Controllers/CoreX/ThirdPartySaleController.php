<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyThirdPartySale;
use App\Services\Properties\ThirdPartySaleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * AT-350 — record / enrich / undo "another agency sold this property".
 *
 * Spec: .ai/specs/property-sold-by-third-party.md §6, §8, §9
 *
 * No new permission key: this is an ordinary property write, so it rides the
 * properties route group's `permission:access_properties` + `agency.required`
 * and the per-property data scope via authorizeProperty(forEdit: true) — which
 * correctly refuses an assistant (view breadth, no write) per AT-267 §7.2.
 *
 * Every capture field is optional (spec D4). Validation therefore exists to stop
 * the impossible (a sale dated tomorrow, a negative price), never to stop the
 * incomplete — "we only know that it sold" is a first-class path.
 */
class ThirdPartySaleController extends Controller
{
    use AuthorizesPropertyAccess;

    public function __construct(private readonly ThirdPartySaleService $service)
    {
    }

    /** POST — mark the property sold by another agency (rich path). */
    public function store(Request $request, Property $property): RedirectResponse
    {
        $this->authorizeProperty($property);
        $data = $this->validated($request);

        try {
            $this->service->record($property, $data, $request->user());
        } catch (RuntimeException $e) {
            // Guard failures are user-fixable situations, not faults: a listing
            // already concluded, or no agency context. Plain language, back to the
            // form with their input intact — never a stack trace (BUILD_STANDARD §4).
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Recorded as sold by another agency. The listing has been taken off the portals and is not counted as an HFC sale.');
    }

    /** PATCH — enrich the open loss record ("Add details" on the banner). */
    public function update(Request $request, Property $property): RedirectResponse
    {
        $this->authorizeProperty($property);
        $record = $this->openRecordOrFail($property);
        $data   = $this->validated($request);

        $this->service->updateRecord($record, $data, $request->user());

        return back()->with('success', 'Third-party sale details updated.');
    }

    /** POST — put the listing back on the market. The loss record is KEPT. */
    public function revert(Request $request, Property $property): RedirectResponse
    {
        $this->authorizeProperty($property);
        $this->openRecordOrFail($property);

        // Draft, not active: the agent decides where it goes next, and a listing
        // that was off the portals should not silently re-advertise itself. The
        // observer closes the loss record off the back of this status change, so
        // the revert path stays the same one every other status change uses.
        $property->status = 'draft';
        $property->save();

        return back()->with('success', 'Listing returned to Draft. The loss record has been kept for reporting.');
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * @return array{sold_by_agency:?string, sold_price:mixed, sold_date:?string, loss_reason:?string, notes:?string}
     */
    private function validated(Request $request): array
    {
        // Trim before validating so a stray space can never create a second
        // "Seeff Margate " in the Loss Analysis report (BUILD_STANDARD §2).
        $request->merge([
            'sold_by_agency' => is_string($request->input('sold_by_agency'))
                ? trim($request->input('sold_by_agency')) : $request->input('sold_by_agency'),
            'notes' => is_string($request->input('notes'))
                ? trim($request->input('notes')) : $request->input('notes'),
        ]);

        return $request->validate([
            'sold_by_agency' => ['nullable', 'string', 'max:200'],
            // max mirrors PresentationOutcome::cancellation_competitor_price — a
            // fat-fingered extra zero is caught, a genuine R950m estate is not.
            'sold_price'     => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'sold_date'      => ['nullable', 'date', 'before_or_equal:today'],
            'loss_reason'    => ['nullable', Rule::in(array_keys(PropertyThirdPartySale::LOSS_REASONS))],
            'notes'          => ['nullable', 'string', 'max:2000'],
        ], [
            'sold_date.before_or_equal' => "The sold date can't be in the future.",
            'sold_price.max'            => 'That sold price looks too high — please check the amount.',
            'sold_price.numeric'        => 'Enter the sold price as a number, with no spaces or currency symbol.',
            'sold_by_agency.max'        => 'The agency name is too long (200 characters maximum).',
            'loss_reason.in'            => 'Please choose one of the listed reasons.',
        ]);
    }

    private function openRecordOrFail(Property $property): PropertyThirdPartySale
    {
        $record = $property->thirdPartySales()->whereNull('reverted_at')->first();

        abort_if($record === null, 404, 'This property has no open third-party sale record.');

        return $record;
    }
}
