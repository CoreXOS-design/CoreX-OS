<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ContactType;
use Illuminate\Http\Request;

class ContactTypeController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'color'      => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer|min:0',
            'esign_role' => 'nullable|string|in:seller,buyer,lessor,lessee',
        ]);

        $data['color']      = $data['color'] ?? '#6366f1';
        $data['sort_order'] = $data['sort_order'] ?? 0;

        ContactType::create($data);

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact type added.');
    }

    public function update(Request $request, ContactType $contactType)
    {
        $data = $request->validate([
            'name'       => 'required|string|max:100',
            'color'      => 'nullable|string|max:7',
            'sort_order' => 'nullable|integer|min:0',
            'esign_role' => 'nullable|string|in:seller,buyer,lessor,lessee',
        ]);

        $contactType->update($data);

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact type updated.');
    }

    public function destroy(ContactType $contactType)
    {
        if ($contactType->contacts()->count() > 0) {
            return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])
                ->with('error', 'Cannot delete — contacts are using this type.');
        }

        $contactType->delete();

        return redirect()->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])->with('success', 'Contact type deleted.');
    }
}
