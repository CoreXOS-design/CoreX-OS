<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Http\Request;

class DocumentVerificationController extends Controller
{
    public function index()
    {
        $pending = UserDocument::with(['user', 'user.branch'])
            ->pending()
            ->orderBy('created_at')
            ->get();

        $recentlyVerified = UserDocument::with(['user', 'user.branch', 'verifier'])
            ->verified()
            ->where('verified_at', '>=', now()->subDays(7))
            ->orderByDesc('verified_at')
            ->get();

        $recentlyRejected = UserDocument::with(['user', 'user.branch', 'rejecter'])
            ->where('status', 'rejected')
            ->where('rejected_at', '>=', now()->subDays(7))
            ->orderByDesc('rejected_at')
            ->get();

        return view('compliance.verification-queue.index', compact(
            'pending',
            'recentlyVerified',
            'recentlyRejected'
        ));
    }

    public function show(UserDocument $userDocument)
    {
        $userDocument->load(['user', 'user.branch', 'verifier', 'rejecter', 'uploader']);

        return view('compliance.verification-queue.show', [
            'document' => $userDocument,
        ]);
    }

    public function verify(Request $request, UserDocument $userDocument)
    {
        abort_unless(auth()->user()->hasPermission('verify_user_documents'), 403);

        $userDocument->update([
            'status' => 'verified',
            'verified_by' => auth()->id(),
            'verified_at' => now(),
            'rejected_reason' => null,
            'rejected_by' => null,
            'rejected_at' => null,
        ]);

        // If FFC certificate and expiry_date is on the document, sync to user
        if ($userDocument->document_type === 'ffc_certificate' && $userDocument->expiry_date) {
            $userDocument->user->update([
                'ffc_expiry_date' => $userDocument->expiry_date,
            ]);
        }

        logger()->info('Document verified', [
            'user_document_id' => $userDocument->id,
            'verified_by' => auth()->id(),
            'agent' => $userDocument->user->name ?? $userDocument->user_id,
        ]);

        return redirect()->route('compliance.verification.index')
            ->with('success', 'Document verified.');
    }

    public function reject(Request $request, UserDocument $userDocument)
    {
        abort_unless(auth()->user()->hasPermission('verify_user_documents'), 403);

        $validated = $request->validate([
            'rejected_reason' => ['required', 'string', 'max:1000'],
        ]);

        $userDocument->update([
            'status' => 'rejected',
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
            'rejected_reason' => $validated['rejected_reason'],
            'verified_by' => null,
            'verified_at' => null,
        ]);

        logger()->info('Document rejected', [
            'user_document_id' => $userDocument->id,
            'rejected_by' => auth()->id(),
            'reason' => $validated['rejected_reason'],
            'agent' => $userDocument->user->name ?? $userDocument->user_id,
        ]);

        return redirect()->route('compliance.verification.index')
            ->with('success', 'Document rejected. User notified.');
    }

    public function markExpired(UserDocument $userDocument)
    {
        abort_unless(auth()->user()->hasPermission('verify_user_documents'), 403);

        $userDocument->update([
            'status' => 'expired',
            'verified_by' => null,
            'verified_at' => null,
        ]);

        logger()->info('Document marked expired', [
            'user_document_id' => $userDocument->id,
            'marked_by' => auth()->id(),
            'agent' => $userDocument->user->name ?? $userDocument->user_id,
        ]);

        return redirect()->route('compliance.verification.index')
            ->with('success', 'Document marked expired. User prompted to re-upload.');
    }
}
