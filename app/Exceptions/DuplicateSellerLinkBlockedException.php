<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Edinburgh erf 364 remediation (Johan, 2026-08-24).
 *
 * ComposeSellerService::linkSellerToProperty() used to write a
 * contact_property seller link with a bare updateOrInsert and no query
 * against existing links at all — a contact already linked to an ACTIVE,
 * on-market property could be silently linked to a second, brand-new
 * property too, with nothing on screen or in a log to say that had
 * happened. Three signals were available at creation time (the active
 * advertised property, an existing contact link, an agreeing CMA scrape)
 * and only one was checked, and only informationally.
 *
 * Johan's settled model: a CERTAIN match (same contact, a different
 * property that is currently on-market) is a hard block, not a warning.
 * This is thrown by linkSellerToProperty() the moment that shape is
 * detected, UNLESS a branch_manager/admin explicitly overrides it via
 * PropertyDuplicateBlockGuard — see that class and the override log this
 * exception's presence implies was skipped.
 */
final class DuplicateSellerLinkBlockedException extends RuntimeException
{
    public function __construct(
        public readonly int $contactId,
        public readonly int $conflictingPropertyId,
        public readonly string $conflictingPropertyAddress,
        public readonly string $conflictingPropertyStatus,
    ) {
        parent::__construct(sprintf(
            'Contact %d already has an active seller link to property %d (%s, status=%s) — refusing to link a second property without an explicit override.',
            $contactId,
            $conflictingPropertyId,
            $conflictingPropertyAddress,
            $conflictingPropertyStatus,
        ));
    }

    /** Plain language, and it names the way forward — never a stack trace (BUILD_STANDARD §4). */
    public function userMessage(): string
    {
        return sprintf(
            'This seller is already linked to %s, which is currently on the market (%s). '
            . 'Linking them to a second property here would create duplicate stock. '
            . 'A branch manager or admin can override this if it\'s genuinely a different property.',
            $this->conflictingPropertyAddress ?: ('property #' . $this->conflictingPropertyId),
            $this->conflictingPropertyStatus,
        );
    }

    /**
     * Rendered centrally so every caller (this compose screen today, any
     * future one) is covered by one rule rather than each remembering to
     * catch it.
     */
    public function render(\Illuminate\Http\Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok'                       => false,
                'error'                    => $this->userMessage(),
                'reason'                   => 'duplicate_seller_link_blocked',
                'conflicting_property_id'  => $this->conflictingPropertyId,
            ], 409);
        }

        return back()->withInput()->with('error', $this->userMessage());
    }
}
