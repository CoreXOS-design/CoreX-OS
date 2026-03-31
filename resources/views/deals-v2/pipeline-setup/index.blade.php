<x-app-layout>
    <div>
        <x-list-header
            title="Pipeline Setup"
            :form-action="route('deals-v2.pipeline.index')"
            :paginator="$templates"
            search-placeholder="Search templates..."
        >
            <x-slot:filters>
                <select name="deal_type" onchange="this.form.submit()" class="text-sm rounded-md px-3 py-2 focus:outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">All Types</option>
                    <option value="bond" {{ request('deal_type') === 'bond' ? 'selected' : '' }}>Bond Sale</option>
                    <option value="cash" {{ request('deal_type') === 'cash' ? 'selected' : '' }}>Cash Sale</option>
                    <option value="sale_of_2nd" {{ request('deal_type') === 'sale_of_2nd' ? 'selected' : '' }}>Sale of 2nd</option>
                </select>
            </x-slot:filters>
            <x-slot:actions>
                <a href="{{ route('deals-v2.pipeline.create') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors">
                    + New Template
                </a>
            </x-slot:actions>
        </x-list-header>

        <div class="p-4 lg:p-6">
            @if(session('status'))
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #34d399;">
                    {{ session('status') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171;">
                    {{ session('error') }}
                </div>
            @endif

            <div class="rounded-xl overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr style="border-bottom: 1px solid var(--border); background: var(--surface-2);">
                                <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Name</th>
                                <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Deal Type</th>
                                <th class="text-left px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                                <th class="text-center px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Steps</th>
                                <th class="text-center px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Default</th>
                                <th class="text-center px-4 py-2 text-xs font-medium uppercase tracking-wider" style="color: var(--text-muted);">Active</th>
                                <th class="text-right px-4 py-2 text-xs font-medium uppercase tracking-wider w-32" style="color: var(--text-muted);">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($templates as $tpl)
                                <tr class="transition-colors" style="border-bottom: 1px solid var(--border);"
                                    onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                                    <td class="px-4 py-2 font-medium" style="color: var(--text-primary);">
                                        <a href="{{ route('deals-v2.pipeline.edit', $tpl) }}" class="hover:underline">{{ $tpl->name }}</a>
                                    </td>
                                    <td class="px-4 py-2">
                                        @php
                                            $badgeColors = [
                                                'bond' => 'background:rgba(59,130,246,0.15);color:#60a5fa;',
                                                'cash' => 'background:rgba(16,185,129,0.15);color:#34d399;',
                                                'sale_of_2nd' => 'background:rgba(245,158,11,0.15);color:#fbbf24;',
                                            ];
                                            $labels = ['bond' => 'Bond', 'cash' => 'Cash', 'sale_of_2nd' => 'Sale of 2nd'];
                                        @endphp
                                        <span class="inline-block px-2 py-0.5 rounded text-xs font-medium" style="{{ $badgeColors[$tpl->deal_type] ?? '' }}">
                                            {{ $labels[$tpl->deal_type] ?? $tpl->deal_type }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2" style="color: var(--text-secondary);">{{ $tpl->branch?->name ?? 'All Branches' }}</td>
                                    <td class="px-4 py-2 text-center font-mono" style="color: var(--text-secondary);">{{ $tpl->steps_count }}</td>
                                    <td class="px-4 py-2 text-center">
                                        @if($tpl->is_default)
                                            <svg class="w-4 h-4 mx-auto" style="color: #34d399;" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2 text-center">
                                        <form method="POST" action="{{ route('deals-v2.pipeline.update', $tpl) }}" class="inline">
                                            @csrf @method('PUT')
                                            <input type="hidden" name="name" value="{{ $tpl->name }}">
                                            <input type="hidden" name="deal_type" value="{{ $tpl->deal_type }}">
                                            <input type="hidden" name="branch_id" value="{{ $tpl->branch_id }}">
                                            <input type="hidden" name="is_default" value="{{ $tpl->is_default ? '1' : '0' }}">
                                            <input type="hidden" name="is_active" value="{{ $tpl->is_active ? '0' : '1' }}">
                                            <button type="submit" class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors {{ $tpl->is_active ? 'bg-teal-600' : '' }}" style="{{ $tpl->is_active ? '' : 'background: var(--surface-2); border: 1px solid var(--border);' }}">
                                                <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform {{ $tpl->is_active ? 'translate-x-4' : 'translate-x-0.5' }}"></span>
                                            </button>
                                        </form>
                                    </td>
                                    <td class="px-4 py-2 text-right">
                                        <div class="flex items-center justify-end gap-1">
                                            <a href="{{ route('deals-v2.pipeline.edit', $tpl) }}" class="p-1 rounded hover:bg-white/10 transition-colors" style="color: var(--text-muted);" title="Edit">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125"/></svg>
                                            </a>
                                            <form method="POST" action="{{ route('deals-v2.pipeline.duplicate', $tpl) }}" class="inline">
                                                @csrf
                                                <button type="submit" class="p-1 rounded hover:bg-white/10 transition-colors" style="color: var(--text-muted);" title="Duplicate">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.5a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m0 0a2.625 2.625 0 1 1 5.25 0"/></svg>
                                                </button>
                                            </form>
                                            <form method="POST" action="{{ route('deals-v2.pipeline.destroy', $tpl) }}" onsubmit="return confirm('Archive this template?')">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="p-1 rounded hover:bg-red-500/20 transition-colors" style="color: var(--text-muted);" title="Archive">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center" style="color: var(--text-muted);">
                                        No pipeline templates found. <a href="{{ route('deals-v2.pipeline.create') }}" class="underline" style="color: #2dd4bf;">Create one</a>.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($templates->hasPages())
                    <div class="px-4 py-3" style="border-top: 1px solid var(--border);">
                        {{ $templates->links() }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
