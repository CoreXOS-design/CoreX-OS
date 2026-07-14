<?php

namespace App\Http\Controllers\DealV2;

use App\Http\Controllers\Controller;
use App\Models\DealV2\DealDocumentAccessLog;
use App\Models\DealV2\DealDocumentDistribution;
use App\Models\Otp;
use App\Services\Otp\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * AT-158 DR2 · WS4 (§8.2a) — the secure-link recipient flow (public, tokened).
 *
 * A recipient opens a tokened link → an OTP is sent to the recipient's OWN
 * email (identity gate) → only after verification does the document stream via
 * an authenticated response (never a public docroot path). Every step is written
 * to the immutable deal_document_access_log (POPIA evidence). Links are
 * revocable; a revoked/expired link shows a friendly page, logged.
 */
class SecureDocumentController extends Controller
{
    private const OTP_PURPOSE = 'deal_doc_distribution';

    public function __construct(private OtpService $otp)
    {
    }

    /** Landing page — logs the open, then offers the PIN gate (or direct download if OTP not required). */
    public function show(Request $request, string $token)
    {
        $dist = $this->resolve($token);
        if (! $dist) {
            return response()->view('deals-v2.secure-doc.unavailable', [], 410);
        }

        // Record the open once; promote status to "opened".
        DealDocumentAccessLog::record($dist, DealDocumentAccessLog::EVENT_LINK_CLICKED, [], $request->ip(), $request->userAgent());
        if (! $dist->first_opened_at) {
            $dist->update([
                'first_opened_at' => now(),
                'status' => $dist->status === DealDocumentDistribution::STATUS_SENT
                    ? DealDocumentDistribution::STATUS_OPENED : $dist->status,
            ]);
        }

        $verified = $this->isVerified($request, $dist);

        return view('deals-v2.secure-doc.show', [
            'token'       => $token,
            'dist'        => $dist,
            'title'       => $dist->document->documentType->label ?? ($dist->document->original_name ?? 'Document'),
            'otpRequired' => $dist->otp_required,
            'verified'    => $verified,
        ]);
    }

    /** Send a one-time PIN to the recipient's email (server-controlled destination). */
    public function requestOtp(Request $request, string $token)
    {
        $dist = $this->resolve($token);
        if (! $dist) {
            return response()->view('deals-v2.secure-doc.unavailable', [], 410);
        }
        if (! $dist->otp_required) {
            return redirect()->route('deals-v2.secure-doc.show', $token);
        }

        $destination = $dist->recipient_email;

        $blocked = $this->otp->throttle('deal_doc:' . $dist->id, $destination);
        if ($blocked) {
            return redirect()->route('deals-v2.secure-doc.show', $token)
                ->with('otp_error', $blocked === 'cooldown'
                    ? 'A PIN was just sent — please wait a moment before requesting another.'
                    : 'Too many PIN requests. Please try again later.');
        }

        $this->otp->issue(self::OTP_PURPOSE, $destination, [
            'subject'    => $dist,
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'audit'      => $this->auditSink($dist, $request),
        ]);

        return redirect()->route('deals-v2.secure-doc.show', $token)
            ->with('otp_sent', true)
            ->with('status', 'A one-time PIN has been emailed to you.');
    }

