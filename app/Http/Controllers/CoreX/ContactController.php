<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactType;
use App\Models\User;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user         = auth()->user();
        $role         = $user->effectiveRole();
        $canPickAgent = in_array($role, ['super_admin', 'admin', 'branch_manager']);

        // Agent filter with session persistence
        if ($request->has('agent_id')) {
            $raw           = $request->query('agent_id', '');
            $filterAgentId = $raw;
            session(['corex_contacts_agent_id' => $raw === '' ? 'all' : $raw]);
        } elseif ($canPickAgent) {
            $saved = session('corex_contacts_agent_id');
            if ($saved === null) {
                $filterAgentId = (string) $user->id;
                session(['corex_contacts_agent_id' => $filterAgentId]);
            } elseif ($saved === 'all') {
                $filterAgentId = '';
            } else {
                $filterAgentId = $saved;
            }
        } else {
            $filterAgentId = '';
        }

        $query = Contact::with(['type', 'createdBy'])->orderBy('last_name')->orderBy('first_name');

        if ($canPickAgent) {
            if ($filterAgentId !== '') {
                $query->where('created_by_user_id', (int) $filterAgentId);
            }
            // empty = show all contacts
        } else {
            // Regular agents see only their own
            $query->where('created_by_user_id', $user->id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('first_name', 'like', "%{$s}%")
                  ->orWhere('last_name',  'like', "%{$s}%")
                  ->orWhere('phone',      'like', "%{$s}%")
                  ->orWhere('email',      'like', "%{$s}%");
            });
        }

        if ($request->filled('type')) {
            $query->where('contact_type_id', $request->type);
        }

        $contacts     = $query->paginate(25)->withQueryString();
        $contactTypes = ContactType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        $agentList     = $canPickAgent ? $this->agentList()->values() : collect();
        $selectedAgent = ($canPickAgent && $filterAgentId !== '')
            ? $agentList->firstWhere('id', (int) $filterAgentId)
            : null;

        return view('corex.contacts.index', compact(
            'contacts', 'contactTypes', 'filterAgentId', 'agentList', 'selectedAgent', 'canPickAgent'
        ));
    }

    public function show(Contact $contact)
    {
        $contact->load(['type', 'createdBy', 'contactNotes.user', 'documents.uploadedBy', 'properties']);
        $contactTypes = ContactType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();

        return view('corex.contacts.show', compact('contact', 'contactTypes'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'phone'           => 'required|string|max:30',
            'email'           => 'nullable|email|max:150',
            'contact_type_id' => 'nullable|exists:contact_types,id',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $data['created_by_user_id'] = auth()->id();

        Contact::create($data);

        return redirect()->route('corex.contacts.index')->with('success', 'Contact added successfully.');
    }

    public function update(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'first_name'      => 'required|string|max:100',
            'last_name'       => 'required|string|max:100',
            'phone'           => 'required|string|max:30',
            'email'           => 'nullable|email|max:150',
            'contact_type_id' => 'nullable|exists:contact_types,id',
            'notes'           => 'nullable|string|max:1000',
            'birthday'        => 'nullable|date',
            'id_number'       => 'nullable|string|max:20',
            'address'         => 'nullable|string|max:500',
        ]);

        $contact->update($data);

        // Redirect to show page if coming from there, otherwise index
        if ($request->has('_from_show')) {
            return redirect()->route('corex.contacts.show', $contact)->with('success', 'Contact updated.');
        }

        return redirect()->route('corex.contacts.index')->with('success', 'Contact updated.');
    }

    public function destroy(Contact $contact)
    {
        $contact->delete();

        return redirect()->route('corex.contacts.index')->with('success', 'Contact deleted.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function agentList(): \Illuminate\Support\Collection
    {
        /** @var User $user */
        $user  = auth()->user();
        $role  = $user->effectiveRole();
        $query = User::orderBy('name')->where('is_active', 1);

        if ($role === 'branch_manager') {
            $branchId = $user->effectiveBranchId();
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        } elseif (! in_array($role, ['super_admin', 'admin'])) {
            $query->where('id', $user->id);
        }

        return $query->get(['id', 'name', 'email']);
    }
}
