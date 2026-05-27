@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header --}}
    <div style="background:var(--brand-default, #0b2a4a); border-radius:16px; padding:20px 24px;">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">Developer Users</h2>
                <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">
                    {{ $users->count() }} platform user{{ $users->count() === 1 ? '' : 's' }} — visible across all agencies
                </div>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-xl px-4 py-3 text-sm font-medium" style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534;">
            {{ session('status') }}
        </div>
    @endif

    {{-- List --}}
    <div class="rounded-xl overflow-hidden" style="background:var(--surface-1); border:1px solid var(--border);">
        @if($users->isEmpty())
            <div class="px-6 py-10 text-center text-sm" style="color:var(--text-muted);">
                No Developer Users yet.
            </div>
        @else
            <table class="w-full text-sm">
                <thead style="background:var(--surface-2); color:var(--text-muted); text-transform:uppercase; font-size:11px; letter-spacing:0.05em;">
                    <tr>
                        <th class="text-left px-4 py-3 font-semibold">Name</th>
                        <th class="text-left px-4 py-3 font-semibold">Email</th>
                        <th class="text-left px-4 py-3 font-semibold">Role</th>
                        <th class="text-left px-4 py-3 font-semibold">Status</th>
                        <th class="text-right px-4 py-3 font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($users as $u)
                        <tr style="border-top:1px solid var(--border);">
                            <td class="px-4 py-3 font-medium" style="color:var(--text);">{{ $u->name }}</td>
                            <td class="px-4 py-3" style="color:var(--text-muted);">{{ $u->email }}</td>
                            <td class="px-4 py-3" style="color:var(--text-muted);">
                                {{ $roleLabels[$u->role] ?? $u->role }}
                            </td>
                            <td class="px-4 py-3">
                                @if($u->is_active)
                                    <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-semibold"
                                          style="background:#dcfce7; color:#166534;">Active</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-1 rounded-md text-xs font-semibold"
                                          style="background:#fee2e2; color:#991b1b;">Disabled</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" action="{{ route('admin.developer-users.toggle', $u->id) }}" class="inline">
                                    @csrf
                                    <button type="submit"
                                            class="px-3 py-1.5 rounded-md text-xs font-semibold transition-colors"
                                            style="background:var(--surface-2); color:var(--text); border:1px solid var(--border);"
                                            onmouseover="this.style.opacity='0.8'" onmouseout="this.style.opacity='1'">
                                        {{ $u->is_active ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
