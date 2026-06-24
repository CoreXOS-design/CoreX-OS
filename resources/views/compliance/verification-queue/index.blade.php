@extends('layouts.corex-app')

@section('corex-content')
<div class="space-y-6" x-data="{ showVerified: false, showRejected: false }">

    {{-- Page header (Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);" data-tour="comp-verification-intro">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Document Verification Queue</h1>
                <p class="text-sm text-white/60">Review and verify agent compliance documents.</p>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color: var(--ds-green);"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <div class="flex-1">{{ session('success') }}</div>
        </div>
    @endif

    {{-- Stat cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4" data-tour="comp-verification-stats">
        <div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--ds-amber);">{{ number_format($pending->count()) }}</div>
            <div class="text-xs font-semibold mt-1 uppercase tracking-wider" style="color: var(--text-muted);">Pending Verification</div>
        </div>
        <button type="button" @click="showVerified = !showVerified"
                class="rounded-md p-4 text-left transition-colors"
                style="background: var(--surface); border: 1px solid var(--border); cursor: pointer;">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--ds-green);">{{ number_format($recentlyVerified->count()) }}</div>
            <div class="text-xs font-semibold mt-1 uppercase tracking-wider" style="color: var(--text-muted);">Verified (7 days)</div>
        </button>
        <button type="button" @click="showRejected = !showRejected"
                class="rounded-md p-4 text-left transition-colors"
                style="background: var(--surface); border: 1px solid var(--border); cursor: pointer;">
            <div class="text-[1.625rem] font-semibold leading-tight" style="color: var(--ds-crimson);">{{ number_format($recentlyRejected->count()) }}</div>
            <div class="text-xs font-semibold mt-1 uppercase tracking-wider" style="color: var(--text-muted);">Rejected (7 days)</div>
        </button>
    </div>

    {{-- Pending --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);" data-tour="comp-verification-pending">
        <div class="px-5 py-3" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Pending Documents</h3>
        </div>

        @if($pending->isEmpty())
            <div class="py-12 px-6 text-center">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); color: var(--ds-green);">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No documents pending verification</h3>
                <p class="text-sm" style="color: var(--text-muted);">Queue is clear.</p>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Branch</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Document</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Uploaded</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">File</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Expiry</th>
                            <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pending as $doc)
                        <tr class="transition-colors" style="border-top: 1px solid var(--border);"
                            onmouseover="this.style.background='var(--surface-2)'"
                            onmouseout="this.style.background=''">
                            <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $doc->user->name ?? 'Unknown' }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $doc->user->branch->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="ds-badge ds-badge-info">{{ \App\Models\UserDocument::$documentTypeLabels[$doc->document_type] ?? ucfirst(str_replace('_', ' ', $doc->document_type)) }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-xs" style="color: var(--text-primary);">{{ $doc->created_at->format('d M Y') }}</div>
                                <div class="text-[0.6875rem]" style="color: var(--text-muted);">{{ $doc->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="text-xs" style="color: var(--text-secondary);">{{ Str::limit($doc->file_name, 20) }}</div>
                                @if($doc->file_size)
                                    <div class="text-[0.6875rem]" style="color: var(--text-muted);">{{ number_format($doc->file_size / 1024, 0) }} KB</div>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @if($doc->expiry_date)
                                    @php $daysLeft = (int) now()->diffInDays($doc->expiry_date, false); @endphp
                                    <span class="text-xs" style="color: {{ $daysLeft <= 0 ? 'var(--ds-crimson)' : ($daysLeft <= 60 ? 'var(--ds-amber)' : 'var(--text-secondary)') }};">
                                        {{ $doc->expiry_date->format('d M Y') }}
                                    </span>
                                @else
                                    <span class="text-xs" style="color: var(--text-muted);">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                <a href="{{ route('compliance.verification.show', $doc) }}" class="corex-btn-primary px-3 py-1 text-xs">Review</a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Recently verified (collapsible) --}}
    <div x-show="showVerified" x-cloak x-transition class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Recently Verified (7 days)</h3>
            <button type="button" @click="showVerified = false" class="text-xs font-semibold" style="color: var(--text-muted); background: none; border: none; cursor: pointer;">Close</button>
        </div>
        @if($recentlyVerified->isEmpty())
            <div class="py-8 px-6 text-center text-sm" style="color: var(--text-muted);">No documents verified in the last 7 days.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Document</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Verified By</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Verified At</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentlyVerified as $doc)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $doc->user->name ?? 'Unknown' }}</td>
                            <td class="px-4 py-3"><span class="ds-badge ds-badge-success">{{ \App\Models\UserDocument::$documentTypeLabels[$doc->document_type] ?? $doc->document_type }}</span></td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $doc->verifier->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $doc->verified_at?->format('d M Y H:i') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Recently rejected (collapsible) --}}
    <div x-show="showRejected" x-cloak x-transition class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="px-5 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <h3 class="text-lg font-semibold" style="color: var(--text-primary);">Recently Rejected (7 days)</h3>
            <button type="button" @click="showRejected = false" class="text-xs font-semibold" style="color: var(--text-muted); background: none; border: none; cursor: pointer;">Close</button>
        </div>
        @if($recentlyRejected->isEmpty())
            <div class="py-8 px-6 text-center text-sm" style="color: var(--text-muted);">No documents rejected in the last 7 days.</div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm ds-table">
                    <thead>
                        <tr style="background: var(--surface-2);">
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Agent</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Document</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Rejected By</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Reason</th>
                            <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentlyRejected as $doc)
                        <tr style="border-top: 1px solid var(--border);">
                            <td class="px-4 py-3 font-semibold" style="color: var(--text-primary);">{{ $doc->user->name ?? 'Unknown' }}</td>
                            <td class="px-4 py-3"><span class="ds-badge ds-badge-danger">{{ \App\Models\UserDocument::$documentTypeLabels[$doc->document_type] ?? $doc->document_type }}</span></td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $doc->rejecter->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-primary); max-width: 200px;">{{ Str::limit($doc->rejected_reason, 60) }}</td>
                            <td class="px-4 py-3 text-xs" style="color: var(--text-secondary);">{{ $doc->rejected_at?->format('d M Y H:i') }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

</div>
@endsection
