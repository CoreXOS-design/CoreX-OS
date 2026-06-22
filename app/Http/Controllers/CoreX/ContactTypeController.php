<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\ContactType;
use Illuminate\Http\Request;

/**
 * Contact types are LOCKED to the four fixed e-sign parents
 * (Seller/Buyer/Lessor/Lessee) — AT-79. There is no add/rename/delete of a
 * parent type; that guarantees the 1:1 esign_role mapping the signing wizard
 * relies on. Custom categorisation happens via sub-tags (ContactTagController),
 * nested under these parents. These endpoints stay registered but reject writes
 * so any stale form or tampered POST is a no-op rather than a silent mutation.
 */
class ContactTypeController extends Controller
{
    private function locked()
    {
        return redirect()
            ->route('corex.settings', ['tab' => 'feature', 'fsec' => 'contacts'])
            ->with('error', 'Contact types are fixed to the four signing roles (Seller, Buyer, Lessor, Lessee). Add custom sub-tags under a role instead.');
    }

    public function store(Request $request)
    {
        return $this->locked();
    }

    public function update(Request $request, ContactType $contactType)
    {
        return $this->locked();
    }

    public function destroy(ContactType $contactType)
    {
        return $this->locked();
    }
}
