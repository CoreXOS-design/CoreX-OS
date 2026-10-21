<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Audit L3 - an iPhone HEIC photo this server has no decoder for cannot be shown anywhere (the picture route serves only JPEG/PNG/WebP/GIF), so
 * the upload is refused up front with a plain message rather than stored as a file that would always be a broken tile. Rendered as a 422 JSON
 * answer (`code: unreadable_image`) for the capture screens and the mobile app, which both call these endpoints with fetch.
 */
class UnreadableImageException extends \RuntimeException
{
    public function __construct(string $message = '')
    {
        parent::__construct($message !== '' ? $message : "That photo is in a format this server can't read (an iPhone HEIC photo). Set the camera to \"Most Compatible\" (JPEG), or share the photo as a JPEG, and upload it again.");
    }

    public function render(Request $request): SymfonyResponse
    {
        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return response()->json(['message' => $this->getMessage(), 'code' => 'unreadable_image', 'errors' => ['photo' => [$this->getMessage()]]], 422);
        }

        return redirect()->back()->withErrors(['photo' => $this->getMessage()]);
    }
}
