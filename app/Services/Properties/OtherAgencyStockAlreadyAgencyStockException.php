<?php

namespace App\Services\Properties;

use Illuminate\Http\JsonResponse;

/**
 * .ai/specs/other-agency-stock.md §5e — thrown by OtherAgencyStockImportService::import() when the
 * portal listing being imported is already on CoreX as a property that is NO LONGER Other Agency
 * Stock (an authorised user unlocked it and moved its status away, so it is the agency's own stock).
 * Re-importing must not flip it back to Other Agency Stock or overwrite it (Johan, 2026-10-08).
 *
 * Rendered as a 409 the extension reads: {success:false, code, message, property_id, url}.
 */
class OtherAgencyStockAlreadyAgencyStockException extends \RuntimeException
{
    public const CODE = 'already_agency_stock';

    public const MESSAGE = 'This property is already on CoreX as agency stock, so it can\'t be imported again as Other Agency Stock. Open it in CoreX instead.';

    public function __construct(public readonly int $propertyId)
    {
        parent::__construct(self::MESSAGE, 409);
    }

    public function url(): string
    {
        return url('/corex/properties/' . $this->propertyId);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success'     => false,
            'code'        => self::CODE,
            'message'     => $this->getMessage(),
            'property_id' => $this->propertyId,
            'url'         => $this->url(),
        ], 409);
    }
}
