<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\RentalInventory;
use Illuminate\Http\Request;

/**
 * A completed inventory is a signed legal record; a cancelled one is
 * closed. Neither may be edited. Thrown by RentalInventory::assertEditable()
 * (called at the top of every capture-write endpoint — lines, dispositions,
 * room marks, photos, signatures, copy-from-last, complete) the moment the
 * inventory's own status is no longer 'draft'. Renderable (Laravel 11
 * pattern, mirrors DraftListingException): a plain 409 JSON message, never a
 * generic 500 or a silently-accepted write.
 */
final class RentalInventoryNotEditableException extends \LogicException
{
    public function __construct(public readonly RentalInventory $inventory)
    {
        parent::__construct(self::messageFor($inventory));
    }

    private static function messageFor(RentalInventory $inventory): string
    {
        return match ($inventory->status) {
            RentalInventory::STATUS_COMPLETED => 'This inventory is completed — it is a signed record and can no longer be edited.',
            RentalInventory::STATUS_CANCELLED => 'This inventory is cancelled and can no longer be edited.',
            default => "This inventory is {$inventory->status} and can no longer be edited.",
        };
    }

    public function render(Request $request)
    {
        return response()->json([
            'message' => $this->getMessage(),
            'status' => $this->inventory->status,
        ], 409);
    }
}
