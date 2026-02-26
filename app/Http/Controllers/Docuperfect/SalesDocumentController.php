<?php

namespace App\Http\Controllers\Docuperfect;

use App\Http\Controllers\Controller;
use App\Mail\Signatures\SalesDocumentMail;
use App\Mail\Signatures\SalesDocumentReminderMail;
use App\Mail\Signatures\SalesDocumentReturnedMail;
use App\Mail\Signatures\SalesDocumentAllReturnedMail;
use App\Models\SalesDocumentRecipient;
use App\Models\SalesDocumentSend;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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
            'recipients.*.name'  => 'required|string|max:255',
            'recipients.*.email' => 'required|email',
            'recipients.*.role'  => 'required|string|max:100',
            'message'        => 'nullable|string|max:1000',
        ]);

        // Handle file
        $filePath = null;

        if ($request->hasFile('uploaded_file')) {
            $filePath = $request->file('uploaded_file')->store('sales-documents', 'private');
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

            return redirect()->back()->with('status',
                "Approved. Document sent to {$nextRecipient->recipient_name} ({$nextRecipient->recipient_role}).");
        }

        // All done
        $send->update([
            'status'       => 'completed',
            'completed_at' => now(),
        ]);

        // Notify agent that all parties are done
        $agent = $send->sender;
        if ($agent) {
            Mail::to($agent->email)->send(new SalesDocumentAllReturnedMail(
                agentName: $agent->name,
                documentName: $send->document_name,
                dashboardUrl: route('docuperfect.sales'),
            ));
        }

        return redirect()->back()->with('status',
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
                agentEmail: $agent->email ?? config('mail.from.address'),
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

        return view('sales-documents.upload', [
            'recipient' => $recipient,
            'send'      => $send,
        ]);
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
            $paths[] = $file->store("sales-returns/{$send->id}/{$recipient->id}", 'private');
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

    // ── Private helpers ──

    private function sendDocumentEmail(SalesDocumentSend $send, SalesDocumentRecipient $recipient): void
    {
        $agent = $send->sender;

        $mail = new SalesDocumentMail(
            recipientName: $recipient->recipient_name,
            documentName: $send->document_name,
            filePath: $send->original_file_path
                ? storage_path("app/private/{$send->original_file_path}")
                : null,
            uploadUrl: route('sales-documents.upload', ['token' => $recipient->token]),
            message: $send->message,
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

        Mail::to($agent->email)->send(new SalesDocumentReturnedMail(
            agentName: $agent->name,
            documentName: $send->document_name,
            clientName: $justCompleted->recipient_name,
            clientRole: $justCompleted->recipient_role,
            nextRecipientName: $nextRecipient?->recipient_name,
            dashboardUrl: route('docuperfect.sales'),
        ));
    }
}
