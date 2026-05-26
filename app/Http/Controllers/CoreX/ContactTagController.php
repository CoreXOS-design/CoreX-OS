<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ContactTag;
use Illuminate\Http\Request;

class ContactTagController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'color'      => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $data['color']      = $data['color'] ?? '#6366f1';
        $data['sort_order'] = $data['sort_order'] ?? 0;

        ContactTag::create($data);

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact tag added.');
    }

    public function update(Request $request, ContactTag $contactTag)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'color'      => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $contactTag->update($data);

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact tag updated.');
    }

    public function destroy(ContactTag $contactTag)
    {
        $contactTag->contacts()->detach();

        $contactTag->delete();

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact tag deleted.');
    }
}
