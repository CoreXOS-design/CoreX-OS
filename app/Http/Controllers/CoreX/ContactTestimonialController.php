<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactTestimonial;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Agent-facing capture of testimonials on a Contact's "Notes & Testimonials"
 * tab. Capturing never publishes — publishing happens in Company Settings →
 * Website (testimonials.publish). Gated by access_contacts via the route group.
 *
 * Spec: .ai/specs/testimonials.md §6.1, §7, §9.
 */
class ContactTestimonialController extends Controller
{
    public function store(Request $request, Contact $contact)
    {
        $data = $this->validateInput($request);

        $contact->testimonials()->create([
            'user_id'      => auth()->id(),
            'agent_id'     => $this->resolveAgentId($data['agent_id'] ?? null, $contact),
            'body'         => $data['body'],
            'display_name' => $this->resolveDisplayName($data['display_name'] ?? null, $contact),
            'rating'       => $data['rating'] ?? null,
            // Capture never publishes — Settings does (prevent-or-absorb).
            'published'    => false,
        ]);

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Testimonial added.')
            ->withFragment('tab-notes');
    }

    public function update(Request $request, Contact $contact, ContactTestimonial $testimonial)
    {
        abort_unless($testimonial->contact_id === $contact->id, 404);

        $data = $this->validateInput($request);

        $testimonial->update([
            'agent_id'     => $this->resolveAgentId($data['agent_id'] ?? null, $contact),
            'body'         => $data['body'],
            'display_name' => $this->resolveDisplayName($data['display_name'] ?? null, $contact),
            'rating'       => $data['rating'] ?? null,
        ]);

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Testimonial updated.')
            ->withFragment('tab-notes');
    }

    public function destroy(Contact $contact, ContactTestimonial $testimonial)
    {
        abort_unless($testimonial->contact_id === $contact->id, 404);

        // Soft delete; the observer fires testimonial.removed if it was published.
        $testimonial->delete();

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Testimonial deleted.')
            ->withFragment('tab-notes');
    }

    private function validateInput(Request $request): array
    {
        return $request->validate([
            'body'         => ['required', 'string', 'max:5000'],
            'display_name' => ['nullable', 'string', 'max:150'],
            'rating'       => ['nullable', 'integer', 'min:1', 'max:5'],
            'agent_id'     => ['nullable', 'integer'],
        ]);
    }

    /**
     * The agent the testimonial is about. Defaults to the capturing user; a
     * chosen agent is honoured only if they belong to the contact's agency
     * (prevent cross-tenant tagging). Absorbs an invalid id by falling back.
     */
    private function resolveAgentId($entered, Contact $contact): ?int
    {
        $entered = $entered !== null && $entered !== '' ? (int) $entered : null;

        if ($entered !== null) {
            $valid = User::withoutGlobalScope(AgencyScope::class)
                ->where('id', $entered)
                ->where('agency_id', $contact->agency_id)
                ->exists();
            if ($valid) {
                return $entered;
            }
        }

        // Fall back to the capturing user (the common case: the agent records
        // their own testimonial). Null only if there is no authenticated user.
        return auth()->id();
    }

    /**
     * NOT-NULL display_name is always supplied. Trim the entered name; if empty,
     * fall back to the contact's full name; if that is empty too, "Client".
     */
    private function resolveDisplayName(?string $entered, Contact $contact): string
    {
        $entered = trim((string) $entered);
        if ($entered !== '') {
            return Str::limit($entered, 150, '');
        }

        $full = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));

        return $full !== '' ? Str::limit($full, 150, '') : 'Client';
    }
}
