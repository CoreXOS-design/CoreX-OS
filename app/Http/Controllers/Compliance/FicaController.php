<?php

namespace App\Http\Controllers\Compliance;

use App\Http\Controllers\Controller;
use App\Mail\FicaRequestMail;
use App\Models\Contact;
use App\Models\FicaSubmission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class FicaController extends Controller
{
    /**
     * List all FICA submissions for the agency.
     */
    public function index(Request $request)
    {
        $query = FicaSubmission::with(['contact', 'requestedBy', 'verifiedBy'])
            ->where('agency_id', Auth::user()->agency_id)
            ->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->whereHas('contact', function ($cq) use ($search) {
                    $cq->where('first_name', 'like', "%{$search}%")
                       ->orWhere('last_name', 'like', "%{$search}%")
                       ->orWhere('email', 'like', "%{$search}%");
                });
            });
        }

        $submissions = $query->paginate(20)->withQueryString();

        $counts = [
            'all'       => FicaSubmission::where('agency_id', Auth::user()->agency_id)->count(),
            'submitted' => FicaSubmission::where('agency_id', Auth::user()->agency_id)->where('status', 'submitted')->count(),
            'approved'  => FicaSubmission::where('agency_id', Auth::user()->agency_id)->where('status', 'approved')->count(),
            'pending'   => FicaSubmission::where('agency_id', Auth::user()->agency_id)->whereIn('status', ['draft', 'corrections_requested'])->count(),
        ];

        return view('compliance.fica.index', compact('submissions', 'counts'));
    }

    /**
     * Show form to pick a contact and send FICA request.
     */
    public function create()
    {
        $contacts = Contact::orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'phone']);

        return view('compliance.fica.create', compact('contacts'));
    }

    /**
     * Create submission record, generate token, send email.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'contact_id' => 'required|exists:contacts,id',
        ]);

        $contact = Contact::findOrFail($validated['contact_id']);

        if (! $contact->email) {
            return back()->withErrors(['contact_id' => 'This contact does not have an email address.'])->withInput();
        }

        $submission = FicaSubmission::create([
            'contact_id'       => $contact->id,
            'agency_id'        => Auth::user()->agency_id,
            'requested_by'     => Auth::id(),
            'token'            => Str::random(64),
            'token_expires_at' => now()->addDays(14),
            'status'           => 'draft',
        ]);

        Mail::to($contact->email)->send(
            new FicaRequestMail($submission, Auth::user())
        );

        return redirect()->route('compliance.fica.index')
            ->with('success', "FICA request sent to {$contact->full_name}.");
    }

    /**
     * Staff review screen.
     */
    public function show(FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);

        $submission->load(['contact', 'requestedBy', 'verifiedBy', 'documents']);

        return view('compliance.fica.show', compact('submission'));
    }

    /**
     * Approve submission.
     */
    public function approve(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);

        $validated = $request->validate([
            'risk_rating'         => 'required|integer|in:1,2,3',
            'verification_method' => 'required|array|min:1',
            'reviewer_notes'      => 'nullable|string|max:2000',
        ]);

        $submission->update([
            'status'              => 'approved',
            'risk_rating'         => $validated['risk_rating'],
            'verification_method' => $validated['verification_method'],
            'reviewer_notes'      => $validated['reviewer_notes'] ?? null,
            'verified_by'         => Auth::id(),
            'verified_at'         => now(),
        ]);

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'FICA submission approved.');
    }

    /**
     * Reject submission.
     */
    public function reject(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);

        $validated = $request->validate([
            'reviewer_notes' => 'required|string|max:2000',
        ]);

        $submission->update([
            'status'         => 'rejected',
            'reviewer_notes' => $validated['reviewer_notes'],
            'verified_by'    => Auth::id(),
            'verified_at'    => now(),
        ]);

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'FICA submission rejected.');
    }

    /**
     * Request corrections — send back to recipient.
     */
    public function requestCorrections(Request $request, FicaSubmission $submission)
    {
        $this->authorizeAgency($submission);

        $validated = $request->validate([
            'reviewer_notes' => 'required|string|max:2000',
        ]);

        $submission->update([
            'status'           => 'corrections_requested',
            'reviewer_notes'   => $validated['reviewer_notes'],
            'token'            => Str::random(64),
            'token_expires_at' => now()->addDays(14),
        ]);

        // Re-send email with new token
        if ($submission->contact && $submission->contact->email) {
            Mail::to($submission->contact->email)->send(
                new FicaRequestMail($submission, Auth::user())
            );
        }

        return redirect()->route('compliance.fica.show', $submission)
            ->with('success', 'Corrections requested — email sent to recipient.');
    }

    /**
     * Ensure submission belongs to the user's agency.
     */
    private function authorizeAgency(FicaSubmission $submission): void
    {
        abort_unless($submission->agency_id === Auth::user()->agency_id, 403);
    }
}
