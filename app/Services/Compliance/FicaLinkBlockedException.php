<?php

namespace App\Services\Compliance;

use App\Models\Contact;
use Illuminate\Http\Request;

/**
 * Thrown when an agent tries to link a contact to a property in a seller-side
 * role (owner/seller/landlord/lessor) before that contact has passed REAL
 * FICA — see FicaSubmission::applyGenuineApprovalFilter(). Post-incident
 * policy (property "30 Captain Smith" / Kym Pollard, 2026-08-05): a stale,
 * soft-deleted, or bulk-import-auto-approved FICA record never counts.
 *
 * Mirrors MarketingBlockedException / DraftListingException (Laravel 11
 * renderable exception pattern) — JSON 422 for AJAX callers, flash redirect
 * for plain form posts.
 */
class FicaLinkBlockedException extends \Exception
{
    public function __construct(private Contact $contact)
    {
        parent::__construct('Contact is not FICA-approved — cannot link as seller/owner/landlord/lessor.');
    }

    public function userMessage(): string
    {
        $name = trim(($this->contact->first_name ?? '') . ' ' . ($this->contact->last_name ?? '')) ?: 'This contact';

        return "{$name} must pass FICA verification before being linked to a property as seller/owner/landlord/lessor. Submit and approve their FICA first.";
    }

    public function render(Request $request)
    {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'ok'              => false,
                'error'           => 'fica_not_approved',
                'message'         => $this->userMessage(),
                'contact_id'      => $this->contact->id,
                'fica_action_url' => route('compliance.fica.create') . '?contact_id=' . $this->contact->id,
            ], 422);
        }

        return redirect()->back()
            ->withErrors(['contact_id' => $this->userMessage()])
            ->with('error', $this->userMessage())
            ->with('tab', 'contacts');
    }
}
