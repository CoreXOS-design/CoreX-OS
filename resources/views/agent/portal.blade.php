@extends('layouts.corex')

@section('corex-content')
<div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    {{-- Page header --}}
    <div style="background:var(--brand-default, #0b2a4a); border-radius:6px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px;">My Portal</h2>
        <div style="font-size:0.875rem; color:rgba(255,255,255,0.55);">Your compliance, documents, and profile at a glance.</div>
    </div>

    @if(session('success'))
        <div class="rounded-md border px-4 py-3 text-sm font-medium" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">{{ session('success') }}</div>
    @endif

    {{-- ══════════════════════════════════════
         PROFILE COMPLETENESS
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Profile Completeness</h3>
            <span class="text-sm font-bold" style="color:{{ $profilePercent === 100 ? '#22c55e' : ($profilePercent >= 75 ? '#f59e0b' : '#ef4444') }};">{{ $profilePercent }}%</span>
        </div>
        <div class="h-2 rounded-full overflow-hidden mb-4" style="background:var(--border);">
            <div class="h-full rounded-full transition-all" style="width:{{ $profilePercent }}%; background:{{ $profilePercent === 100 ? '#22c55e' : '#0ea5e9' }};"></div>
        </div>

        @if($profilePercent < 100)
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            @foreach($profileFields as $field)
            <div class="flex items-center gap-2 py-1">
                @if(!empty($field['value']))
                <svg class="w-4 h-4 flex-shrink-0" style="color:#22c55e;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                <span class="text-xs" style="color:var(--text-muted);">{{ $field['label'] }}</span>
                @else
                <svg class="w-4 h-4 flex-shrink-0" style="color:#ef4444;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                <span class="text-xs font-medium" style="color:#ef4444;">{{ $field['label'] }}</span>
                @endif
            </div>
            @endforeach
        </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">

        {{-- ══════════════════════════════════════
             COMPLIANCE STATUS
             ══════════════════════════════════════ --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">My Compliance Status</h3>

            @php $dotColors = ['green' => '#22c55e', 'amber' => '#f59e0b', 'red' => '#ef4444']; @endphp

            <div class="space-y-3">
                {{-- FFC --}}
                <div class="flex items-center justify-between py-2 px-3 rounded-md" style="border:1px solid var(--border);">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $dotColors[$ffcStatus['status']] }};"></span>
                        <div>
                            <div class="text-xs font-semibold" style="color:var(--text-primary);">FFC Certificate</div>
                            <div class="text-[10px]" style="color:var(--text-muted);">{{ $ffcStatus['label'] }}</div>
                        </div>
                    </div>
                    @if($ffcStatus['status'] === 'red')
                    <span class="text-[10px] px-2 py-0.5 rounded" style="background:rgba(239,68,68,0.12); color:#ef4444;">Upload below</span>
                    @endif
                </div>

                {{-- Training courses --}}
                @foreach($trainingItems as $item)
                <div class="flex items-center justify-between py-2 px-3 rounded-md" style="border:1px solid var(--border);">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $dotColors[$item['status']] }};"></span>
                        <div>
                            <div class="text-xs font-semibold" style="color:var(--text-primary);">{{ $item['title'] }}</div>
                            <div class="text-[10px]" style="color:var(--text-muted);">{{ $item['label'] }}</div>
                        </div>
                    </div>
                    @if($item['status'] !== 'green')
                    <a href="{{ route('training.show', $item['id']) }}" class="text-[10px] px-2 py-0.5 rounded no-underline" style="background:rgba(14,165,233,0.12); color:#0ea5e9;">Continue</a>
                    @endif
                </div>
                @endforeach

                {{-- RMCP --}}
                <div class="flex items-center justify-between py-2 px-3 rounded-md" style="border:1px solid var(--border);">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0" style="background:{{ $dotColors[$rmcpStatus] }};"></span>
                        <div>
                            <div class="text-xs font-semibold" style="color:var(--text-primary);">RMCP Acknowledgement</div>
                            <div class="text-[10px]" style="color:var(--text-muted);">{{ $rmcpLabel }}</div>
                        </div>
                    </div>
                    @if($rmcpStatus === 'red' && $rmcpCourse)
                    <a href="{{ route('training.show', $rmcpCourse) }}" class="text-[10px] px-2 py-0.5 rounded no-underline" style="background:rgba(14,165,233,0.12); color:#0ea5e9;">Read RMCP</a>
                    @endif
                </div>
            </div>
        </div>

        {{-- ══════════════════════════════════════
             EARNINGS SNAPSHOT
             ══════════════════════════════════════ --}}
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
            <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">My Earnings</h3>

            <div class="grid grid-cols-2 gap-3 mb-4">
                <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">This Month</div>
                    <div class="text-lg font-extrabold" style="color:var(--text-primary);">R {{ number_format($thisMonthEarnings, 2) }}</div>
                </div>
                <div class="p-3 rounded-md" style="background:var(--surface-2); border:1px solid var(--border);">
                    <div class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--text-muted);">This Year</div>
                    <div class="text-lg font-extrabold" style="color:var(--text-primary);">R {{ number_format($thisYearEarnings, 2) }}</div>
                </div>
            </div>

            {{-- Cap bar --}}
            <div class="mb-3">
                <div class="flex items-center justify-between mb-1">
                    <span class="text-xs" style="color:var(--text-muted);">Cap Progress</span>
                    <span class="text-xs font-bold" style="color:{{ $isCapped ? '#f59e0b' : 'var(--text-primary)' }};">
                        {{ $isCapped ? 'CAPPED' : $capPercent . '%' }}
                    </span>
                </div>
                <div class="h-2 rounded-full overflow-hidden" style="background:var(--border);">
                    <div class="h-full rounded-full" style="width:{{ $capPercent }}%; background:{{ $isCapped ? '#f59e0b' : '#0ea5e9' }};"></div>
                </div>
            </div>

            <a href="{{ route('commission.dashboard') }}" class="text-xs font-medium no-underline" style="color:#0ea5e9;">View Full Earnings &rarr;</a>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         RECENT ACTIVITY
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary);">Recent Activity</h3>
        </div>
        @if($recentActivity->isEmpty())
            <div class="p-6 text-center text-xs" style="color:var(--text-muted);">No commission entries yet.</div>
        @else
        <div class="divide-y" style="border-color:var(--border);">
            @foreach($recentActivity as $tx)
            <div class="flex items-center justify-between px-5 py-2.5">
                <div class="flex items-center gap-3 min-w-0">
                    <span class="text-xs whitespace-nowrap" style="color:var(--text-muted);">{{ $tx->deal_date ? $tx->deal_date->format('d M') : $tx->created_at->format('d M') }}</span>
                    <span class="text-sm truncate" style="color:var(--text-primary);">{{ \Illuminate\Support\Str::limit($tx->description, 40) }}</span>
                </div>
                <div class="flex items-center gap-3 flex-shrink-0">
                    <span class="text-sm font-semibold" style="color:var(--text-primary);">R {{ number_format($tx->net_agent_amount, 2) }}</span>
                    @php
                        $sBadge = match($tx->status) {
                            'pending' => ['bg' => 'rgba(245,158,11,0.12)', 'color' => '#f59e0b'],
                            'confirmed' => ['bg' => 'rgba(59,130,246,0.12)', 'color' => '#3b82f6'],
                            'paid' => ['bg' => 'rgba(34,197,94,0.12)', 'color' => '#22c55e'],
                            default => ['bg' => 'rgba(148,163,184,0.12)', 'color' => '#94a3b8'],
                        };
                    @endphp
                    <span class="px-1.5 py-0.5 rounded text-[10px] font-semibold" style="background:{{ $sBadge['bg'] }}; color:{{ $sBadge['color'] }};">{{ ucfirst($tx->status) }}</span>
                </div>
            </div>
            @endforeach
        </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════
         MY DOCUMENTS
         ══════════════════════════════════════ --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:6px; padding:20px 24px;">
        <h3 class="text-sm font-bold mb-4" style="color:var(--text-primary);">My Documents</h3>

        <div class="space-y-3">
            @foreach($docTypes as $doc)
            <div class="flex items-center justify-between py-2 px-3 rounded-md" style="border:1px solid var(--border);">
                <div class="flex items-center gap-2">
                    @if(!empty($doc['value']))
                    <svg class="w-4 h-4 flex-shrink-0" style="color:#22c55e;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" /></svg>
                    @else
                    <svg class="w-4 h-4 flex-shrink-0" style="color:#ef4444;" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                    @endif
                    <span class="text-xs font-medium" style="color:var(--text-primary);">{{ $doc['label'] }}</span>
                </div>

                <div class="flex items-center gap-2">
                    @if(!empty($doc['value']))
                    <a href="{{ asset('storage/' . $doc['value']) }}" target="_blank" class="text-[10px] px-2 py-0.5 rounded no-underline" style="color:var(--text-muted); border:1px solid var(--border);">View</a>
                    @endif

                    <form method="POST" action="{{ route('agent.portal.upload') }}" enctype="multipart/form-data"
                          class="flex items-center gap-1" x-data="{ fileName: '' }">
                        @csrf
                        <input type="hidden" name="document_type" value="{{ $doc['type'] }}">
                        <label class="text-[10px] px-2 py-0.5 rounded cursor-pointer no-underline"
                               style="background:rgba(14,165,233,0.12); color:#0ea5e9; border:1px solid rgba(14,165,233,0.25);">
                            {{ empty($doc['value']) ? 'Upload' : 'Replace' }}
                            <input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" class="hidden"
                                   @change="fileName = $event.target.files[0]?.name || ''; if(fileName) $el.closest('form').submit();">
                        </label>
                    </form>
                </div>
            </div>
            @endforeach
        </div>
    </div>

</div>
@endsection