    /** Verify the submitted PIN; on success unlock the download for this session. */
    public function verifyOtp(Request $request, string $token)
    {
        $dist = $this->resolve($token);
        if (! $dist) {
            return response()->view('deals-v2.secure-doc.unavailable', [], 410);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        $otp = $this->otp->verify(self::OTP_PURPOSE, $dist->recipient_email, trim($data['code']), [
            'audit' => $this->auditSink($dist, $request),
        ]);

        if (! $otp) {
            return redirect()->route('deals-v2.secure-doc.show', $token)
                ->with('otp_sent', true)
                ->with('otp_error', 'That PIN was incorrect or has expired. Please try again.');
        }

        $request->session()->put($this->sessionKey($dist), true);

        return redirect()->route('deals-v2.secure-doc.show', $token)
            ->with('status', 'Verified — your document is ready to download.');
    }

    /** Stream the document — only after verification (or when OTP isn't required). */
    public function download(Request $request, string $token)
    {
        $dist = $this->resolve($token);
        if (! $dist) {
            return response()->view('deals-v2.secure-doc.unavailable', [], 410);
        }

        if (! $this->isVerified($request, $dist)) {
            return redirect()->route('deals-v2.secure-doc.show', $token)
                ->with('otp_error', 'Please verify your PIN before downloading.');
        }

        $document = $dist->document;
        $disk = Storage::disk($document->disk ?? 'local');
        if (! $document || ! $disk->exists($document->storage_path)) {
            return response()->view('deals-v2.secure-doc.unavailable', [], 410);
        }

        DealDocumentAccessLog::record($dist, DealDocumentAccessLog::EVENT_DOWNLOADED, [], $request->ip(), $request->userAgent());
        $dist->update(['status' => DealDocumentDistribution::STATUS_DOWNLOADED]);

        return $disk->download($document->storage_path, $document->original_name);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AT-264 — PACK flow: ONE link + ONE OTP unlocks a recipient's WHOLE pack.
    // A "Send" already groups its documents under a single `group_key`; the pack
    // link is keyed on that key, one OTP verification (the OTP is email-scoped)
    // unlocks every document in the group. A single-document send is simply a
    // pack of one, so the same flow serves it — uniform behaviour. The per-token
    // routes above stay untouched (existing links already in inboxes keep working).
    // ─────────────────────────────────────────────────────────────────────────

    /** Pack landing — lists every document in the group behind one PIN gate. */
    public function packShow(Request $request, string $groupKey)
    {
        $rows = $this->resolvePack($groupKey);
        if (! $rows) {
            return response()->view('deals-v2.secure-doc.unavailable', [], 410);
        }
        $rep = $rows->first();

        // Record the open once per document (so each doc's audit trail shows the
        // pack was opened); promote each still-"sent" row to "opened".
        foreach ($rows as $d) {
            if (! $d->first_opened_at) {
                $d->update([
                    'first_opened_at' => now(),
                    'status' => $d->status === DealDocumentDistribution::STATUS_SENT
                        ? DealDocumentDistribution::STATUS_OPENED : $d->status,
                ]);
            }
        }
        DealDocumentAccessLog::record($rep, DealDocumentAccessLog::EVENT_LINK_CLICKED,
            ['pack' => $groupKey, 'docs' => $rows->count()], $request->ip(), $request->userAgent());

        return view('deals-v2.secure-doc.pack', [
            'groupKey'    => $groupKey,
            'rows'        => $rows,
            'rep'         => $rep,
            'otpRequired' => (bool) $rep->otp_required,
            'verified'    => $this->isPackVerified($request, $groupKey, $rep),
        ]);
    }

    /** Send ONE PIN for the whole pack to the recipient's email. */
    public function packRequestOtp(Request $request, string $groupKey)
    {
        $rows = $this->resolvePack($groupKey);
        if (! $rows) {
            return response()->view('deals-v2.secure-doc.unavailable', [], 410);
        }
        $rep = $rows->first();
        if (! $rep->otp_required) {
            return redirect()->route('deals-v2.secure-doc.pack', $groupKey);
        }

        $blocked = $this->otp->throttle('deal_doc_pack:' . $groupKey, $rep->recipient_email);
        if ($blocked) {
            return redirect()->route('deals-v2.secure-doc.pack', $groupKey)
                ->with('otp_error', $blocked === 'cooldown'
                    ? 'A PIN was just sent — please wait a moment before requesting another.'
                    : 'Too many PIN requests. Please try again later.');
        }

        $this->otp->issue(self::OTP_PURPOSE, $rep->recipient_email, [
            'subject'    => $rep,
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'audit'      => $this->auditSink($rep, $request),
        ]);

        return redirect()->route('deals-v2.secure-doc.pack', $groupKey)
            ->with('otp_sent', true)
            ->with('status', 'A one-time PIN has been emailed to you.');
    }

    /** Verify the PIN once; unlock every document in the pack for this session. */
    public function packVerifyOtp(Request $request, string $groupKey)
    {
        $rows = $this->resolvePack($groupKey);
        if (! $rows) {
            return response()->view('deals-v2.secure-doc.unavailable', [], 410);
        }
        $rep = $rows->first();
        $data = $request->validate(['code' => ['required', 'string', 'max:12']]);

        $otp = $this->otp->verify(self::OTP_PURPOSE, $rep->recipient_email, trim($data['code']), [
            'audit' => $this->auditSink($rep, $request),
        ]);
        if (! $otp) {
            return redirect()->route('deals-v2.secure-doc.pack', $groupKey)
                ->with('otp_sent', true)
                ->with('otp_error', 'That PIN was incorrect or has expired. Please try again.');
        }

        $request->session()->put($this->packSessionKey($groupKey), true);

        return redirect()->route('deals-v2.secure-doc.pack', $groupKey)
            ->with('status', 'Verified — your documents are ready to download.');
    }

    /** Stream one document from the pack — only after the single pack verification. */
    public function packDownload(Request $request, string $groupKey, string $distribution)
    {
        $rows = $this->resolvePack($groupKey);
        if (! $rows) {
            return response()->view('deals-v2.secure-doc.unavailable', [], 410);
        }
        $rep = $rows->first();
        if (! $this->isPackVerified($request, $groupKey, $rep)) {
            return redirect()->route('deals-v2.secure-doc.pack', $groupKey)
                ->with('otp_error', 'Please verify your PIN before downloading.');
        }

        // The requested distribution MUST belong to this group (never a foreign id).
        $dist = $rows->firstWhere('id', (int) $distribution);
        $document = $dist?->document;
        if (! $dist || ! $document) {
            return response()->view('deals-v2.secure-doc.unavailable', [], 410);
        }
        $disk = Storage::disk($document->disk ?? 'local');
        if (! $disk->exists($document->storage_path)) {
            return response()->view('deals-v2.secure-doc.unavailable', [], 410);
        }

        DealDocumentAccessLog::record($dist, DealDocumentAccessLog::EVENT_DOWNLOADED,
            ['pack' => $groupKey], $request->ip(), $request->userAgent());
        $dist->update(['status' => DealDocumentDistribution::STATUS_DOWNLOADED]);

        return $disk->download($document->storage_path, $document->original_name);
    }

    // ── Helpers ──

    /** All live secure-link rows in a group (shared recipient_email + otp_required). */
    private function resolvePack(string $groupKey): ?\Illuminate\Support\Collection
    {
        $rows = DealDocumentDistribution::withoutGlobalScopes()
            ->where('group_key', $groupKey)
            ->where('delivery_mode', DealDocumentDistribution::MODE_SECURE_LINK)
            ->with(['document.documentType'])
            ->orderBy('id')
            ->get()
            ->filter(fn (DealDocumentDistribution $d) => $d->isSecureLink() && ! $d->isRevoked())
            ->values();

        return $rows->isEmpty() ? null : $rows;
    }

    private function isPackVerified(Request $request, string $groupKey, DealDocumentDistribution $rep): bool
    {
        return ! $rep->otp_required || $request->session()->get($this->packSessionKey($groupKey)) === true;
    }

    private function packSessionKey(string $groupKey): string
    {
        return 'secure_doc_pack_verified_' . $groupKey;
    }

    private function resolve(string $token): ?DealDocumentDistribution
    {
        // Public route — the token IS the credential, so the agency scope must
        // not hide the row. This is the audited cross-scope read (recipient has
        // no CoreX session), gated entirely by the unguessable 40-char token.
        $dist = DealDocumentDistribution::withoutGlobalScopes()
            ->where('secure_token', $token)
            ->with(['document.documentType'])
            ->first();

        if (! $dist || ! $dist->isSecureLink() || $dist->isRevoked()) {
            return null;
        }
        return $dist;
    }

    private function isVerified(Request $request, DealDocumentDistribution $dist): bool
    {
        return ! $dist->otp_required || $request->session()->get($this->sessionKey($dist)) === true;
    }

    private function sessionKey(DealDocumentDistribution $dist): string
    {
        return 'secure_doc_verified_' . $dist->id;
    }

    private function auditSink(DealDocumentDistribution $dist, Request $request): callable
    {
        return function (string $event, ?Otp $otp, array $ctx) use ($dist, $request) {
            $map = [
                'otp_issued'            => DealDocumentAccessLog::EVENT_OTP_SENT,
                'otp_verified'          => DealDocumentAccessLog::EVENT_OTP_VERIFIED,
                'otp_failed'            => DealDocumentAccessLog::EVENT_OTP_FAILED,
                'otp_attempts_exceeded' => DealDocumentAccessLog::EVENT_OTP_FAILED,
            ];
            if (isset($map[$event])) {
                DealDocumentAccessLog::record($dist, $map[$event], ['otp_event' => $event], $request->ip(), $request->userAgent());
            }
        };
    }
}
