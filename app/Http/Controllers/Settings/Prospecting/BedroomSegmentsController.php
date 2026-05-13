<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings\Prospecting;

use App\Events\Prospecting\BedroomSegmentConfigured;
use App\Http\Controllers\Controller;
use App\Models\Prospecting\BedroomSegment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BedroomSegmentsController extends Controller
{
    public function store(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        $validated = $this->validateInput($request);

        $segment = BedroomSegment::create([
            'agency_id'     => $agencyId,
            'name'          => $validated['name'],
            'beds_min'      => $validated['beds_min'],
            'beds_max'      => $validated['beds_max'],
            'display_order' => $this->nextDisplayOrder($agencyId),
        ]);

        event(new BedroomSegmentConfigured(
            segment:     $segment,
            action:      BedroomSegmentConfigured::ACTION_CREATED,
            actorUserId: Auth::id(),
            agencyId:    $agencyId,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'bedroom-segments'])
            ->with('status', "Bedroom segment '{$segment->name}' added.");
    }

    public function update(Request $request, BedroomSegment $segment)
    {
        $this->authorizeAgency($request, $segment);
        $validated = $this->validateInput($request);

        $segment->update([
            'name'     => $validated['name'],
            'beds_min' => $validated['beds_min'],
            'beds_max' => $validated['beds_max'],
        ]);

        event(new BedroomSegmentConfigured(
            segment:     $segment->fresh(),
            action:      BedroomSegmentConfigured::ACTION_UPDATED,
            actorUserId: Auth::id(),
            agencyId:    $segment->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'bedroom-segments'])
            ->with('status', 'Bedroom segment updated.');
    }

    public function archive(Request $request, BedroomSegment $segment)
    {
        $this->authorizeAgency($request, $segment);

        $segment->delete();

        event(new BedroomSegmentConfigured(
            segment:     $segment,
            action:      BedroomSegmentConfigured::ACTION_ARCHIVED,
            actorUserId: Auth::id(),
            agencyId:    $segment->agency_id,
        ));

        return redirect()->route('settings.prospecting.index', ['tab' => 'bedroom-segments'])
            ->with('status', "Bedroom segment '{$segment->name}' archived.");
    }

    public function reorder(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        $validated = $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer',
        ]);

        DB::transaction(function () use ($validated, $agencyId) {
            foreach ($validated['order'] as $position => $id) {
                BedroomSegment::withoutGlobalScopes()
                    ->where('id', $id)
                    ->where('agency_id', $agencyId)
                    ->update(['display_order' => $position]);
            }
        });

        $first = BedroomSegment::withoutGlobalScopes()->where('agency_id', $agencyId)->first();
        if ($first) {
            event(new BedroomSegmentConfigured(
                segment:     $first,
                action:      BedroomSegmentConfigured::ACTION_UPDATED,
                actorUserId: Auth::id(),
                agencyId:    $agencyId,
            ));
        }

        return redirect()->route('settings.prospecting.index', ['tab' => 'bedroom-segments'])
            ->with('status', 'Segments reordered.');
    }

    /**
     * @return array{name:string, beds_min:int, beds_max:?int}
     */
    private function validateInput(Request $request): array
    {
        $validator = validator($request->all(), [
            'name'     => 'required|string|max:50',
            'beds_min' => 'required|integer|min:0|max:20',
            'beds_max' => 'nullable|integer|min:0|max:20',
        ]);

        $validator->after(function ($v) {
            $data = $v->getData();
            $min = $data['beds_min'] ?? null;
            $max = $data['beds_max'] ?? null;
            if ($min !== null && $max !== null && (int) $max < (int) $min) {
                $v->errors()->add('beds_max', 'Maximum beds must be greater than or equal to minimum.');
            }
        });

        $validated = $validator->validate();
        $validated['beds_min'] = (int) $validated['beds_min'];
        $validated['beds_max'] = isset($validated['beds_max']) && $validated['beds_max'] !== ''
            ? (int) $validated['beds_max']
            : null;

        return $validated;
    }

    private function resolveAgencyId(Request $request): int
    {
        $user = $request->user();
        $id = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);
        abort_if($id === null, 403, 'No agency context.');
        return (int) $id;
    }

    private function nextDisplayOrder(int $agencyId): int
    {
        return (int) (BedroomSegment::withoutGlobalScopes()->where('agency_id', $agencyId)->max('display_order') ?? 0) + 1;
    }

    private function authorizeAgency(Request $request, BedroomSegment $segment): void
    {
        if ($segment->agency_id !== $this->resolveAgencyId($request)) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
