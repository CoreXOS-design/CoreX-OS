<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Http\Controllers\Controller;
use App\Services\Rentals\RentalFaultTypePortalView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * .ai/specs/rental-portal-access.md §22 — a PDF / document the agency attached to a fault type's first-aid content, opened
 * from the tenant's or owner's "before you report" panel. Gated: only the signed-in person's OWN agency's active fault
 * types (the catalogue content the agency chose to show tenants); never a raw storage URL.
 */
class ClientFaultTypeDocumentController extends Controller
{
    use ResolvesPortalContact;

    public function file(Request $request, int $document, RentalFaultTypePortalView $view): Response|JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $doc = $view->privateDocumentFor((int) $contact->agency_id, $document);
        if (!$doc) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return Storage::disk('local')->response($doc->storage_path);
    }
}
