<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Mail\Signatures\SalesDocumentMail;
use App\Mail\Signatures\SalesDocumentReminderMail;
use App\Mail\Signatures\SalesDocumentReturnedMail;
use App\Mail\Signatures\SalesDocumentAllReturnedMail;
use App\Notifications\SignatureActivityNotification;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\SalesDocumentRecipient;
use App\Models\SalesDocumentSend;
use App\Services\Docuperfect\DocumentFlattener;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SalesDocumentController extends Controller
{
    /**
     * Sales documents dashboard — grouped by status.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $sends = SalesDocumentSend::visibleTo($user)
            ->with(['recipients', 'sender'])
            ->orderByDesc('created_at')
            ->get();

        $inProgress = $sends->where('status', 'in_progress');
        $completed  = $sends->where('status', 'completed');
        $expired    = $sends->where('status', 'expired');

        return view('docuperfect.sales.dashboard', [
            'inProgress' => $inProgress,
            'completed'  => $completed,
            'expired'    => $expired,
        ]);
    }

    /**
     * Show the send form.
     */
    public function showSendForm(Request $request)
    {
        $documentId   = $request->query('document_id');
        $documentName = $request->query('document_name', '');

        return view('docuperfect.sales.send-form', [
            'documentId'   => $documentId,
            'documentName' => $documentName,
        ]);
    }

    /**
     * Create the send record, build recipient chain, send to first person.
     */
    public function sendToClient(Request $request)
    {
        $request->validate([
            'document_name'  => 'required|string|max:255',
            'uploaded_file'  => 'nullable|file|mimes:pdf,doc,docx|max:20480',
            'recipients'     => 'required|array|min:1',
            'recipients.*.name'      => 'required|string|max:255',
            'recipients.*.email'     => 'required|email',
            'recipients.*.role'      => 'required|string|max:100',
            'recipients.*.id_number' => 'required|string|max:20',
            'message'        => 'nullable|string|max:1000',
        ]);

        // Handle file
        $filePath = null;

        if ($request->hasFile('uploaded_file')) {
            $filePath = $request->file('uploaded_file')->store('sales-documents', 'local');
        }

        // Create parent record
        $send = SalesDocumentSend::create([
            'document_id'        => $request->input('document_id'),
            'document_name'      => $request->input('document_name'),
            'original_file_path' => $filePath,
            'sent_by'            => auth()->id(),
            'message'            => $request->input('message'),
            'status'             => 'in_progress',
        ]);

        // Create recipient chain
        foreach ($request->input('recipients') as $index => $recipientData) {
            $order   = $index + 1;
            $isFirst = $order === 1;

            SalesDocumentRecipient::create([
                'sales_document_send_id' => $send->id,
                'signing_order'          => $order,
                'recipient_name'         => $recipientData['name'],
                'recipient_email'        => $recipientData['email'],
                'recipient_role'         => $recipientData['role'],
                'id_number'              => $recipientData['id_number'] ?? null,
                'token'                  => Str::random(64),
                'token_expires_at'       => now()->addDays(30),
                'status'                 => $isFirst ? 'sent' : 'waiting',
                'sent_at'               => $isFirst ? now() : null,
            ]);
        }

        // Send email to first recipient
        $firstRecipient = $send->recipients()->orderBy('signing_order')->first();
        $this->sendDocumentEmail($send, $firstRecipient);

        return redirect()->route('docuperfect.sales')
            ->with('status', "Document sent to {$firstRecipient->recipient_name}.");
    }

    /**
     * Agent approves a returned copy and sends to next person in chain.
     */
    public function approveAndSendNext(SalesDocumentSend $send, SalesDocumentRecipient $recipient)
    {
        $recipient->update(['status' => 'approved']);

        // Find next waiting recipient
        $nextRecipient = $send->recipients()
            ->where('signing_order', '>', $recipient->signing_order)
            ->where('status', 'waiting')
            ->orderBy('signing_order')
            ->first();

        if ($nextRecipient) {
            $nextRecipient->update([
                'status'           => 'sent',
                'sent_at'          => now(),
                'token'            => Str::random(64),
                'token_expires_at' => now()->addDays(30),
            ]);

            $this->sendDocumentEmail($send, $nextRecipient);

            return redirect()->route('docuperfect.sales')->with('success',
                "Approved. Document sent to {$nextRecipient->recipient_name} ({$nextRecipient->recipient_role}).");
        }

        // All done
        $send->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        // In-app notification to agent — no email
        $agent = $send->sender;
        if ($agent) {
            $agent->notify(SignatureActivityNotification::salesDocumentAllReturned(
                $send->document_name, $send->id, route('docuperfect.sales'),
            ));
        }

        return redirect()->route('docuperfect.sales')->with('success',
            "All parties have returned signed copies. Document complete.");
    }

    /**
     * Agent manually marks a recipient as returned (e.g. they emailed it back).
     */
    public function markAsReturned(SalesDocumentRecipient $recipient)
    {
        $send = $recipient->documentSend;

        $recipient->update([
            'status'        => 'returned_pending_approval',
            'returned_at'   => now(),
            'return_method' => 'email',
        ]);

        $this->notifyAgentOfReturn($send, $recipient);

        return redirect()->back()->with('status',
            "{$recipient->recipient_name}'s copy marked as returned. Please review and approve.");
    }

    /**
     * Resend to a specific recipient (new token, reset reminders).
     */
    public function resend(SalesDocumentRecipient $recipient)
    {
        $send = $recipient->documentSend;

        $recipient->update([
            'token'            => Str::random(64),
            'token_expires_at' => now()->addDays(30),
            'status'           => 'sent',
            'sent_at'          => now(),
            'reminder_count'   => 0,
            'last_reminder_at' => null,
        ]);

        $this->sendDocumentEmail($send, $recipient);

        return redirect()->back()->with('status', "Document re-sent to {$recipient->recipient_name}.");
    }

    /**
     * Agent sends a manual reminder to a recipient.
     */
    public function sendManualReminder(SalesDocumentRecipient $recipient)
    {
        $send  = $recipient->documentSend;
        $agent = $send->sender;

        Mail::to($recipient->recipient_email)->send(
            (new SalesDocumentReminderMail(
                recipientName: $recipient->recipient_name,
                documentName: $send->document_name,
                uploadUrl: route('sales-documents.upload', ['token' => $recipient->token]),
                level: 'manual',
                agentEmail: $agent->outward_email ?? config('mail.from.address'), // AT-79 outward override
                daysSinceSent: $recipient->daysSinceSent(),
            ))->fromAgent($agent)
        );

        $recipient->update([
            'reminder_count'   => $recipient->reminder_count + 1,
            'last_reminder_at' => now(),
        ]);

        return redirect()->back()->with('status', "Reminder sent to {$recipient->recipient_name}.");
    }

    /**
     * Public page — show upload form for returning signed document.
     */
    public function showUploadPage(string $token)
    {
        $recipient = SalesDocumentRecipient::where('token', $token)->firstOrFail();
        $send = $recipient->documentSend;

        if ($recipient->isExpired()) {
            return view('sales-documents.expired');
        }

        if ($recipient->isReturned()) {
            return view('sales-documents.already-returned');
        }

        // Identity verification gate — only if recipient has an ID number on file
        if (!session("sales_verified_{$token}") && !empty($recipient->id_number)) {
            return view('sales-documents.verify', [
                'recipient' => $recipient,
                'send'      => $send,
            ]);
        }

        return view('sales-documents.upload', [
            'recipient' => $recipient,
            'send'      => $send,
        ]);
    }

    /**
     * Public — download original document (token + ID verified).
     */
    public function downloadForRecipient(string $token)
    {
        $recipient = SalesDocumentRecipient::where('token', $token)->firstOrFail();
        $send = $recipient->documentSend;

        if ($recipient->isExpired()) {
            return view('sales-documents.expired');
        }

        // Must pass ID verification first
        if (!session("sales_verified_{$token}") && !empty($recipient->id_number)) {
            return redirect()->route('sales-documents.upload', ['token' => $token]);
        }

        if (!$send->original_file_path || !file_exists(storage_path("app/private/{$send->original_file_path}"))) {
            return redirect()->route('sales-documents.upload', ['token' => $token])
                ->with('error', 'Document file not available for download.');
        }

        return response()->download(
            storage_path("app/private/{$send->original_file_path}"),
            $send->document_name . '.pdf'
        );
    }

    /**
     * Public — verify sales recipient identity (ID/passport number).
     */
    public function verifySalesIdentity(Request $request, string $token)
    {
        $recipient = SalesDocumentRecipient::where('token', $token)->firstOrFail();

        if ($recipient->isExpired()) {
            return view('sales-documents.expired');
        }

        $request->validate([
            'id_number' => 'required|string|min:3|max:20',
        ]);

        $submittedId = strtolower(trim($request->id_number));
        $expectedId  = strtolower(trim($recipient->id_number));

        if ($submittedId !== $expectedId) {
            return back()->with('error', 'The ID number you entered does not match our records. Please try again.');
        }

        session(["sales_verified_{$token}" => true]);

        return redirect()->route('sales-documents.upload', ['token' => $token]);
    }

    /**
     * Public — handle uploaded signed document.
     */
    public function handleUpload(Request $request, string $token)
    {
        $recipient = SalesDocumentRecipient::where('token', $token)->firstOrFail();
        $send = $recipient->documentSend;

        if ($recipient->isExpired()) {
            return view('sales-documents.expired');
        }

        if ($recipient->isReturned()) {
            return view('sales-documents.already-returned');
        }

        $request->validate([
            'files'   => 'required|array|min:1',
            'files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:20480',
        ]);

        // Store uploaded files
        $paths = [];
        foreach ($request->file('files') as $file) {
            $paths[] = $file->store("sales-returns/{$send->id}/{$recipient->id}", 'local');
        }

        $recipient->update([
            'status'             => 'returned_pending_approval',
            'returned_at'        => now(),
            'returned_file_path' => json_encode($paths),
            'return_method'      => 'upload',
        ]);

        $this->notifyAgentOfReturn($send, $recipient);

        return view('sales-documents.upload-success', [
            'recipient' => $recipient,
            'send'      => $send,
        ]);
    }

    /**
     * Download the original document for a send.
     */
    public function downloadOriginal(SalesDocumentSend $send)
    {
        if (!$send->original_file_path || !file_exists(storage_path("app/private/{$send->original_file_path}"))) {
            return redirect()->back()->with('error', 'Original document not found.');
        }

        return response()->download(
            storage_path("app/private/{$send->original_file_path}"),
            $send->document_name . '.pdf'
        );
    }

    /**
     * Agent uploads a signed scan for a Docuperfect sales document.
     * Converts to page images and stores as the document's flattened pages.
     */
    public function uploadSignedDocument(Request $request, Document $document)
    {
        $user = $request->user();

        // Verify ownership / access
        if (!$user->hasPermission('sales_docs.edit') && (int) $document->owner_id !== (int) $user->id) {
            abort(403);
        }

        $request->validate([
            'signed_files'   => 'required|array|min:1',
            'signed_files.*' => 'file|mimes:pdf,jpg,jpeg,png|max:20480',
        ]);

        // Store uploaded files
        $uploadPaths = [];
        foreach ($request->file('signed_files') as $file) {
            $uploadPaths[] = $file->store("docuperfect/sales-signed/{$document->id}", 'local');
        }

        // Get or create the signature template for this document
        $template = SignatureTemplate::firstOrCreate(
            ['document_id' => $document->id],
            [
                'status' => SignatureTemplate::STATUS_DRAFT,
                'created_by' => $user->id,
                'signing_order_json' => ['agent'],
            ]
        );

        // Flatten the uploaded scan into page images
        $flattener = app(DocumentFlattener::class);
        $flattener->flattenWetInkScan($template, $uploadPaths);

        // Mark as ready — agent has uploaded signed scan, needs to set up parties
        $template->update(['status' => SignatureTemplate::STATUS_READY]);

        return redirect()->route('docuperfect.sales')
            ->with('status', "Signed document uploaded for \"{$document->name}\". Now set up signing parties.");
    }

    /**
     * Cancel / reject an in-progress sales document send.
     */
    public function cancel(Request $request, SalesDocumentSend $send)
    {
        $user = $request->user();

        // Authorization: owner or user with sales_docs.edit permission
        if (!$user->hasPermission('sales_docs.edit') && (int) $send->sent_by !== (int) $user->id) {
            abort(403);
        }

        $request->validate([
            'rejection_reason' => 'required|string|min:5|max:1000',
        ]);

        // Expire all pending/sent recipients
        $send->recipients()
            ->whereIn('status', ['waiting', 'sent', 'returned_pending_approval'])
            ->update(['status' => 'expired']);

        $send->update(['status' => 'expired']);

        return redirect()->route('docuperfect.sales')
            ->with('status', "Document \"{$send->document_name}\" has been cancelled.");
    }

    /**
     * Show review page for a returned document (agent must review before approving).
     */
    public function reviewUpload(Request $request, SalesDocumentSend $send, SalesDocumentRecipient $recipient)
    {
        $user = $request->user();

        if (!$user->hasPermission('sales_docs.edit') && (int) $send->sent_by !== (int) $user->id) {
            abort(403);
        }

        if ($recipient->status === 'approved') {
            return redirect()->route('docuperfect.sales')
                ->with('status', "{$recipient->recipient_name}'s document has already been approved.");
        }

        if (!$recipient->returned_file_path && $recipient->return_method !== 'email') {
            return redirect()->route('docuperfect.sales')
                ->with('error', 'No uploaded document to review.');
        }

        // Decode file paths
        $uploadPaths = [];
        if ($recipient->returned_file_path) {
            $decoded = json_decode($recipient->returned_file_path, true);
            $uploadPaths = is_array($decoded) ? $decoded : [$recipient->returned_file_path];
        }

        $uploadFiles = [];
        foreach ($uploadPaths as $index => $path) {
            $uploadFiles[] = [
                'path'      => $path,
                'name'      => basename($path),
                'extension' => pathinfo($path, PATHINFO_EXTENSION),
                'exists'    => Storage::disk('local')->exists($path),
                'url'       => route('docuperfect.sales.recipientFile', [
                    'send'      => $send->id,
                    'recipient' => $recipient->id,
                    'index'     => $index,
                ]),
            ];
        }

        $nextRecipient = $send->recipients()
            ->where('signing_order', '>', $recipient->signing_order)
            ->where('status', 'waiting')
            ->orderBy('signing_order')
            ->first();

        return view('docuperfect.sales.review-upload', [
            'send'          => $send,
            'recipient'     => $recipient,
            'uploadFiles'   => $uploadFiles,
            'nextRecipient' => $nextRecipient,
        ]);
    }

    /**
     * Serve a returned file for agent review.
     */
    public function serveReturnedFile(Request $request, SalesDocumentSend $send, SalesDocumentRecipient $recipient, $index)
    {
        $user = $request->user();

        if (!$user->hasPermission('sales_docs.view') && (int) $send->sent_by !== (int) $user->id) {
            abort(403);
        }

        $uploadPaths = [];
        if ($recipient->returned_file_path) {
            $decoded = json_decode($recipient->returned_file_path, true);
            $uploadPaths = is_array($decoded) ? $decoded : [$recipient->returned_file_path];
        }

        $idx = (int) $index;
        if (!isset($uploadPaths[$idx])) {
            abort(404);
        }

        $path = $uploadPaths[$idx];
        if (!Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path));
    }

    /**
     * Agent uploads a signed document on behalf of a recipient (received via WhatsApp/email/in-person).
     */
    public function uploadOnBehalf(Request $request, SalesDocumentSend $send, SalesDocumentRecipient $recipient)
    {
        $user = $request->user();

        if (!$user->hasPermission('sales_docs.edit') && (int) $send->sent_by !== (int) $user->id) {
            abort(403);
        }

        $request->validate([
            'files'          => 'required|array|min:1',
            'files.*'        => 'file|mimes:pdf,jpg,jpeg,png|max:20480',
            'receive_method' => 'required|in:whatsapp,email,in_person',
        ]);

        $paths = [];
        foreach ($request->file('files') as $file) {
            $paths[] = $file->store("sales-returns/{$send->id}/{$recipient->id}", 'local');
        }

        // Upload on behalf = agent already has signed doc, approve immediately
        $recipient->update([
            'status'             => 'approved',
            'returned_at'        => now(),
            'returned_file_path' => json_encode($paths),
            'return_method'      => $request->input('receive_method'),
        ]);

        // Find next waiting recipient and advance the chain
        $nextRecipient = $send->recipients()
            ->where('signing_order', '>', $recipient->signing_order)
            ->where('status', 'waiting')
            ->orderBy('signing_order')
            ->first();

        if ($nextRecipient) {
            $nextRecipient->update([
                'status'           => 'sent',
                'sent_at'          => now(),
                'token'            => Str::random(64),
                'token_expires_at' => now()->addDays(30),
            ]);

            $this->sendDocumentEmail($send, $nextRecipient);

            return redirect()->route('docuperfect.sales')->with('success',
                "Uploaded and approved for {$recipient->recipient_name}. Document sent to {$nextRecipient->recipient_name}.");
        }

        // No more recipients — mark send as completed
        $send->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        // In-app notification to agent — no email
        $agent = $send->sender;
        if ($agent) {
            $agent->notify(SignatureActivityNotification::salesDocumentAllReturned(
                $send->document_name, $send->id, route('docuperfect.sales'),
            ));
        }

        return redirect()->route('docuperfect.sales')->with('success',
            "Uploaded and approved for {$recipient->recipient_name}. All parties complete.");
    }

    // ── Private helpers ──

    private function sendDocumentEmail(SalesDocumentSend $send, SalesDocumentRecipient $recipient): void
    {
        $agent = $send->sender;

        $mail = new SalesDocumentMail(
            recipientName: $recipient->recipient_name,
            documentName: $send->document_name,
            uploadUrl: route('sales-documents.upload', ['token' => $recipient->token]),
            personalMessage: $send->message,
            expiresAt: $recipient->token_expires_at,
        );

        $mail->fromAgent($agent);

        Mail::to($recipient->recipient_email)->send($mail);
    }

    private function notifyAgentOfReturn(SalesDocumentSend $send, SalesDocumentRecipient $justCompleted): void
    {
        $agent = $send->sender;
        if (!$agent) {
            return;
        }

        $nextRecipient = $send->recipients()
            ->where('signing_order', '>', $justCompleted->signing_order)
            ->where('status', 'waiting')
            ->orderBy('signing_order')
            ->first();

        // In-app notification to agent — no email
        $agent->notify(SignatureActivityNotification::salesDocumentReturned(
            $justCompleted->recipient_name, $send->document_name, $send->id, route('docuperfect.sales'),
        ));
    }
}
