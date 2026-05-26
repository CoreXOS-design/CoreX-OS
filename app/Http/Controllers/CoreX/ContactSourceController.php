<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ContactSource;
use Illuminate\Http\Request;

class ContactSourceController extends Controller
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

        ContactSource::create($data);

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact source added.');
    }

    public function update(Request $request, ContactSource $contactSource)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'color'      => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $contactSource->update($data);

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact source updated.');
    }

    public function destroy(ContactSource $contactSource)
    {
        $contactSource->contacts()->update(['contact_source_id' => null]);

        $contactSource->delete();

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact source deleted.');
    }
}
