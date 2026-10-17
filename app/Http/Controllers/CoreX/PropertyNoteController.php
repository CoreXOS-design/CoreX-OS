<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyNote;
use Illuminate\Http\Request;

class PropertyNoteController extends Controller
{
    use AuthorizesPropertyAccess;

    public function store(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $request->validate(['content' => 'required|string|max:5000']);

        $property->notes()->create([
            'user_id' => auth()->id(),
            'content' => $request->content,
        ]);

        return $this->backToNotes('Note added.', $property);
    }

    public function destroy(Property $property, PropertyNote $note)
    {
        abort_unless((int) $note->property_id === $property->id, 404);
        abort_unless(
            auth()->id() === $note->user_id || in_array(auth()->user()?->effectiveRole(), ['super_admin', 'admin']),
            403
        );

        $note->delete();

        return $this->backToNotes('Note deleted.', $property);
    }

    /**
     * Land on the Notes tab of the property, whatever URL the agent came from.
     *
     * This used to be back()->with('tab', 'notes'). The property page picks its open tab as
     * `request('tab') ?? session('tab')`, so when the agent had arrived on a deep link such as
     * /corex/properties/6042?tab=contacts, back() returned to that same URL, the ?tab=contacts
     * in it beat the flashed 'notes', and the page reopened on Contacts: the note WAS saved but
     * the agent saw "Note added." on the wrong tab and no note — which reads as "Add Note does
     * nothing". An explicit ?tab=notes on the redirect wins over everything.
     */
    private function backToNotes(string $message, Property $property)
    {
        return redirect()
            ->route('corex.properties.show', ['property' => $property, 'tab' => 'notes'])
            ->with('success', $message)
            ->with('tab', 'notes');
    }
}
