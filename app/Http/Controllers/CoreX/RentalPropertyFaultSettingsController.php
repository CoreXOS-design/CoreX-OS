<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\RentalPropertyFaultSetting as Row;
use App\Services\Rentals\RentalOwnerFaultSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.5 - the agent side of "How the owner wants faults handled" on a rental property: capture the
 * owner's answers FOR them (evidence required), and "Send to owner to confirm". Same permission and property scope as the work terms.
 */
class RentalPropertyFaultSettingsController extends Controller
{
    use AuthorizesPropertyAccess;

    public function __construct(private RentalOwnerFaultSettingsService $service)
    {
    }

    public function capture(Request $request, Property $property): RedirectResponse
    {
        $this->authorizeRentalProperty($property);

        $data = $request->validate([
            'repair_limit' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'emergency_limit' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'callout_mode' => ['required', 'in:yes,no'],
            'callout_limit' => ['nullable', 'required_if:callout_mode,yes', 'numeric', 'gt:0', 'max:99999999.99'],
            'basis' => ['required', 'in:' . implode(',', array_keys(Row::BASES))],
            'occurred_at' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['required', 'string', 'min:3', 'max:2000'],
            'evidence' => ['nullable', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,doc,docx,txt,mp3,m4a,eml'],
        ], [
            'repair_limit.required' => 'Enter the amount for repairs (R0 if the owner wants to be asked every time).',
            'emergency_limit.required' => 'Enter the amount for emergency repairs (R0 if the owner wants to be asked every time).',
            'callout_mode.required' => 'Say whether the owner allows a contractor who charges a call-out fee.',
            'callout_limit.required_if' => 'Enter the call-out amount the owner allows, or choose No.',
            'basis.required' => 'Say how the owner told us.',
            'occurred_at.required' => 'Say when the owner told us.',
            'occurred_at.before_or_equal' => 'The date the owner told us cannot be in the future.',
            'note.required' => 'A note is required - say what the owner said, in a sentence.',
        ]);

        $this->service->capture($property, $request->user(), $data, $data['basis'], \Illuminate\Support\Carbon::parse($data['occurred_at']), $data['note'], $request->file('evidence'));

        return redirect()->to(route('corex.properties.show', $property) . '?tab=rental#owner-fault-settings-panel')
            ->with('success', 'Recorded for the owner. The owner has been emailed what you recorded, and can confirm it, change it, or tell us they do not agree.');
    }

    public function send(Request $request, Property $property): RedirectResponse
    {
        $this->authorizeRentalProperty($property);
        $row = $this->service->sendToOwner($property, $request->user());

        return redirect()->to(route('corex.properties.show', $property) . '?tab=rental#owner-fault-settings-panel')
            ->with($row ? 'success' : 'error', $row ? 'Sent to the owner to confirm.' : 'Nothing to send - there is nothing recorded yet, or the owner has already confirmed it.');
    }

    public function evidence(Request $request, Property $property): StreamedResponse
    {
        $this->authorizeProperty($property);
        $row = Row::withoutGlobalScopes()->where('property_id', $property->id)->first();
        abort_unless($row && $row->capture_file_path && Storage::disk('local')->exists($row->capture_file_path), 404);

        return Storage::disk('local')->download($row->capture_file_path, $row->capture_file_name ?: 'evidence');
    }

    private function authorizeRentalProperty(Property $property): void
    {
        $this->authorizeProperty($property);
        abort_if(strtolower((string) $property->listing_type) !== 'rental' || $property->listing_type_pending, 403, 'This property is not a settled rental listing.');
    }
}
