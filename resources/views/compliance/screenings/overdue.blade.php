@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Overdue Screenings" :back-route="route('compliance.screenings.index')" back-label="Screenings" :flush="true" />

    <div class="p-4 lg:p-6">
        <div class="overflow-x-auto" style="border:1px solid var(--border, #e5e7eb); border-radius:3px;">
            <table class="w-full text-sm" style="font-family:'Plus Jakarta Sans',sans-serif;">
                <thead>
                    <tr style="background:var(--surface-alt, #f8fafc); border-bottom:1px solid var(--border, #e5e7eb);">
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Staff Member</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Role</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Risk Tier</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Status</th>
                        <th class="px-4 py-3 text-left font-semibold" style="color:var(--text-secondary, #6b7280);">Due On</th>
                        <th class="px-4 py-3 text-right font-semibold" style="color:var(--text-secondary, #6b7280);">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($overdueUsers as $u)
                    <tr style="border-bottom:1px solid var(--border, #f1f5f9);">
                        <td class="px-4 py-3 font-semibold" style="color:var(--text-primary, #1f2937);">{{ $u->name }}</td>
                        <td class="px-4 py-3 text-xs" style="color:#64748b;">{{ $u->role }}</td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-semibold" style="color:{{ ($u->risk_tier ?? 'medium') === 'high' ? '#ef4444' : (($u->risk_tier ?? 'medium') === 'medium' ? '#eab308' : '#00d4aa') }};">{{ ucfirst($u->risk_tier ?? 'medium') }}</span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="background:rgba(239,68,68,0.15); color:#ef4444; border-radius:3px;">{{ str_replace('_', ' ', ucfirst($u->screening_status ?? 'never_screened')) }}</span>
                        </td>
                        <td class="px-4 py-3 text-xs" style="color:#ef4444;">{{ $u->screening_due_on ? \Carbon\Carbon::parse($u->screening_due_on)->format('d M Y') : 'Never' }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('compliance.screenings.create', $u) }}" class="text-xs font-semibold px-2 py-1" style="background:rgba(0,212,170,0.15); color:#00d4aa; border-radius:3px; text-decoration:none;">Start Screening</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center" style="color:#94a3b8;">No overdue screenings. All staff are up to date.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($overdueUsers->hasPages())
        <div class="mt-4">{{ $overdueUsers->links() }}</div>
        @endif
    </div>
</div>
@endsection
