<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\ClientUser;
use App\Services\Rentals\RentalPortalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * .ai/specs/rental-portal-access.md §19 — the portal Documents area, shared by the tenant and the landlord controllers so
 * the two audiences can never drift: one list (search / sort / type + date filters / pagination) and one authorised file
 * route, both resolved by RentalPortalDocumentService from the person's OWN scope. Needs ResolvesPortalContact.
 */
trait ServesPortalDocuments
{
    protected function portalDocumentList(Request $request, string $role, string $fileRoute): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $request->validate([
            'q' => 'nullable|string|max:120',
            'type' => 'nullable|in:' . implode(',', array_keys(RentalPortalDocumentService::KINDS)),
            'from' => 'nullable|date', 'to' => 'nullable|date',
            'sort' => 'nullable|in:date,name,type', 'dir' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $service = app(RentalPortalDocumentService::class);

        return response()->json($service->query(
            $service->rowsFor($role, $contact),
            $request->only(['q', 'type', 'from', 'to', 'sort', 'dir', 'page', 'per_page']),
            $fileRoute,
        ));
    }

    /** One document, only if it is in this person's own list — anything else (another party's, a draft, an archived lease's) is a 404. */
    protected function portalDocumentFile(Request $request, string $role, int $document): Response|JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        /** @var ClientUser $client */
        $client = $request->user();
        $response = app(RentalPortalDocumentService::class)->respond($role, $contact, $client, $document, $request->boolean('download'), $request);

        return $response ?? response()->json(['message' => 'Document not found.'], 404);
    }
}
