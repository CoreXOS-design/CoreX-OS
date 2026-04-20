@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5"
     x-data="{ showVerified: false, showRejected: false, rejectingId: null, rejectReason: '' }">

    {{-- Page header --}}
    <div style="background:#0f172a; border-radius:3px; padding:20px 24px;">
        <h2 style="font-size:1.25rem; font-weight:800; color:#fff; margin:0 0 4px; font-family:'Plus Jakarta Sans',sans-serif;">Document Verification Queue</h2>
        <div style="font-size:0.8rem; color:rgba(255,255,255,0.5);">Review and verify agent compliance documents.</div>
    </div>

    @if(session('success'))
        <div style="border-radius:3px; border:1px solid #bbf7d0; background:rgba(0,212,170,0.08); color:#00d4aa; padding:12px 16px; font-size:0.85rem; font-weight:500;">{{ session('success') }}</div>
    @endif

    {{-- Stat cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:16px 20px;">
            <div class="flex items-center gap-3">
                <span style="width:10px; height:10px; border-radius:50%; background:#00d4aa; flex-shrink:0;"></span>
                <div>
                    <div style="font-size:1.5rem; font-weight:800; color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">{{ $pending->count() }}</div>
                    <div style="font-size:0.7rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Pending Verification</div>
                </div>
            </div>
        </div>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:16px 20px; cursor:pointer;" @click="showVerified = !showVerified">
            <div class="flex items-center gap-3">
                <span style="width:10px; height:10px; border-radius:50%; background:#64748b; flex-shrink:0;"></span>
                <div>
                    <div style="font-size:1.5rem; font-weight:800; color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">{{ $recentlyVerified->count() }}</div>
                    <div style="font-size:0.7rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Verified (7 days)</div>
                </div>
            </div>
        </div>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:16px 20px; cursor:pointer;" @click="showRejected = !showRejected">
            <div class="flex items-center gap-3">
                <span style="width:10px; height:10px; border-radius:50%; background:#ef4444; flex-shrink:0;"></span>
                <div>
                    <div style="font-size:1.5rem; font-weight:800; color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">{{ $recentlyRejected->count() }}</div>
                    <div style="font-size:0.7rem; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Rejected (7 days)</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Pending table --}}
    <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; overflow:hidden;">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">Pending Documents</h3>
        </div>

        @if($pending->isEmpty())
        <div class="p-8 text-center">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#00d4aa" style="width:40px; height:40px; margin:0 auto 12px;">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
            </svg>
            <div style="font-size:0.85rem; font-weight:600; color:var(--text-primary);">No documents pending verification.</div>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Queue is clear.</div>
        </div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border);">
                        <th style="text-align:left; padding:10px 16px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Agent</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Branch</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Document</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Uploaded</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">File</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Expiry</th>
                        <th style="text-align:right; padding:10px 16px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pending as $doc)
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:10px 16px;">
                            <div style="font-size:0.8rem; font-weight:600; color:var(--text-primary);">{{ $doc->user->name ?? 'Unknown' }}</div>
                        </td>
                        <td style="padding:10px 12px;">
                            <span style="font-size:0.75rem; color:var(--text-muted);">{{ $doc->user->branch->name ?? '-' }}</span>
                        </td>
                        <td style="padding:10px 12px;">
                            <span style="font-size:0.7rem; font-weight:600; padding:2px 8px; border-radius:3px; background:rgba(0,212,170,0.08); color:#00d4aa;">{{ \App\Models\UserDocument::$documentTypeLabels[$doc->document_type] ?? ucfirst(str_replace('_', ' ', $doc->document_type)) }}</span>
                        </td>
                        <td style="padding:10px 12px;">
                            <div style="font-size:0.75rem; color:var(--text-primary);">{{ $doc->created_at->format('d M Y') }}</div>
                            <div style="font-size:0.65rem; color:var(--text-muted);">{{ $doc->created_at->diffForHumans() }}</div>
                        </td>
                        <td style="padding:10px 12px;">
                            <div style="font-size:0.7rem; color:var(--text-muted);">{{ Str::limit($doc->file_name, 20) }}</div>
                            @if($doc->file_size)
                            <div style="font-size:0.6rem; color:var(--text-muted);">{{ number_format($doc->file_size / 1024, 0) }} KB</div>
                            @endif
                        </td>
                        <td style="padding:10px 12px;">
                            @if($doc->expiry_date)
                            @php $daysLeft = (int) now()->diffInDays($doc->expiry_date, false); @endphp
                            <span style="font-size:0.7rem; color:{{ $daysLeft <= 0 ? '#ef4444' : ($daysLeft <= 60 ? '#f59e0b' : 'var(--text-muted)') }};">
                                {{ $doc->expiry_date->format('d M Y') }}
                            </span>
                            @else
                            <span style="font-size:0.7rem; color:var(--text-muted);">-</span>
                            @endif
                        </td>
                        <td style="padding:10px 16px; text-align:right;">
                            <a href="{{ route('compliance.verification.show', $doc) }}" style="font-size:0.7rem; padding:5px 14px; border-radius:3px; background:#00d4aa; color:#0f172a; text-decoration:none; font-weight:700; font-family:'Plus Jakarta Sans',sans-serif;">Review</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- Recently verified (collapsible) --}}
    <div x-show="showVerified" x-cloak x-transition style="background:var(--surface); border:1px solid var(--border); border-radius:3px; overflow:hidden;">
        <div class="px-5 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">Recently Verified (7 days)</h3>
            <button @click="showVerified = false" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:0.75rem;">Close</button>
        </div>
        @if($recentlyVerified->isEmpty())
        <div class="p-6 text-center text-xs" style="color:var(--text-muted);">No documents verified in the last 7 days.</div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border);">
                        <th style="text-align:left; padding:10px 16px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Agent</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Document</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Verified By</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Verified At</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentlyVerified as $doc)
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:10px 16px; font-size:0.8rem; color:var(--text-primary);">{{ $doc->user->name ?? 'Unknown' }}</td>
                        <td style="padding:10px 12px;"><span style="font-size:0.7rem; font-weight:600; padding:2px 8px; border-radius:3px; background:rgba(0,212,170,0.08); color:#00d4aa;">{{ \App\Models\UserDocument::$documentTypeLabels[$doc->document_type] ?? $doc->document_type }}</span></td>
                        <td style="padding:10px 12px; font-size:0.75rem; color:var(--text-muted);">{{ $doc->verifier->name ?? '-' }}</td>
                        <td style="padding:10px 12px; font-size:0.75rem; color:var(--text-muted);">{{ $doc->verified_at?->format('d M Y H:i') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

    {{-- Recently rejected (collapsible) --}}
    <div x-show="showRejected" x-cloak x-transition style="background:var(--surface); border:1px solid var(--border); border-radius:3px; overflow:hidden;">
        <div class="px-5 py-3 flex items-center justify-between" style="border-bottom:1px solid var(--border);">
            <h3 class="text-sm font-bold" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">Recently Rejected (7 days)</h3>
            <button @click="showRejected = false" style="background:none; border:none; color:var(--text-muted); cursor:pointer; font-size:0.75rem;">Close</button>
        </div>
        @if($recentlyRejected->isEmpty())
        <div class="p-6 text-center text-xs" style="color:var(--text-muted);">No documents rejected in the last 7 days.</div>
        @else
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="border-bottom:1px solid var(--border);">
                        <th style="text-align:left; padding:10px 16px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Agent</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Document</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Rejected By</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Reason</th>
                        <th style="text-align:left; padding:10px 12px; font-size:0.65rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em;">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($recentlyRejected as $doc)
                    <tr style="border-bottom:1px solid var(--border);">
                        <td style="padding:10px 16px; font-size:0.8rem; color:var(--text-primary);">{{ $doc->user->name ?? 'Unknown' }}</td>
                        <td style="padding:10px 12px;"><span style="font-size:0.7rem; font-weight:600; padding:2px 8px; border-radius:3px; background:rgba(239,68,68,0.08); color:#ef4444;">{{ \App\Models\UserDocument::$documentTypeLabels[$doc->document_type] ?? $doc->document_type }}</span></td>
                        <td style="padding:10px 12px; font-size:0.75rem; color:var(--text-muted);">{{ $doc->rejecter->name ?? '-' }}</td>
                        <td style="padding:10px 12px; font-size:0.7rem; color:#ef4444; max-width:200px;">{{ Str::limit($doc->rejected_reason, 60) }}</td>
                        <td style="padding:10px 12px; font-size:0.75rem; color:var(--text-muted);">{{ $doc->rejected_at?->format('d M Y H:i') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>

</div>
@endsection
