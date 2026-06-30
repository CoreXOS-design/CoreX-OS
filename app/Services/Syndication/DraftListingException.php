<?php

namespace App\Services\Syndication;

use App\Models\Property;
use Illuminate\Http\Request;

/**
 * Thrown when a user tries to publish/activate/submit a property to ANY portal
 * or website while it is still a draft. A draft has not been finalised for
 * market, so it must be set to Active before it can be syndicated anywhere
 * (P24, Private Property, or an agency website).
 *
 * Renderable (Laravel 11 pattern): returns a 422 with a clear, actionable
 * message for the syndication panel's Alpine error surface, and a flash redirect
 * for non-JSON requests. Mirrors MarketingBlockedException.
 */
class DraftListingException extends \Exception
{
    public function __construct(
        private Property $property,
        private string $portal = 'any website or portal',
    ) {
        parent::__construct('Property is still a draft and cannot be syndicated.');
    }

    public function userMessage(): string
    {
        return "This property is still a draft — set its status to Active before publishing it to {$this->portal}.";
    }

    public function render(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'success'        => false,
                'error'          => 'listing_draft',
                'message'        => $this->userMessage(),
                'property_status' => $this->property->status,
            ], 422);
        }

        return redirect()->back()->with('error', $this->userMessage());
    }
}
