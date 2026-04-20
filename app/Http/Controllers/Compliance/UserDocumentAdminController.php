<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserDocument;
use Illuminate\Http\Request;

class UserDocumentAdminController extends Controller
{
    public function uploadForUser(User $user)
    {
        abort_unless(auth()->user()->hasPermission('manage_user_compliance'), 403);

        $documentTypes = UserDocument::$documentTypeLabels;
        // Remove 'other' and 'profile_photo' — admin uploads are for compliance docs
        unset($documentTypes['other'], $documentTypes['profile_photo']);

        $existingDocs = $user->documents()
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('document_type')
            ->map(fn ($group) => $group->first());

        return view('compliance.admin-upload', compact('user', 'documentTypes', 'existingDocs'));
    }

    public function storeForUser(Request $request, User $user)
    {
        abort_unless(auth()->user()->hasPermission('manage_user_compliance'), 403);

        $validTypes = array_keys(UserDocument::$documentTypeLabels);

        $validated = $request->validate([
            'document_type' => ['required', 'string', 'in:' . implode(',', $validTypes)],
            'file'          => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
            'reason'        => ['required', 'string', 'min:10', 'max:2000'],
            'expiry_date'   => ['nullable', 'date'],
        ]);

        $file = $request->file('file');
        $path = $file->store('user-documents/' . $user->id, 'public');

        $document = UserDocument::create([
            'agency_id'           => $user->agency_id,
            'user_id'             => $user->id,
            'document_type'       => $validated['document_type'],
            'file_path'           => $path,
            'file_name'           => $file->getClientOriginalName(),
            'file_size'           => $file->getSize(),
            'mime_type'           => $file->getMimeType(),
            'status'              => 'verified',
            'verified_by'         => auth()->id(),
            'verified_at'         => now(),
            'uploaded_by'         => auth()->id(),
            'uploaded_by_admin'   => true,
            'admin_upload_reason' => $validated['reason'],
            'expiry_date'         => $validated['expiry_date'] ?? null,
        ]);

        // Sync legacy columns for backward compatibility
        if ($validated['document_type'] === 'ffc_certificate') {
            $user->update(['ffc_certificate_path' => $path]);
            if (!empty($validated['expiry_date'])) {
                $user->update(['ffc_expiry_date' => $validated['expiry_date']]);
            }
        }

        logger()->info('Document uploaded by admin on behalf of user', [
            'user_document_id' => $document->id,
            'target_user_id' => $user->id,
            'target_user_name' => $user->name,
            'document_type' => $validated['document_type'],
            'admin_id' => auth()->id(),
            'admin_name' => auth()->user()->name,
            'reason' => $validated['reason'],
        ]);

        $redirectTo = $request->input('redirect_to', 'user');

        if ($redirectTo === 'verification') {
            return redirect()->route('compliance.verification.index')
                ->with('success', 'Document uploaded for ' . $user->name . ' and marked verified.');
        }

        return redirect()->route('admin.users.edit', $user)
            ->with('success', 'Document uploaded for ' . $user->name . ' and marked verified.');
    }
}
