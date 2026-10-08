<?php

declare(strict_types=1);

namespace App\Exceptions\Docuperfect;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Someone tried to switch e-signing ON for a template whose document type carries the legal
 * warning, without acknowledging it. This is not a block on e-signing — acknowledge and it
 * goes through — it is the "must acknowledge before it saves" step.
 *
 * Rendered as a 422 JSON `{code: 'esign_ack_required', warning: {...}}` for the editors (which
 * show the warning and resubmit with `esign_acknowledged`), or a redirect-back with the
 * message for a plain form post.
 */
final class EsignAcknowledgementRequired extends RuntimeException
{
    /** @param array{title:string,paragraphs:array<int,string>,confirm_label:string,enable_button:string,cancel_button:string,version:int} $warning */
    public function __construct(string $message, public readonly array $warning)
    {
        parent::__construct($message);
    }

    public function render(Request $request): Response|JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok'      => false,
                'code'    => 'esign_ack_required',
                'error'   => $this->getMessage(),
                'warning' => $this->warning,
            ], 422);
        }

        return back()->withInput()->withErrors(['esign' => $this->getMessage()]);
    }
}
