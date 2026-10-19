<?php

namespace App\Http\Controllers;

use App\Services\Images\PrivateImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security audit D14 - the ONE door to a private or portal-facing picture. No login: the signed, expiring, encrypted token in the
 * address is the capability (see {@see PrivateImageService}). Every way it can fail - wrong or missing signature, expired, tampered
 * token, archived or removed parent row, file gone, not an image - gives the SAME empty 404, so nothing can be learned by probing it.
 */
class PrivateImageController extends Controller
{
    public function show(Request $request, string $token, PrivateImageService $images): Response
    {
        // The signature check lives here (not in the `signed` middleware) on purpose: that middleware answers 403, and a 403 next to a 404
        // would tell a prober which half of the address was wrong.
        if (! URL::hasValidSignature($request)) {
            return $this->notFound();
        }

        $found = $images->resolve($token);
        if ($found === null) {
            return $this->notFound();
        }

        // $public = false: BinaryFileResponse would otherwise stamp the answer `Cache-Control: public`.
        $response = new BinaryFileResponse($found['path'], 200, [
            'Content-Type' => $found['mime'],
            'X-Content-Type-Options' => 'nosniff',
        ], false, 'inline');
        $response->headers->set('Cache-Control', 'private, max-age=' . $found['seconds']);

        return $response;
    }

    private function notFound(): Response
    {
        return response('', 404, ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
