<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Shared testimonial validation + field-resolution rules, extracted from
 * ContactTestimonialController (web) so the mobile agent-facing API
 * (MobileContactNotesController) reuses the exact same business rules
 * instead of a second hand-copied implementation that can drift.
 *
 * Spec: .ai/specs/contact-notes-testimonials.md, .ai/specs/testimonials.md §6.1, §7, §9.
 */
trait ResolvesTestimonialFields
{
    protected function validateTestimonialInput(Request $request): array
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
    protected function resolveAgentId($entered, Contact $contact): ?int
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
    protected function resolveDisplayName(?string $entered, Contact $contact): string
    {
        $entered = trim((string) $entered);
        if ($entered !== '') {
            return Str::limit($entered, 150, '');
        }

        $full = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));

        return $full !== '' ? Str::limit($full, 150, '') : 'Client';
    }
}
