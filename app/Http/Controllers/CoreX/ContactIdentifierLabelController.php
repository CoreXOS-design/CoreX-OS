<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ContactIdentifierLabel;
use Illuminate\Http\Request;

class ContactIdentifierLabelController extends Controller
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

        ContactIdentifierLabel::create($data);

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact label added.');
    }

    public function update(Request $request, ContactIdentifierLabel $contactIdentifierLabel)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'color'      => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $contactIdentifierLabel->update($data);

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact label updated.');
    }

    public function destroy(ContactIdentifierLabel $contactIdentifierLabel)
    {
        // A tel/email whose label is deleted just loses the tag (nullOnDelete
        // FK) — the number/address itself is never touched.
        $contactIdentifierLabel->phones()->update(['contact_identifier_label_id' => null]);
        $contactIdentifierLabel->emails()->update(['contact_identifier_label_id' => null]);

        $contactIdentifierLabel->delete();

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact label deleted.');
    }
}
