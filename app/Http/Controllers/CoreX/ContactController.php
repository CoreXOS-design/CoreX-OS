<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactTag;
use App\Models\ContactType;
use App\Models\DocumentType;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user         = auth()->user();
        $dataScope    = PermissionService::getDataScope($user, 'contacts');
        $canPickAgent = in_array($dataScope, ['all', 'branch']);

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
            } elseif ($dataScope === 'branch' && $user->branch_id) {
                $query->whereHas('createdBy', fn($q) => $q->where('branch_id', $user->branch_id));
            }
            // 'all' scope with no filter = show all contacts
        } else {
            // 'own' scope: agents see only their own
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
        $contact->load(['type', 'createdBy', 'contactNotes.user', 'documents.uploader', 'documents.documentType', 'documents.properties', 'properties', 'matches.createdBy', 'tags']);
        $contactTypes     = ContactType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $contactTags      = ContactTag::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        $matchCategories  = PropertySettingItem::group('category')->get();
        $matchTypes       = PropertySettingItem::group('property_type')->where('active', true)->get();
        $documentTypes    = DocumentType::active()->ordered()->get();

        // Group documents by property for the Drive tab
        $allDocs = $contact->documents;
        $driveLinkedGroups = [];
        $driveUnlinkedDocs = collect();
        foreach ($allDocs as $doc) {
            $propId = $doc->properties->first()?->id;
            if ($propId) {
                $driveLinkedGroups[$propId][] = $doc;
            } else {
                $driveUnlinkedDocs->push($doc);
            }
        }
        $drivePropertyMap = $contact->properties->keyBy('id');

        return view('corex.contacts.show', compact('contact', 'contactTypes', 'contactTags', 'matchCategories', 'matchTypes', 'documentTypes', 'driveLinkedGroups', 'driveUnlinkedDocs', 'drivePropertyMap'));
    }

    public function checkDuplicate(Request $request)
    {
        $request->validate([
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
        ]);

        $phone = $request->input('phone');
        $email = $request->input('email');

        if (!$phone && !$email) {
            return response()->json(['found' => false]);
        }

        $duplicate = Contact::with('createdBy')
            ->where(function ($q) use ($phone, $email) {
                if ($phone) {
                    $q->where('phone', $phone);
                }
                if ($email) {
                    $q->orWhere('email', $email);
                }
            })
            ->first();

        if (!$duplicate) {
            return response()->json(['found' => false]);
        }

        return response()->json([
            'found'          => true,
            'name'           => $duplicate->full_name,
            'phone'          => $duplicate->phone,
            'email'          => $duplicate->email ?? '—',
            'type'           => optional($duplicate->type)->name ?? '—',
            'agent'          => optional($duplicate->createdBy)->name ?? 'Unknown',
            'last_contacted' => $duplicate->last_contacted_at
                ? \Carbon\Carbon::parse($duplicate->last_contacted_at)->format('d M Y')
                : 'Never',
            'url'            => route('corex.contacts.show', $duplicate),
        ]);
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

        // Duplicate check — email or phone already exists under a different contact
        $duplicate = Contact::with('createdBy')
            ->where(function ($q) use ($data) {
                $q->where('phone', $data['phone']);
                if (!empty($data['email'])) {
                    $q->orWhere('email', $data['email']);
                }
            })
            ->first();

        if ($duplicate) {
            $ownerName = optional($duplicate->createdBy)->name ?? 'another agent';
            $field     = $duplicate->phone === $data['phone'] ? 'phone number' : 'email address';
            return back()->withInput()->withErrors([
                'phone' => "This contact's {$field} already exists under a contact created by {$ownerName}.",
            ]);
        }

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
            'loaded_at'       => 'nullable|date',
            'modified_at'     => 'nullable|date',
            'tag_ids'         => 'nullable|array',
            'tag_ids.*'       => 'integer|exists:contact_tags,id',
            'bank_name'           => 'nullable|string|max:255',
            'bank_account_name'   => 'nullable|string|max:255',
            'bank_account_number' => 'nullable|string|max:100',
            'bank_branch_name'    => 'nullable|string|max:255',
            'bank_branch_code'    => 'nullable|string|max:50',
            'bank_account_type'   => 'nullable|string|max:50',
        ]);

        $tagIds = $data['tag_ids'] ?? [];
        unset($data['tag_ids']);

        $contact->update($data);
        $contact->tags()->sync($tagIds);

        // Redirect to show page if coming from there, otherwise index
        if ($request->has('_from_show')) {
            return redirect()->route('corex.contacts.show', $contact)->with('success', 'Contact updated.');
        }

        return redirect()->route('corex.contacts.index')->with('success', 'Contact updated.');
    }

    public function touch(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'last_contacted_at' => 'required|date',
        ]);

        $contact->update(['last_contacted_at' => $data['last_contacted_at']]);

        return redirect()->route('corex.contacts.show', $contact)->with('success', 'Last contacted date updated.');
    }

    public function incrementChannel(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'channel' => 'required|in:whatsapp,email',
        ]);

        $field = $data['channel'] . '_count';
        $contact->increment($field);
        $contact->update(['last_contacted_at' => now()]);

        return response()->json([
            'count'            => $contact->fresh()->$field,
            'last_contacted'   => now()->format('d M Y H:i'),
            'last_contacted_relative' => now()->diffForHumans(),
        ]);
    }

    public function destroy(Contact $contact)
    {
        $contact->delete();

        return redirect()->route('corex.contacts.index')->with('success', 'Contact deleted.');
    }

    public function destroyAll()
    {
        $count = Contact::withTrashed()->count();
        // Hard-delete related records, then hard-delete all contacts (including soft-deleted)
        \DB::table('contact_tag')->delete();
        \DB::table('contact_notes')->delete();
        Contact::withTrashed()->forceDelete();

        return redirect()->route('corex.contacts.index')->with('success', "{$count} contacts permanently deleted.");
    }

    public function syncTags(Request $request, Contact $contact)
    {
        $data = $request->validate([
            'tag_ids'   => 'nullable|array',
            'tag_ids.*' => 'integer|exists:contact_tags,id',
        ]);

        $contact->tags()->sync($data['tag_ids'] ?? []);

        return redirect()->route('corex.contacts.show', $contact)->with('success', 'Tags updated.');
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
