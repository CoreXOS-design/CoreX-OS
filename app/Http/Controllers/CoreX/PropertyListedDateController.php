<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;

/**
 * Correct a property's Listed Date. The date is otherwise stamped by CoreX when the
 * advert goes live on the portals, so a manual change must always say why: the reason
 * is written to the property's notes and the change is audited.
 * Spec: .ai/specs/property-listed-date-correction.md
 */
class PropertyListedDateController extends Controller
{
    use AuthorizesPropertyAccess;

    public function update(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $data = $request->validate([
            'listed_date' => 'required|date|before_or_equal:today',
            'reason'      => 'required|string|min:5|max:2000',
        ], [
            'reason.required' => 'Please give a reason for changing the listed date.',
            'reason.min'      => 'Please give a reason for changing the listed date.',
        ]);

        $old = $property->listed_date?->toDateString();
        $new = \Illuminate\Support\Carbon::parse($data['listed_date'])->toDateString();

        if ($old === $new) {
            return back()->with('error', 'The listed date is already ' . $new . '.')->with('tab', 'notes');
        }

        $reason = trim($data['reason']);
        $fmt    = static fn (?string $d): string => $d ? \Illuminate\Support\Carbon::parse($d)->format('j M Y') : 'not set';

        \Illuminate\Support\Facades\DB::transaction(function () use ($property, $old, $new, $reason, $fmt) {
            $property->auditedQuietUpdate(
                ['listed_date' => $new],
                'listed_date_corrected',
                'Listed date changed from ' . $fmt($old) . ' to ' . $fmt($new) . ' — ' . $reason,
                ['reason' => $reason],
                auth()->user(),
            );

            $property->notes()->create([
                'user_id' => auth()->id(),
                'content' => 'Listed date changed from ' . $fmt($old) . ' to ' . $fmt($new) . '. Reason: ' . $reason,
            ]);
        });

        return back()->with('success', 'Listed date updated and the reason recorded in the notes.')->with('tab', 'notes');
    }
}
