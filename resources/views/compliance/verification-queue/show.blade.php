@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5" x-data="{ showRejectModal: false, rejectReason: '' }">

    {{-- Back link --}}
    <a href="{{ route('compliance.verification.index') }}" style="display:inline-flex; align-items:center; gap:6px; font-size:0.8rem; color:#00d4aa; text-decoration:none; font-weight:600; font-family:'Plus Jakarta Sans',sans-serif;">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px; height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
        Back to Queue
    </a>

    @if(session('success'))
        <div style="border-radius:3px; border:1px solid #bbf7d0; background:rgba(0,212,170,0.08); color:#00d4aa; padding:12px 16px; font-size:0.85rem; font-weight:500;">{{ session('success') }}</div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        {{-- Left: Document preview --}}
        <div class="lg:col-span-2" style="background:var(--surface); border:1px solid var(--border); border-radius:3px; overflow:hidden;">
            <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
                <h3 class="text-sm font-bold" style="color:var(--text-primary); font-family:'Plus Jakarta Sans',sans-serif;">Document Preview</h3>
            </div>

            <div style="padding:16px; min-height:400px;">
                @php
                    $filePath = $document->file_path;
                    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
                    $publicUrl = asset('storage/' . $filePath);
                @endphp

                @if(in_array($extension, ['pdf']))
                <iframe src="{{ $publicUrl }}" style="width:100%; height:600px; border:1px solid var(--border); border-radius:3px;"></iframe>
                @elseif(in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']))
                <div style="text-align:center;">
                    <img src="{{ $publicUrl }}" alt="{{ $document->file_name }}"
                         style="max-width:100%; max-height:600px; border-radius:3px; border:1px solid var(--border);">
                </div>
                @else
                <div style="text-align:center; padding:60px 20px;">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="#64748b" style="width:48px; height:48px; margin:0 auto 12px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                    <div style="font-size:0.85rem; color:var(--text-primary); margin-bottom:8px;">{{ $document->file_name }}</div>
                    <a href="{{ $publicUrl }}" download style="font-size:0.8rem; padding:8px 20px; border-radius:3px; background:#00d4aa; color:#0f172a; text-decoration:none; font-weight:700;">Download File</a>
                </div>
                @endif
            </div>
        </div>

        {{-- Right: Sidebar info + actions --}}
        <div class="space-y-4">

            {{-- Agent info --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:16px 20px;">
                <h4 style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin:0 0 12px;">Agent Info</h4>
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span style="font-size:0.75rem; color:var(--text-muted);">Name</span>
                        <span style="font-size:0.75rem; font-weight:600; color:var(--text-primary);">{{ $document->user->name ?? 'Unknown' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span style="font-size:0.75rem; color:var(--text-muted);">Branch</span>
                        <span style="font-size:0.75rem; color:var(--text-primary);">{{ $document->user->branch->name ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span style="font-size:0.75rem; color:var(--text-muted);">Designation</span>
                        <span style="font-size:0.75rem; color:var(--text-primary);">{{ $document->user->designation ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span style="font-size:0.75rem; color:var(--text-muted);">FFC Number</span>
                        <span style="font-size:0.75rem; color:var(--text-primary);">{{ $document->user->ffc_number ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span style="font-size:0.75rem; color:var(--text-muted);">Role</span>
                        <span style="font-size:0.75rem; color:var(--text-primary);">{{ ucfirst(str_replace('_', ' ', $document->user->role ?? 'agent')) }}</span>
                    </div>
                </div>
            </div>

            {{-- Document metadata --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:16px 20px;">
                <h4 style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin:0 0 12px;">Document Details</h4>
                <div class="space-y-2">
                    <div class="flex justify-between">
                        <span style="font-size:0.75rem; color:var(--text-muted);">Type</span>
                        <span style="font-size:0.7rem; font-weight:600; padding:2px 8px; border-radius:3px; background:rgba(0,212,170,0.08); color:#00d4aa;">{{ \App\Models\UserDocument::$documentTypeLabels[$document->document_type] ?? $document->document_type }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span style="font-size:0.75rem; color:var(--text-muted);">File name</span>
                        <span style="font-size:0.75rem; color:var(--text-primary); max-width:160px; text-align:right; word-break:break-all;">{{ $document->file_name }}</span>
                    </div>
                    @if($document->file_size)
                    <div class="flex justify-between">
                        <span style="font-size:0.75rem; color:var(--text-muted);">Size</span>
                        <span style="font-size:0.75rem; color:var(--text-primary);">{{ number_format($document->file_size / 1024, 1) }} KB</span>
                    </div>
                    @endif
                    @if($document->mime_type)
                    <div class="flex justify-between">
                        <span style="font-size:0.75rem; color:var(--text-muted);">Format</span>
                        <span style="font-size:0.75rem; color:var(--text-primary);">{{ $document->mime_type }}</span>
                    </div>
                    @endif
                    <div class="flex justify-between">
                        <span style="font-size:0.75rem; color:var(--text-muted);">Uploaded</span>
                        <span style="font-size:0.75rem; color:var(--text-primary);">{{ $document->created_at->format('d M Y H:i') }}</span>
                    </div>
                    @if($document->expiry_date)
                    @php $daysLeft = (int) now()->diffInDays($document->expiry_date, false); @endphp
                    <div class="flex justify-between">
                        <span style="font-size:0.75rem; color:var(--text-muted);">Expiry</span>
                        <span style="font-size:0.75rem; font-weight:600; color:{{ $daysLeft <= 0 ? '#ef4444' : ($daysLeft <= 60 ? '#f59e0b' : '#00d4aa') }};">
                            {{ $document->expiry_date->format('d M Y') }}
                            ({{ $daysLeft > 0 ? "in {$daysLeft} days" : 'EXPIRED' }})
                        </span>
                    </div>
                    @endif
                </div>
            </div>

            {{-- Status --}}
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:16px 20px;">
                @php
                    $statusConfig = [
                        'pending' => ['bg' => 'rgba(245,158,11,0.12)', 'color' => '#f59e0b', 'text' => 'Pending Verification'],
                        'verified' => ['bg' => 'rgba(0,212,170,0.12)', 'color' => '#00d4aa', 'text' => 'Verified'],
                        'rejected' => ['bg' => 'rgba(239,68,68,0.12)', 'color' => '#ef4444', 'text' => 'Rejected'],
                        'expired' => ['bg' => 'rgba(239,68,68,0.12)', 'color' => '#ef4444', 'text' => 'Expired'],
                    ];
                    $sc = $statusConfig[$document->status] ?? $statusConfig['pending'];
                @endphp
                <div class="flex items-center gap-3 mb-3">
                    <span style="font-size:0.75rem; font-weight:700; padding:3px 12px; border-radius:3px; background:{{ $sc['bg'] }}; color:{{ $sc['color'] }};">{{ $sc['text'] }}</span>
                </div>

                @if($document->status === 'verified')
                <div style="font-size:0.75rem; color:var(--text-muted);">
                    Verified by <strong style="color:var(--text-primary);">{{ $document->verifier->name ?? 'Unknown' }}</strong>
                    on {{ $document->verified_at?->format('d M Y H:i') }}
                </div>
                @endif

                @if($document->status === 'rejected')
                <div style="background:rgba(239,68,68,0.06); border:1px solid rgba(239,68,68,0.2); border-radius:3px; padding:10px 14px; margin-top:8px;">
                    <div style="font-size:0.7rem; font-weight:600; color:#ef4444; margin-bottom:4px;">Rejection Reason</div>
                    <div style="font-size:0.75rem; color:var(--text-primary);">{{ $document->rejected_reason }}</div>
                    <div style="font-size:0.65rem; color:var(--text-muted); margin-top:6px;">
                        Rejected by {{ $document->rejecter->name ?? 'Unknown' }} on {{ $document->rejected_at?->format('d M Y H:i') }}
                    </div>
                </div>
                @endif
            </div>

            {{-- Actions (only when pending) --}}
            @if($document->status === 'pending')
            <div style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:16px 20px;">
                <h4 style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.05em; margin:0 0 12px;">Actions</h4>

                <div class="space-y-2">
                    <form method="POST" action="{{ route('compliance.verification.verify', $document) }}">
                        @csrf
                        <button type="submit" style="width:100%; padding:10px 16px; border-radius:3px; border:none; background:#00d4aa; color:#0f172a; font-size:0.8rem; font-weight:700; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif;">Verify Document</button>
                    </form>

                    <button @click="showRejectModal = true" style="width:100%; padding:10px 16px; border-radius:3px; border:1px solid #ef4444; background:rgba(239,68,68,0.06); color:#ef4444; font-size:0.8rem; font-weight:600; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif;">Reject Document</button>

                    @if($document->expiry_date && $document->expiry_date->isPast())
                    <form method="POST" action="{{ route('compliance.verification.expire', $document) }}">
                        @csrf
                        <button type="submit" style="width:100%; padding:10px 16px; border-radius:3px; border:1px solid var(--border); background:transparent; color:var(--text-muted); font-size:0.8rem; font-weight:600; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif;">Mark as Expired</button>
                    </form>
                    @endif
                </div>
            </div>
            @endif
        </div>
    </div>

    {{-- Rejection modal --}}
    <div x-show="showRejectModal" x-cloak x-transition
         style="position:fixed; inset:0; z-index:9999; display:flex; align-items:center; justify-content:center; background:rgba(0,0,0,0.6);"
         @keydown.escape.window="showRejectModal = false">
        <div @click.outside="showRejectModal = false"
             style="background:var(--surface); border:1px solid var(--border); border-radius:3px; padding:24px; width:100%; max-width:480px;">
            <h3 style="font-size:0.9rem; font-weight:700; color:var(--text-primary); margin:0 0 6px; font-family:'Plus Jakarta Sans',sans-serif;">Reject Document</h3>
            <p style="font-size:0.75rem; color:var(--text-muted); margin:0 0 16px;">Provide a reason so the agent knows what to fix.</p>

            <form method="POST" action="{{ route('compliance.verification.reject', $document) }}">
                @csrf
                <textarea name="rejected_reason" x-model="rejectReason" rows="4" required maxlength="1000"
                          placeholder="FFC certificate is expired. Please upload a valid current certificate from your PPRA portal."
                          style="width:100%; border-radius:3px; border:1px solid var(--border); background:var(--surface-2); color:var(--text-primary); padding:10px 14px; font-size:0.8rem; box-sizing:border-box; resize:vertical; transition:border-color 200ms; font-family:'Plus Jakarta Sans',sans-serif;"
                          onfocus="this.style.borderColor='#ef4444'" onblur="this.style.borderColor='var(--border)'"></textarea>
                @error('rejected_reason') <p style="font-size:0.7rem; color:#ef4444; margin-top:3px;">{{ $message }}</p> @enderror

                <div class="flex items-center justify-end gap-3 mt-4">
                    <button type="button" @click="showRejectModal = false" style="padding:8px 16px; border-radius:3px; border:1px solid var(--border); background:transparent; color:var(--text-secondary); font-size:0.8rem; cursor:pointer;">Cancel</button>
                    <button type="submit" :disabled="rejectReason.trim() === ''" style="padding:8px 20px; border-radius:3px; border:none; background:#ef4444; color:#fff; font-size:0.8rem; font-weight:700; cursor:pointer; font-family:'Plus Jakarta Sans',sans-serif;">Reject</button>
                </div>
            </form>
        </div>
    </div>

</div>
@endsection
