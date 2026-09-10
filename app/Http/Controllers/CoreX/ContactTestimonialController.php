<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactTestimonial;
use Illuminate\Http\Request;

/**
 * Agent-facing capture of testimonials on a Contact's "Notes & Testimonials"
 * tab. Capturing never publishes — publishing happens in Company Settings →
 * Website (testimonials.publish). Gated by access_contacts via the route group.
 *
 * Field-resolution rules (resolveAgentId/resolveDisplayName/validateTestimonialInput)
 * live in the ResolvesTestimonialFields trait so the mobile API
 * (MobileContactNotesController) shares the exact same rules.
 *
 * Spec: .ai/specs/testimonials.md §6.1, §7, §9. .ai/specs/contact-notes-testimonials.md.
 */
class ContactTestimonialController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesContactAccess;
    use \App\Http\Controllers\Concerns\ResolvesTestimonialFields;

    public function store(Request $request, Contact $contact)
    {
        $this->authorizeContact($contact);
        $data = $this->validateTestimonialInput($request);

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
        $this->authorizeContact($contact);
        abort_unless($testimonial->contact_id === $contact->id, 404);

        $data = $this->validateTestimonialInput($request);

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
        $this->authorizeContact($contact);
        abort_unless($testimonial->contact_id === $contact->id, 404);

        // Soft delete; the observer fires testimonial.removed if it was published.
        $testimonial->delete();

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Testimonial deleted.')
            ->withFragment('tab-notes');
    }
}
