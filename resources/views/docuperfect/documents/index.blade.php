@extends('layouts.nexus')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">My Documents</h2>
        <div class="text-sm text-white/60">All your filled documents. Create new ones from the <a href="{{ route('docuperfect.dashboard') }}" class="text-white/80 underline">Dashboard</a>.</div>
    </div>

    @if(session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('status') }}
        </div>
    @endif

    @if($documents->isEmpty())
        <div class="ds-status-card p-6 text-center">
            <div class="text-sm text-slate-500">No documents yet. <a href="{{ route('docuperfect.dashboard') }}" class="ds-link">Create one from a template</a>.</div>
        </div>
    @else
        <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
            <table class="w-full text-sm ds-table">
                <thead>
                    <tr>
                        <th class="text-left px-4 py-3">Name</th>
                        <th class="text-left px-4 py-3">Template</th>
                        <th class="text-left px-4 py-3">Last Edited</th>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <th class="text-left px-4 py-3">Agent</th>
                        @endif
                        @if($user->isAdmin())
                        <th class="text-left px-4 py-3">Branch</th>
                        @endif
                        <th class="text-right px-4 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($documents as $doc)
                    <tr>
                        <td class="px-4 py-3 font-medium text-slate-900">{{ $doc->name }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ $doc->template->name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-500">{{ $doc->updated_at->format('d M Y H:i') }}</td>
                        @if($user->isAdmin() || $user->isBranchManager())
                        <td class="px-4 py-3 text-slate-600">{{ $doc->owner->name ?? '—' }}</td>
                        @endif
                        @if($user->isAdmin())
                        <td class="px-4 py-3 text-slate-600">{{ $doc->branch->name ?? '—' }}</td>
                        @endif
                        <td class="px-4 py-3 text-right space-x-2">
                            <a href="{{ route('docuperfect.documents.edit', $doc->id) }}" class="ds-link text-sm">Edit</a>
                            <form method="POST" action="{{ route('docuperfect.documents.archive', $doc->id) }}" class="inline" onsubmit="return confirm('Archive this document?');">
                                @csrf
                                <button class="text-sm text-slate-400 hover:text-amber-600">Archive</button>
                            </form>
                            <form method="POST" action="{{ route('docuperfect.documents.destroy', $doc->id) }}" class="inline" onsubmit="return confirm('Permanently delete this document?');">
                                @csrf
                                @method('DELETE')
                                <button class="text-sm text-slate-400 hover:text-red-600">Delete</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

</div>
@endsection
