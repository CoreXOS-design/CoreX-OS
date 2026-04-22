@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Document Types" :flush="true">
        <x-slot:actions>
            <a href="{{ route('compliance.document-types.create') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-white transition" style="background:#00d4aa; border-radius:3px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Type
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        <p class="text-xs mb-4" style="color:var(--text-secondary, #6b7280);">Configure which compliance documents your agency maintains. Each type can have its own expiry and renewal rules.</p>

        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); border-radius:3px; color:#00d4aa;">{{ session('success') }}</div>
        @endif

        {{-- Filter tabs --}}
        <div class="flex gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);">
            @foreach(['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $key => $label)
                <a href="{{ route('compliance.document-types.index', ['filter' => $key]) }}"
                   class="px-3 py-1.5 text-xs font-semibold transition"
                   style="{{ $filter === $key ? 'border-bottom:2px solid #00d4aa; color:#00d4aa;' : 'color:var(--text-secondary, #6b7280);' }}">
                    {{ $label }} <span class="ml-1 text-[10px] opacity-60">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </div>

        @if($types->isEmpty())
            <div class="py-12 text-center text-sm" style="color:var(--text-secondary, #6b7280);">
                No document types {{ $filter === 'active' ? 'configured yet' : 'found' }}. Click <strong>Add Type</strong> to define your first.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Name</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Slug</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Expiry</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Renewal Reminder</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Required</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Status</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($types as $type)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb); {{ !$type->is_active ? 'opacity:0.5;' : '' }}">
                            <td class="px-3 py-2.5 font-semibold" style="color:var(--text-primary, #0f172a);">{{ $type->name }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #94a3b8); font-family:monospace;">{{ $type->slug }}</td>
                            <td class="px-3 py-2.5 text-center text-xs">
                                @if($type->has_expiry)
                                    <span style="color:#00d4aa;">Tracked</span>
                                @else
                                    <span style="color:var(--text-secondary, #94a3b8);">None</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-center text-xs" style="color:var(--text-secondary, #6b7280);">
                                @if($type->renewal_days)
                                    {{ $type->renewal_days }} days before
                                @else
                                    No auto-reminder
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-center text-xs">
                                @if($type->required)
                                    <span class="px-1.5 py-0.5 font-semibold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:3px;">Required</span>
                                @else
                                    <span style="color:var(--text-secondary, #94a3b8);">Optional</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-center text-xs">
                                @if($type->is_active)
                                    <span class="px-1.5 py-0.5 font-semibold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:3px;">Active</span>
                                @else
                                    <span class="px-1.5 py-0.5 font-semibold" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:3px;">Archived</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('compliance.document-types.edit', $type) }}" class="text-xs font-semibold" style="color:#00d4aa;">Edit</a>
                                    @if($type->is_active)
                                        <form method="POST" action="{{ route('compliance.document-types.archive', $type) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold" style="color:var(--text-secondary, #94a3b8); background:none; border:none; cursor:pointer;" onclick="return confirm('Archive this document type?')">Archive</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('compliance.document-types.restore', $type) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs font-semibold" style="color:#00d4aa; background:none; border:none; cursor:pointer;">Restore</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
