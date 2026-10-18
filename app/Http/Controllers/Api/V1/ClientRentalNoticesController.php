<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Http\Controllers\Controller;
use App\Services\Rentals\LeaseNoticeService;
use App\Services\Rentals\RentalPortalNoticeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/lease-notices.md - the portal's Notices tab, for the tenant and for the owner (one controller, the side comes from the
 * route). Giving notice tells the agents and nobody else; a person sees only the notices their own side gave.
 */
class ClientRentalNoticesController extends Controller
{
    use ResolvesPortalContact;

    public function __construct(private readonly RentalPortalNoticeService $notices)
    {
    }

    public function tenantIndex(Request $request): JsonResponse
    {
        return $this->index($request, RentalPortalNoticeService::ROLE_TENANT);
    }

    public function tenantStore(Request $request): JsonResponse
    {
        return $this->store($request, RentalPortalNoticeService::ROLE_TENANT);
    }

    public function landlordIndex(Request $request): JsonResponse
    {
        return $this->index($request, RentalPortalNoticeService::ROLE_LANDLORD);
    }

    public function landlordStore(Request $request): JsonResponse
    {
        return $this->store($request, RentalPortalNoticeService::ROLE_LANDLORD);
    }

    private function index(Request $request, string $role): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json($this->notices->overview($role, $contact));
    }

    private function store(Request $request, string $role): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $data = $request->validate([
            'lease_id' => ['required', 'integer'],
            'notice_date' => ['required', 'date', 'before_or_equal:today'],
            'intended_end_date' => ['required', 'date', 'after:notice_date', 'before_or_equal:' . now()->addYears(2)->toDateString()],
            'reason' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:' . LeaseNoticeService::ATTACHMENT_MAX_KB, 'mimes:' . LeaseNoticeService::ATTACHMENT_MIMES],
        ], [
            'notice_date.before_or_equal' => 'The date notice was given cannot be in the future.',
            'intended_end_date.after' => 'The move-out date must be after the date you gave notice.',
            'intended_end_date.before_or_equal' => 'The move-out date is too far away.',
        ]);

        try {
            $this->notices->give($role, $contact, $data, $request->file('attachment'));
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }

        return response()->json($this->notices->overview($role, $contact) + ['message' => 'Your notice has been sent to your agent. Nothing goes to anyone else until they have checked it.'], 201);
    }
}
