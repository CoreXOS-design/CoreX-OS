@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Staff Take-On" :flush="true">
        <x-slot:actions>
            <a href="{{ route('staff-take-on.create') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-white transition" style="background:#00d4aa; border-radius:6px;" onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Start New Take-On
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); border-radius:6px; color:#00d4aa;">{{ session('success') }}</div>
        @endif

        <div class="flex gap-1 mb-4" style="border-bottom:1px solid var(--border, #e5e7eb);">
            @foreach(['all' => 'All', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'this_month' => 'This Month'] as $key => $label)
                <a href="{{ route('staff-take-on.index', ['status' => $key]) }}"
                   class="px-3 py-1.5 text-xs font-semibold transition"
                   style="{{ $status === $key ? 'border-bottom:2px solid #00d4aa; color:#00d4aa;' : 'color:var(--text-secondary, #6b7280);' }}">
                    {{ $label }} <span class="ml-1 text-[10px] opacity-60">{{ $counts[$key] }}</span>
                </a>
            @endforeach
        </div>

        @if($records->isEmpty())
            <div class="py-12 text-center text-sm" style="color:var(--text-secondary, #6b7280);">No take-on records yet. Click <strong>+ Start New Take-On</strong> to onboard a new employee.</div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Employee</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Type</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Progress</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Started</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Status</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($records as $rec)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-3 py-2.5 font-semibold" style="color:var(--text-primary, #0f172a);">{{ $rec->user->name ?? 'Unknown' }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ ucfirst(str_replace('_', ' ', $rec->take_on_type)) }}</td>
                            <td class="px-3 py-2.5 text-center">
                                <div class="flex items-center justify-center gap-2">
                                    <div class="w-24 h-1.5 rounded-full" style="background:var(--border, #e5e7eb);">
                                        <div class="h-1.5 rounded-full" style="background:#00d4aa; width:{{ $rec->progressPercentage() }}%;"></div>
                                    </div>
                                    <span class="text-[10px] font-semibold" style="color:var(--text-secondary, #6b7280);">{{ $rec->progressPercentage() }}%</span>
                                </div>
                            </td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $rec->created_at?->format('d M Y') }}</td>
                            <td class="px-3 py-2.5 text-center">
                                @if($rec->isComplete())
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(0,212,170,0.1); color:#00d4aa; border-radius:6px;">Completed</span>
                                @else
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(234,179,8,0.1); color:#eab308; border-radius:6px;">Step {{ array_search($rec->current_step, ['user','personal','tax_banking','employment','compensation','leave','compliance','review']) + 1 }} of 8</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                @if($rec->isComplete())
                                    <a href="{{ route('staff-take-on.wizard', [$rec, 'review']) }}" class="text-xs font-semibold" style="color:#00d4aa;">View</a>
                                @else
                                    <a href="{{ route('staff-take-on.wizard', [$rec, $rec->nextStep() ?? 'review']) }}" class="text-xs font-semibold" style="color:#00d4aa;">Resume</a>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $records->links() }}</div>
        @endif
    </div>
</div>
@endsection
