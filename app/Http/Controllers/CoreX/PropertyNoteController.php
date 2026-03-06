<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyNote;
use Illuminate\Http\Request;

class PropertyNoteController extends Controller
{
    public function store(Request $request, Property $property)
    {
        $request->validate(['content' => 'required|string|max:5000']);

        $property->notes()->create([
            'user_id' => auth()->id(),
            'content' => $request->content,
        ]);

        return back()->with('success', 'Note added.')->with('tab', 'notes');
    }

    public function destroy(Property $property, PropertyNote $note)
    {
        abort_unless((int) $note->property_id === $property->id, 404);
        abort_unless(
            auth()->id() === $note->user_id || in_array(auth()->user()->effectiveRole(), ['super_admin', 'admin']),
            403
        );

        $note->delete();

        return back()->with('success', 'Note deleted.')->with('tab', 'notes');
    }
}
