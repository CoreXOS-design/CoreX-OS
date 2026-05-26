@extends('layouts.corex')

@section('corex-content')
<div class="space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold" style="color:var(--text-primary);">Duplicate Cleanup Queue</h1>
        <div class="flex items-center gap-2">
            <span class="text-xs" style="color:var(--text-muted);">{{ $clusters->total() }} pending clusters</span>
        </div>
    </div>

    @if(session('success'))
        <div class="px-4 py-3 rounded-lg text-sm font-medium" style="background:rgba(16,185,129,0.1); color:#10b981; border:1px solid rgba(16,185,129,0.2);">
            {{ session('success') }}
        </div>
    @endif

    @if($clusters->isEmpty())
        <div class="corex-panel">
            <div class="corex-panel-body text-center py-8">
                <p class="text-sm" style="color:var(--text-muted);">No duplicate clusters detected.</p>
                <p class="text-xs mt-1" style="color:var(--text-muted);">
                    Run <code style="background:var(--surface-2); padding:2px 6px; border-radius:4px;">php artisan contacts:detect-duplicates</code> to scan for duplicates.
                </p>
            </div>
        </div>
    @else
        @foreach($clusters as $cluster)
            @php
                $contactIds = json_decode($cluster->contact_ids, true) ?? [];
                $clusterContacts = collect($contactIds)->map(fn($id) => $contacts->get($id))->filter();
            @endphp
            <div class="corex-panel">
                <div class="corex-panel-header">
                    <h3 class="corex-panel-title">
                        Cluster: {{ ucfirst($cluster->match_field) }} match
                        <span class="text-xs font-normal ml-2" style="color:var(--text-muted);">"{{ $cluster->match_value }}" · {{ count($contactIds) }} contacts</span>
                    </h3>
                    <span class="text-[10px] px-2 py-0.5 rounded" style="background:var(--ds-amber); color:#fff;">Pending</span>
                </div>
                <div class="corex-panel-body">
                    <div class="space-y-2 mb-4">
                        @foreach($clusterContacts as $contact)
                            <div class="flex items-center gap-3 p-2 rounded" style="background:var(--surface-2);">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                                     style="background:var(--brand-icon);">
                                    {{ strtoupper(substr($contact->first_name ?? '', 0, 1)) }}
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-sm font-medium truncate" style="color:var(--text-primary);">
                                        {{ $contact->full_name }}
                                    </div>
                                    <div class="text-xs" style="color:var(--text-muted);">
                                        {{ $contact->phone }} · {{ $contact->email ?? 'No email' }}
                                        · Owner: {{ $contact->createdBy?->name ?? 'Unknown' }}
                                    </div>
                                </div>
                                <a href="{{ route('corex.contacts.show', $contact) }}" class="text-xs font-medium" style="color:var(--brand-icon);">View</a>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex items-center gap-2">
                        <form method="POST" action="{{ route('command-center.admin.duplicate-cleanup.dismiss', $cluster->id) }}">
                            @csrf
                            <button type="submit" class="text-xs font-medium px-3 py-1.5 rounded-md hover:opacity-80"
                                    style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                                Not Duplicate
                            </button>
                        </form>
                        <button type="button" onclick="alert('Merge flow will be available in a follow-up release.')"
                                class="text-xs font-medium px-3 py-1.5 rounded-md hover:opacity-80"
                                style="background:var(--brand-button); color:#fff;">
                            Merge (Coming Soon)
                        </button>
                    </div>
                </div>
            </div>
        @endforeach

        <div class="mt-4">{{ $clusters->links() }}</div>
    @endif
</div>
@endsection
