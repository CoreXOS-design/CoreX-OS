<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Compliance\RmcpBoardLetter;
use App\Models\Compliance\RmcpBoardSignature;
use App\Models\Compliance\RmcpVersion;
use App\Services\Compliance\RmcpBoardApprovalService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * RMCP board approval letter - the emailed-link side. PUBLIC: the unguessable 40-character token is the credential, and the
 * letter is not shown until a six-digit code sent to the member's own address on the signature line is entered (the same
 * pattern as the DR2 secure document links, SecureDocumentController). A link that is unknown, cancelled or replaced by a newer
 * round gives the app's standard "isn't valid any more" page (410), so a prober cannot tell the cases apart.
 */
class RmcpBoardSigningController extends Controller
{
    public function __construct(private RmcpBoardApprovalService $service)
    {
    }

    public function show(Request $request, string $token)
    {
        [$sig, $letter] = $this->resolve($token);

        $verified = $this->isVerified($request, $sig);
        if ($verified && ! $sig->isSigned() && $sig->viewed_at === null) {
            $this->service->recordView($sig, $request->ip(), $request->userAgent());
            $sig->refresh();
        }

        $agency = Agency::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->find($letter->agency_id);
        $version = RmcpVersion::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->find($letter->rmcp_version_id);

        return view('compliance.rmcp-board.public', [
            'token'       => $token,
            'sig'         => $sig,
            'letter'      => $letter,
            'agencyName'  => Agency::displayNameFor($agency, 'the agency'),
            'version'     => $version,
            'verified'    => $verified,
            'maskedEmail' => Str::mask($sig->member_email, '*', 2, max(0, (int) strpos($sig->member_email, '@') - 3)),
            'codeSent'    => (bool) session('code_sent'),
        ]);
    }

    public function requestCode(Request $request, string $token)
    {
        [$sig] = $this->resolve($token);

        if ($sig->isSigned()) {
            return redirect()->route('rmcp-board.show', $token);
        }
        $blocked = $this->service->issueCode($sig, $request->ip(), $request->userAgent());
        if ($blocked) {
            return redirect()->route('rmcp-board.show', $token)->with('code_sent', true)->with('code_error', $blocked);
        }

        return redirect()->route('rmcp-board.show', $token)->with('code_sent', true)->with('status', 'We have emailed you a six-digit code.');
    }

    public function verify(Request $request, string $token)
    {
        [$sig] = $this->resolve($token);
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        if (! $this->service->verifyCode($sig, $data['code'], $request->ip(), $request->userAgent())) {
            return redirect()->route('rmcp-board.show', $token)->with('code_sent', true)
                ->with('code_error', 'That code was not right or has run out. Please try again, or ask for a new one.');
        }

        $request->session()->put($this->sessionKey($sig), true);

        return redirect()->route('rmcp-board.show', $token);
    }

    public function sign(Request $request, string $token)
    {
        [$sig] = $this->resolve($token);
        if (! $this->isVerified($request, $sig)) {
            return redirect()->route('rmcp-board.show', $token)->with('code_error', 'Please confirm the code we emailed you first.');
        }

        $data = $request->validate([
            'signature_type'           => ['required', 'in:drawn,typed'],
            'signature_data'           => ['required_if:signature_type,drawn', 'nullable', 'string'],
            'typed_name'               => ['required_if:signature_type,typed', 'nullable', 'string', 'max:200'],
            'declaration_acknowledged' => ['accepted'],
        ], ['declaration_acknowledged.accepted' => 'Please tick the box to confirm you have read the letter.']);

        try {
            $this->service->sign($sig, $data['signature_type'], $data['signature_data'] ?? null, $data['typed_name'] ?? null,
                'link', $request->ip(), $request->userAgent());
        } catch (\DomainException $e) {
            return redirect()->route('rmcp-board.show', $token)->with('sign_error', $e->getMessage());
        }

        return redirect()->route('rmcp-board.show', $token)->with('status', 'Thank you. Your signature is saved.');
    }

    /** The signed letter, once everyone has signed - for a verified signer. */
    public function pdf(Request $request, string $token)
    {
        [$sig, $letter] = $this->resolve($token);
        abort_unless($this->isVerified($request, $sig) && $sig->isSigned() && $letter->isComplete() && $letter->signed_pdf_path, 404);
        abort_unless(Storage::disk('local')->exists($letter->signed_pdf_path), 404);

        return Storage::disk('local')->download($letter->signed_pdf_path, 'rmcp-board-approval-signed.pdf');
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** @return array{0:RmcpBoardSignature,1:RmcpBoardLetter} */
    private function resolve(string $token): array
    {
        $sig = RmcpBoardSignature::findByToken($token);
        $letter = $sig ? RmcpBoardLetter::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->find($sig->letter_id) : null;

        // A letter that was cancelled or replaced is gone for the signer. A finished letter stays readable for those who signed it.
        if (! $sig || ! $letter || ! in_array($letter->status, [RmcpBoardLetter::STATUS_SENT, RmcpBoardLetter::STATUS_COMPLETE], true)) {
            abort(410);
        }

        return [$sig, $letter];
    }

    private function isVerified(Request $request, RmcpBoardSignature $sig): bool
    {
        return $request->session()->get($this->sessionKey($sig)) === true;
    }

    private function sessionKey(RmcpBoardSignature $sig): string
    {
        return 'rmcp_board_ok_' . $sig->id;
    }
}
