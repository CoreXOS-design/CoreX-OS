@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Public Holidays" :flush="true">
        <x-slot:actions>
            <form method="GET" action="{{ route('payroll.leave.public-holidays.index') }}" class="inline-flex items-center gap-2">
                <select name="year" onchange="this.form.submit()" class="px-3 py-2 text-xs focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                    @foreach($years as $y)
                        <option value="{{ $y }}" {{ $year == $y ? 'selected' : '' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </form>
            <a href="{{ route('payroll.leave.public-holidays.create') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-white transition" style="background:#00d4aa; border-radius:6px;">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Holiday
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        @if(session('success'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(0,212,170,0.08); border:1px solid rgba(0,212,170,0.25); border-radius:6px; color:#00d4aa;">{{ session('success') }}</div>
        @endif

        <p class="text-xs mb-4" style="color:var(--text-secondary, #6b7280);">{{ $holidays->count() }} public holidays for South Africa in {{ $year }}. These are excluded from working day calculations.</p>

        @if($holidays->isEmpty())
            <div class="py-8 text-center text-xs" style="color:var(--text-secondary, #94a3b8);">No holidays seeded for {{ $year }}. Use the artisan command: <code>php artisan corex:seed-public-holidays {{ $year }}</code></div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border, #e5e7eb);">
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Date</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Day</th>
                            <th class="text-left px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Name</th>
                            <th class="text-center px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Type</th>
                            <th class="text-right px-3 py-2 text-xs font-bold uppercase" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($holidays as $h)
                        <tr style="border-bottom:1px solid var(--border, #e5e7eb);">
                            <td class="px-3 py-2.5 text-xs font-semibold" style="color:var(--text-primary, #0f172a);">{{ $h->holiday_date->format('d M Y') }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-secondary, #6b7280);">{{ $h->holiday_date->format('l') }}</td>
                            <td class="px-3 py-2.5 text-xs" style="color:var(--text-primary, #0f172a);">{{ $h->name }}</td>
                            <td class="px-3 py-2.5 text-center">
                                @if($h->is_movable)
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(234,179,8,0.1); color:#eab308; border-radius:6px;">Moveable</span>
                                @else
                                    <span class="px-1.5 py-0.5 text-[10px] font-semibold" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:6px;">Fixed</span>
                                @endif
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('payroll.leave.public-holidays.edit', $h) }}" class="text-xs font-semibold" style="color:#00d4aa;">Edit</a>
                                    <form method="POST" action="{{ route('payroll.leave.public-holidays.destroy', $h) }}" class="inline">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs font-semibold" style="color:#ef4444; background:none; border:none; cursor:pointer;" onclick="return confirm('Delete this holiday?')">Delete</button>
                                    </form>
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
