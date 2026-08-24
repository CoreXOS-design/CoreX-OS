<?php

namespace App\Exceptions\AI;

use RuntimeException;

/**
 * Thrown by EllieReferenceSourceFetchService when a URL — or a redirect it
 * points at — fails an SSRF guard: bad scheme, resolves to a private/loopback/
 * reserved address, wrong content type, oversized response, or too many
 * redirects. Caught by the caller and recorded as a fetch error on the
 * source row; never bubbles up as a 500.
 *
 * Spec: .ai/specs/ellie-reference-sources.md §6.
 */
class SsrfBlockedException extends RuntimeException
{
}
