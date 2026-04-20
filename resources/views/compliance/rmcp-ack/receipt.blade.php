@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Acknowledgement Receipt" :back-route="route('agent.portal')" back-label="My Portal" :flush="true">
        <x-slot:actions>
            <a href="{{ route('rmcp.ack.receipt.pdf', $ack) }}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="border:1px solid var(--border, #e5e7eb); border-radius:3px; color:var(--text-secondary, #6b7280);">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m0 0a48.1 48.1 0 0 1 10.5 0m-10.5 0V5.625A2.625 2.625 0 0 1 9.875 3h4.25a2.625 2.625 0 0 1 2.625 2.625v3.18"/></svg>
                Download PDF
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        <div class="max-w-2xl mx-auto">
            {{-- Success banner --}}
            @if($ack->isComplete())
            <div class="mb-4 px-4 py-3 text-sm font-semibold" style="background:rgba(0,212,170,0.1); border:1px solid rgba(0,212,170,0.3); border-radius:3px; color:#00d4aa;">
                RMCP Acknowledgement Complete
            </div>
            @endif

            <div class="bg-white border" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                {{-- Header --}}
                <div class="px-6 py-5 text-center" style="background:#0f172a; color:#fff;">
                    <div class="text-xs font-semibold uppercase" style="color:#00d4aa; letter-spacing:2px;">RMCP Acknowledgement Receipt</div>
                    <div class="text-xs mt-2" style="color:#94a3b8;">Ref: ACK-{{ str_pad($ack->id, 6, '0', STR_PAD_LEFT) }}</div>
                </div>

                <div class="px-6 py-5 space-y-4">
                    {{-- Details --}}
                    <div class="grid grid-cols-2 gap-3 text-sm" style="color:#64748b;">
                        <div>
                            <div class="text-xs font-semibold" style="color:#94a3b8;">Staff Member</div>
                            <div class="font-semibold" style="color:#0f172a;">{{ $ack->user->name }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold" style="color:#94a3b8;">RMCP Version</div>
                            <div class="font-semibold" style="color:#0f172a;">v{{ $ack->version->version_number }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold" style="color:#94a3b8;">Acknowledged On</div>
                            <div class="font-semibold" style="color:#0f172a;">{{ $ack->completed_at?->format('d F Y H:i') ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold" style="color:#94a3b8;">Valid Until</div>
                            <div class="font-semibold" style="color:#00d4aa;">{{ $ack->valid_until?->format('d F Y') ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold" style="color:#94a3b8;">IP Address</div>
                            <div style="color:#0f172a;">{{ $ack->ip_address ?? '-' }}</div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold" style="color:#94a3b8;">Sections Acknowledged</div>
                            <div style="color:#0f172a;">{{ $ack->sections_acknowledged_count }} of {{ $ack->sections_total_count }}</div>
                        </div>
                    </div>

                    {{-- Sections list --}}
                    <div>
                        <h4 class="text-xs font-bold uppercase mb-2" style="color:#94a3b8; letter-spacing:0.05em;">Acknowledged Sections</h4>
                        <div class="space-y-1">
                            @foreach($ack->sectionAcknowledgements->sortBy('section.display_order') as $sa)
                            <div class="flex items-center justify-between text-xs px-3 py-1.5" style="background:var(--surface-alt, #f8fafc); border-radius:3px;">
                                <span style="color:#0f172a;">{{ $sa->section->section_number }}. {{ $sa->section->title }}</span>
                                @if($sa->acknowledged)
                                <span style="color:#00d4aa;">{{ $sa->acknowledged_at?->format('H:i') }}</span>
                                @else
                                <span style="color:#94a3b8;">-</span>
                                @endif
                            </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Signature --}}
                    @if($ack->signature_path)
                    <div>
                        <h4 class="text-xs font-bold uppercase mb-2" style="color:#94a3b8; letter-spacing:0.05em;">Signature</h4>
                        @if($ack->signature_type === 'typed')
                        <div class="px-4 py-3 text-center" style="border:1px dashed var(--border, #e5e7eb); border-radius:3px;">
                            <span style="font-family:'Dancing Script',cursive; font-size:1.5rem; color:#0f172a;">{{ $ack->typed_signature_name }}</span>
                        </div>
                        @elseif($ack->signature_type === 'drawn')
                        <div class="px-4 py-3 text-center" style="border:1px dashed var(--border, #e5e7eb); border-radius:3px;">
                            <img src="{{ Storage::url($ack->signature_path) }}" alt="Signature" style="max-height:80px; margin:0 auto;">
                        </div>
                        @endif
                    </div>
                    @endif
                </div>

                {{-- Footer --}}
                <div class="px-6 py-3 text-center text-xs" style="border-top:1px solid var(--border, #e5e7eb); color:#94a3b8;">
                    This receipt serves as proof of RMCP acknowledgement for FICA compliance audit purposes.
                </div>
            </div>
        </div>
    </div>
</div>

<link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&display=swap" rel="stylesheet">
@endsection
