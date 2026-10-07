<?php

namespace App\Services\Properties;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Thrown by OtherAgencyStockActionRules::assertAllowed(). A 403 that explains itself:
 * JSON callers (the page's own fetch() calls, the API) get the reason under both
 * `error` and `message`; a browser lands back on the property with the reason shown.
 *
 * Deliberately a RuntimeException, not an HttpException: bootstrap/app.php turns every
 * HttpException 403 into the bare "no access" page, which would swallow the reason.
 */
class OtherAgencyStockActionBlockedException extends \RuntimeException
{
    public function __construct(
        public readonly string $action,
        public readonly int $propertyId,
        string $reason,
    ) {
        parent::__construct($reason, 403);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json(['ok' => false, 'error' => $this->getMessage(), 'message' => $this->getMessage()], 403);
        }

        return redirect()
            ->route('corex.properties.show', $this->propertyId)
            ->with('error', $this->getMessage());
    }
}
