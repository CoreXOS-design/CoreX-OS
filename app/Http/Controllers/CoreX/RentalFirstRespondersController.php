<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesContactAccess;
use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Property;
use App\Services\Rentals\FirstResponderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rentals-faults-work-orders.md section 21.3 - saves "Who looks at faults first" for a rental property, and the owner's default for all their
 * properties. The form posts the WHOLE list (up to 3 rows); {@see FirstResponderService::save()} validates, soft-deletes rows taken off, and logs the
 * change. Property writes are scoped like every other property write (authorizeProperty - OWN / BRANCH / AGENCY); the owner default like every contact write.
 */
class RentalFirstRespondersController extends Controller
{
    use AuthorizesContactAccess;
    use AuthorizesPropertyAccess;

    public function __construct(private readonly FirstResponderService $service)
    {
    }

    public function updateProperty(Request $request, Property $property): RedirectResponse
    {
        $this->authorizeProperty($property);
        abort_if(
            strtolower((string) $property->listing_type) !== 'rental' || $property->listing_type_pending,
            403,
            'This property is not a settled rental listing.'
        );

        $changed = $this->service->save($property, $this->rows($request), $request->user(), $request->input('agreed_with'));

        return redirect()->to(route('corex.properties.show', $property) . '?tab=rental#first-responders-panel')
            ->with('success', $changed ? 'Who looks at faults first: saved.' : 'Nothing changed - the list is as it was.');
    }

    public function updateOwner(Request $request, Contact $contact): RedirectResponse
    {
        $this->authorizeContact($contact);

        $changed = $this->service->save($contact, $this->rows($request), $request->user(), $request->input('agreed_with'));

        return redirect()->to(route('corex.contacts.show', $contact) . '?tab=properties#owner-first-responders-panel')
            ->with('success', $changed ? "This owner's default for faults: saved." : 'Nothing changed - the list is as it was.')
            // the contact page has no global flash area, so the owner panel shows this confirmation itself
            ->with('owner_first_responders_saved', true);
    }

    /** The posted rows in the order they were shown. @return array<int, array<string, mixed>> */
    private function rows(Request $request): array
    {
        $request->validate([
            'responders' => ['nullable', 'array', 'max:' . \App\Models\RentalFirstResponder::MAX_ENTRIES],
            'responders.*.supplier_id' => ['nullable', 'integer'],
            'responders.*.fault_scope' => ['nullable', 'in:all,types'],
            'responders.*.fault_type_ids' => ['nullable', 'array'],
            'responders.*.fault_type_ids.*' => ['integer'],
            'responders.*.tell_mode' => ['nullable', 'in:inspect,info_only'],
            'agreed_with' => ['nullable', 'string', 'max:255'],
        ], [
            'responders.max' => 'You can name at most ' . \App\Models\RentalFirstResponder::MAX_ENTRIES . ' people or companies.',
        ]);

        return array_values((array) $request->input('responders', []));
    }
}
