<?php

namespace App\Services\Syndication;

use App\Models\Property;
use Illuminate\Http\Request;

/**
 * Thrown when a user tries to publish/activate/submit a property to ANY portal
 * or website while it is off-market (Property::OFF_MARKET_STATUSES — draft,
 * prospecting, withdrawn, archived, cancelled, etc.). None of those are ready
 * for market, so the listing must be set to Active before it can be
 * syndicated anywhere (P24, Private Property, or an agency website).
 *
 * Class name/error code kept as-is (2026-08-21) even though the trigger
 * broadened beyond literal drafts -- EnforcesMarketingReadiness::
 * enforceListingNotDraft() is still the one and only guard this exception
 * belongs to; renaming would touch every call site for no behavioural gain.
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
        if ($this->property->isDraft()) {
            return "This property is still a draft — set its status to Active before publishing it to {$this->portal}.";
        }
        if ($this->property->isProspecting()) {
            return "This property is still in Prospecting — win the mandate and move it to Draft (then Active) before publishing it to {$this->portal}.";
        }

        $label = $this->property->statusBadge();

        return "This property's status is \"{$label}\" — it must be set to Active before publishing it to {$this->portal}.";
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
