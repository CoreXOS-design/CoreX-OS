@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    {{-- Header --}}
    <div class="rounded-2xl px-6 py-4 flex items-center justify-between" style="background:var(--brand-default, #0b2a4a);">
        <div>
            <h2 class="text-xl font-bold text-white">Agency Management</h2>
            <div class="text-sm mt-0.5" style="color:rgba(255,255,255,0.6);">Create and manage all agencies on the platform.</div>
        </div>
        <a href="{{ route('agencies.create') }}"
           class="px-4 py-2 rounded-lg text-sm font-semibold text-white transition-colors"
           style="background:var(--brand-button, #0ea5e9);"
           onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
            + New Agency
        </a>
    </div>

    @if(session('success'))
        <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="background:#f0fdf4;border-color:#bbf7d0;color:#166534;">
            {{ session('success') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-xl border px-4 py-3 text-sm font-medium" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">
            {{ session('error') }}
        </div>
    @endif

    {{-- Agencies table --}}
    <div class="rounded-2xl border border-slate-200 bg-white overflow-hidden">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b" style="background:#f8fafc;">
                    <th class="text-left py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-default, #0b2a4a);">Agency</th>
                    <th class="text-left py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-default, #0b2a4a);">Slug</th>
                    <th class="text-center py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-default, #0b2a4a);">Branches</th>
                    <th class="text-center py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-default, #0b2a4a);">Users</th>
                    <th class="text-left py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-default, #0b2a4a);">Brand Colours</th>
                    <th class="text-center py-3 px-5 font-semibold text-xs uppercase tracking-wider" style="color:var(--brand-default, #0b2a4a);">Status</th>
                    <th class="py-3 px-5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($agencies as $agency)
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="py-3 px-5 font-semibold text-slate-800">{{ $agency->name }}</td>
                        <td class="py-3 px-5 text-slate-500 font-mono text-xs">{{ $agency->slug }}</td>
                        <td class="py-3 px-5 text-center text-slate-600">{{ $agency->branches_count }}</td>
                        <td class="py-3 px-5 text-center text-slate-600">{{ $agency->users_count }}</td>
                        <td class="py-3 px-5">
                            <div class="flex items-center gap-1.5">
                                <span class="inline-block w-5 h-5 rounded border border-slate-200 shadow-sm"
                                      style="background:{{ $agency->sidebar_color }}"
                                      title="Sidebar: {{ $agency->sidebar_color }}"></span>
                                <span class="inline-block w-5 h-5 rounded border border-slate-200 shadow-sm"
                                      style="background:{{ $agency->icon_color }}"
                                      title="Icons: {{ $agency->icon_color }}"></span>
                                <span class="inline-block w-5 h-5 rounded border border-slate-200 shadow-sm"
                                      style="background:{{ $agency->default_color }}"
                                      title="Default: {{ $agency->default_color }}"></span>
                                <span class="inline-block w-5 h-5 rounded border border-slate-200 shadow-sm"
                                      style="background:{{ $agency->button_color }}"
                                      title="Button: {{ $agency->button_color }}"></span>
                            </div>
                        </td>
                        <td class="py-3 px-5 text-center">
                            @if($agency->is_active)
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
                                      style="background:#dcfce7;color:#166534;">Active</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold"
                                      style="background:#fee2e2;color:#991b1b;">Inactive</span>
                            @endif
                        </td>
                        <td class="py-3 px-5 text-right">
                            <div class="flex items-center justify-end gap-3">
                                <a href="{{ route('agencies.edit', $agency) }}"
                                   class="text-xs font-semibold transition-colors"
                                   style="color:var(--brand-icon, #0ea5e9);"
                                   onmouseover="this.style.color='var(--brand-default, #0b2a4a)'" onmouseout="this.style.color='var(--brand-icon, #0ea5e9)'">
                                    Edit
                                </a>
                                <form method="POST" action="{{ route('agencies.toggle-active', $agency) }}"
                                      onsubmit="return confirm('{{ $agency->is_active ? 'Disable' : 'Enable' }} agency &quot;{{ $agency->name }}&quot;? @if($agency->is_active)Users in this agency will not be able to sign in until it is re-enabled.@endif');"
                                      class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs font-semibold transition-colors"
                                            style="color:{{ $agency->is_active ? '#b45309' : '#166534' }};">
                                        {{ $agency->is_active ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('agencies.destroy', $agency) }}"
                                      class="inline"
                                      onsubmit="
                                          if (!confirm('PERMANENTLY delete agency &quot;{{ $agency->name }}&quot;? This HARD-DELETES every user, branch, property, contact, deal, presentation and document in this agency. This cannot be undone.')) return false;
                                          var pw = prompt('Type the delete password to confirm:');
                                          if (pw === null) return false;
                                          this.querySelector('input[name=delete_password]').value = pw;
                                          return true;
                                      ">
                                    @csrf
                                    @method('DELETE')
                                    <input type="hidden" name="delete_password" value="">
                                    <button type="submit" class="text-xs font-semibold text-red-600 hover:text-red-700 transition-colors">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="py-10 text-center text-sm text-slate-400 italic">
                            No agencies yet. <a href="{{ route('agencies.create') }}" style="color:var(--brand-icon, #0ea5e9);">Create the first one.</a>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</div>
@endsection
