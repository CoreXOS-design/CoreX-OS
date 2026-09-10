<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\ContactTestimonial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile agent-facing CRUD for a Contact's Notes & Testimonials — the same
 * "Notes & Testimonials" tab data as corex/contacts/show.blade.php, so a
 * note/testimonial written here shows on the web (and vice versa) on next
 * load: same tables, same validation rules, same authorization trait.
 *
 * Authorization uses AuthorizesContactAccess (the web's own per-record
 * mutation guard) rather than MobileContactController's narrower
 * created_by_user_id-only check — a deliberate parity choice, see
 * .ai/specs/contact-notes-testimonials.md.
 *
 * Spec: .ai/specs/contact-notes-testimonials.md
 */
class MobileContactNotesController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesContactAccess;
    use \App\Http\Controllers\Concerns\ResolvesTestimonialFields;

    // ─── Notes ────────────────────────────────────────────────

    // GET /api/v1/mobile/contacts/{contact}/notes
    public function notesIndex(Request $request, Contact $contact): JsonResponse
    {
        abort_unless(Contact::whereKey($contact->getKey())->exists(), 403, 'That contact is outside your visibility scope.');

        $notes = $contact->contactNotes()->with('user:id,name')->get()->map(fn (ContactNote $n) => $this->shapeNote($n));

        return response()->json(['notes' => $notes]);
    }

    // POST /api/v1/mobile/contacts/{contact}/notes
    public function notesStore(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeContact($contact);

        $data = $request->validate([
            'type' => ['nullable', 'required_without:body', 'string', \Illuminate\Validation\Rule::in(ContactNote::QUICK_PICK_TYPES)],
            'body' => 'nullable|required_without:type|string|max:5000',
        ]);

        $note = $contact->contactNotes()->create([
            'user_id' => $request->user()->id,
            'type'    => $data['type'] ?? null,
            'body'    => $data['body'] ?? '',
        ]);

        $note->load('user:id,name');

        return response()->json(['note' => $this->shapeNote($note)], 201);
    }

    // PUT /api/v1/mobile/contacts/{contact}/notes/{note}
    public function notesUpdate(Request $request, Contact $contact, ContactNote $note): JsonResponse
    {
        $this->authorizeContact($contact);
        abort_unless($note->contact_id === $contact->id, 404);

        $data = $request->validate([
            'type' => ['nullable', 'required_without:body', 'string', \Illuminate\Validation\Rule::in(ContactNote::QUICK_PICK_TYPES)],
            'body' => 'nullable|required_without:type|string|max:5000',
        ]);

        // array_key_exists (not ?? null) — a mobile payload that only sends
        // `body` must not silently clear an existing quick-pick `type`, same
        // fix as the web ContactNoteController::update().
        $note->update([
            'type' => array_key_exists('type', $data) ? $data['type'] : $note->type,
            'body' => $data['body'] ?? '',
        ]);

        $note->load('user:id,name');

        return response()->json(['note' => $this->shapeNote($note)]);
    }

    // DELETE /api/v1/mobile/contacts/{contact}/notes/{note}
    public function notesDestroy(Request $request, Contact $contact, ContactNote $note): JsonResponse
    {
        $this->authorizeContact($contact);
        abort_unless($note->contact_id === $contact->id, 404);

        $note->delete();

        return response()->json(['ok' => true]);
    }

    // ─── Testimonials ─────────────────────────────────────────

    // GET /api/v1/mobile/contacts/{contact}/testimonials
    public function testimonialsIndex(Request $request, Contact $contact): JsonResponse
    {
        abort_unless(Contact::whereKey($contact->getKey())->exists(), 403, 'That contact is outside your visibility scope.');

        $testimonials = $contact->testimonials()->with(['user:id,name', 'agent:id,name'])->get()
            ->map(fn (ContactTestimonial $t) => $this->shapeTestimonial($t));

        return response()->json(['testimonials' => $testimonials]);
    }

    // POST /api/v1/mobile/contacts/{contact}/testimonials
    public function testimonialsStore(Request $request, Contact $contact): JsonResponse
    {
        $this->authorizeContact($contact);
        $data = $this->validateTestimonialInput($request);

        $testimonial = $contact->testimonials()->create([
            'user_id'      => $request->user()->id,
            'agent_id'     => $this->resolveAgentId($data['agent_id'] ?? null, $contact),
            'body'         => $data['body'],
            'display_name' => $this->resolveDisplayName($data['display_name'] ?? null, $contact),
            'rating'       => $data['rating'] ?? null,
            // Capture never publishes — Company Settings → Website does (prevent-or-absorb).
            'published'    => false,
        ]);

        $testimonial->load(['user:id,name', 'agent:id,name']);

        return response()->json(['testimonial' => $this->shapeTestimonial($testimonial)], 201);
    }

    // PUT /api/v1/mobile/contacts/{contact}/testimonials/{testimonial}
    public function testimonialsUpdate(Request $request, Contact $contact, ContactTestimonial $testimonial): JsonResponse
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

        $testimonial->load(['user:id,name', 'agent:id,name']);

        return response()->json(['testimonial' => $this->shapeTestimonial($testimonial)]);
    }

    // DELETE /api/v1/mobile/contacts/{contact}/testimonials/{testimonial}
    public function testimonialsDestroy(Request $request, Contact $contact, ContactTestimonial $testimonial): JsonResponse
    {
        $this->authorizeContact($contact);
        abort_unless($testimonial->contact_id === $contact->id, 404);

        // Soft delete; the observer fires testimonial.removed if it was published.
        $testimonial->delete();

        return response()->json(['ok' => true]);
    }

    // ─── Shaping ──────────────────────────────────────────────

    private function shapeNote(ContactNote $note): array
    {
        return [
            'id'         => $note->id,
            'contact_id' => $note->contact_id,
            'type'       => $note->type,
            'body'       => $note->body,
            'user_id'    => $note->user_id,
            'user_name'  => $note->user?->name,
            'created_at' => $note->created_at?->toIso8601String(),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    private function shapeTestimonial(ContactTestimonial $testimonial): array
    {
        return [
            'id'           => $testimonial->id,
            'contact_id'   => $testimonial->contact_id,
            'body'         => $testimonial->body,
            'display_name' => $testimonial->display_name,
            'rating'       => $testimonial->rating,
            'agent_id'     => $testimonial->agent_id,
            'agent_name'   => $testimonial->agent?->name,
            'user_id'      => $testimonial->user_id,
            'user_name'    => $testimonial->user?->name,
            'published'    => (bool) $testimonial->published,
            'created_at'   => $testimonial->created_at?->toIso8601String(),
            'updated_at'   => $testimonial->updated_at?->toIso8601String(),
        ];
    }
}
