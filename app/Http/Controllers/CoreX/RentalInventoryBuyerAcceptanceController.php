<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Mail\Distribution\SignedDocumentDistributionMail;
use App\Models\Contact;
use App\Models\RentalInventory;
use App\Services\Distribution\SignedDocumentDistributionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * §24 ruling (Johan, 2026-09-29) — the AGENT-side half of buyer acceptance:
 * sending an eligible buyer their own signing link. Deliberately its own
 * controller, separate from RentalInventoryRecordingController — this
 * feature never calls RentalInventory::assertEditable() anywhere, and
 * keeping it out of the completion-gated controller makes that boundary
 * structurally visible, not just a convention to remember. The buyer's own
 * CAPTURE half (signing/uploading) lives on RentalInventoryPublicController
 * — the buyer has no CoreX login, same as every other public inventory
 * surface.
 */
class RentalInventoryBuyerAcceptanceController extends Controller
{
    /**
     * POST /corex/rental-inventories/{inventory}/buyer-acceptances/{contact}/send
     * — emails the buyer their own signing link (the inventory's existing
     * public share link, generated if none is live yet — same "a printed/
     * shared link never 404s" contract RentalInventory::report() already
     * uses). Refuses outright unless buyerAcceptanceOfferedFor() is true
     * and $contact is genuinely one of the property's committed-deal
     * buyers — no partial trust in whatever the client sends.
     */
    public function send(
        Request $request,
        RentalInventory $rentalInventory,
        Contact $contact,
        SignedDocumentDistributionService $distributionService,
    ): JsonResponse {
        if (! $rentalInventory->buyerAcceptanceOfferedFor()) {
            return response()->json(['message' => 'Buyer acceptance is not offered on this inventory.'], 409);
        }

        $isEligible = $rentalInventory->eligibleBuyerContacts()->contains(fn (Contact $c) => (int) $c->id === (int) $contact->id);
        if (! $isEligible) {
            return response()->json(['message' => 'This contact is not a buyer on the property\'s current committed deal.'], 422);
        }

        if (empty($contact->email)) {
            return response()->json(['message' => 'This buyer has no email address on file.'], 422);
        }

        if (! $rentalInventory->hasValidPublicLink()) {
            $rentalInventory->generatePublicLink();
        }

        $agent = $request->user();
        $mail = new SignedDocumentDistributionMail(
            recipientName: $contact->full_name,
            documentLabel: 'Inventory report — please review and sign to accept',
            propertyAddress: $rentalInventory->property?->buildDisplayAddress() ?? '',
            emailSubject: 'Please review and sign to accept — ' . ($rentalInventory->property?->buildDisplayAddress() ?? 'inventory report'),
            publicUrl: $rentalInventory->publicShareUrl(),
        );

        $result = $distributionService->sendGenericMail($contact->email, $mail, $agent);

        return response()->json($result, $result['status'] === 'sent' ? 200 : 422);
    }
}
