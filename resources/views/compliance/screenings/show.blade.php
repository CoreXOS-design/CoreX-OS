@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6" x-data="screeningShow()">
    <x-page-header :title="'Screening — ' . $screening->user->name" :back-route="route('compliance.screenings.index')" back-label="Screenings" :flush="true">
        <x-slot:actions>
            @if($screening->status === 'in_progress')
            <button @click="showFlag = true" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold transition" style="border:1px solid rgba(239,68,68,0.3); border-radius:3px; color:#ef4444;">Flag</button>
            @endif
            <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold" style="border-radius:3px; background:{{ $screening->status === 'completed' ? 'rgba(0,212,170,0.15)' : ($screening->status === 'flagged' ? 'rgba(239,68,68,0.15)' : 'rgba(234,179,8,0.15)') }}; color:{{ $screening->status === 'completed' ? '#00d4aa' : ($screening->status === 'flagged' ? '#ef4444' : '#eab308') }};">
                {{ ucfirst($screening->status) }}
            </span>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6">
        <div class="flex gap-6">
            {{-- Left: User summary --}}
            <div class="flex-shrink-0 hidden lg:block" style="width:240px;">
                <div class="bg-white border p-4" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                    <h3 class="text-xs font-bold uppercase mb-3" style="color:#94a3b8; letter-spacing:0.05em;">Staff Member</h3>
                    <div class="text-sm font-bold" style="color:#0f172a;">{{ $screening->user->name }}</div>
                    <div class="mt-2 space-y-1 text-xs" style="color:#64748b;">
                        <div>Role: {{ $screening->user->role }}</div>
                        <div>Designation: {{ $screening->user->designation ?? '-' }}</div>
                        <div>Risk Tier: <span class="font-semibold" style="color:{{ $screening->risk_tier === 'high' ? '#ef4444' : ($screening->risk_tier === 'medium' ? '#eab308' : '#00d4aa') }};">{{ ucfirst($screening->risk_tier) }}</span></div>
                        @if($screening->user->ffc_number) <div>FFC: {{ $screening->user->ffc_number }}</div> @endif
                        @if($screening->user->id_number) <div>ID: {{ $screening->user->id_number }}</div> @endif
                    </div>
                    <div class="mt-3 pt-3 space-y-1 text-xs" style="border-top:1px solid var(--border, #e5e7eb); color:#64748b;">
                        <div>Type: {{ \App\Models\Compliance\EmployeeScreening::$typeLabels[$screening->screening_type] ?? $screening->screening_type }}</div>
                        <div>Initiated: {{ $screening->initiated_on->format('d M Y') }}</div>
                        @if($screening->completed_on) <div>Completed: {{ $screening->completed_on->format('d M Y') }}</div> @endif
                        @if($screening->next_due_on) <div>Next due: {{ $screening->next_due_on->format('d M Y') }}</div> @endif
                    </div>
                </div>
            </div>

            {{-- Main: Checks --}}
            <div class="flex-1 min-w-0 space-y-3">
                {{-- Progress --}}
                <div class="flex items-center gap-3 mb-4">
                    <div class="flex-1 h-2 rounded-full overflow-hidden" style="background:rgba(0,212,170,0.15);">
                        <div class="h-full rounded-full transition-all" style="background:#00d4aa; width:{{ $screening->completionPercent() }}%;"></div>
                    </div>
                    <span class="text-xs font-semibold" style="color:#00d4aa;">{{ $screening->completionPercent() }}%</span>
                </div>

                @foreach($screening->checks->sortBy('id') as $check)
                <div class="bg-white border" style="border-color:var(--border, #e5e7eb); border-radius:3px;" x-data="{ open: {{ $check->result === 'pending' && $screening->status === 'in_progress' ? 'true' : 'false' }} }">
                    <div class="flex items-center justify-between px-4 py-3 cursor-pointer" @click="open = !open">
                        <div class="flex items-center gap-3">
                            <span class="w-2 h-2 rounded-full flex-shrink-0" style="background:{{ match($check->result) { 'clear' => '#00d4aa', 'concerns' => '#eab308', 'fail' => '#ef4444', 'not_applicable' => '#94a3b8', default => '#d1d5db' } }};"></span>
                            <span class="text-sm font-semibold" style="color:var(--text-primary, #1f2937);">
                                {{ \App\Models\Compliance\EmployeeScreeningCheck::$checkTypeLabels[$check->check_type] ?? $check->check_type }}
                            </span>
                        </div>
                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-semibold" style="border-radius:3px; background:{{ match($check->result) { 'clear' => 'rgba(0,212,170,0.15)', 'concerns' => 'rgba(234,179,8,0.15)', 'fail' => 'rgba(239,68,68,0.15)', 'not_applicable' => 'rgba(148,163,184,0.15)', default => 'rgba(209,213,219,0.3)' } }}; color:{{ match($check->result) { 'clear' => '#00d4aa', 'concerns' => '#eab308', 'fail' => '#ef4444', 'not_applicable' => '#94a3b8', default => '#9ca3af' } }};">
                            {{ \App\Models\Compliance\EmployeeScreeningCheck::$resultLabels[$check->result] ?? $check->result }}
                        </span>
                    </div>

                    <div x-show="open" x-cloak x-transition class="px-4 pb-4 pt-1 space-y-3" style="border-top:1px solid var(--border, #e5e7eb);">
                        @if($check->checked_on)
                        <div class="text-xs" style="color:#64748b;">
                            Checked {{ $check->checked_on->format('d M Y') }} by {{ $check->checker?->name ?? 'Unknown' }}
                            @if($check->reference_number) | Ref: {{ $check->reference_number }} @endif
                        </div>
                        @endif

                        @if($check->notes)
                        <div class="text-xs px-3 py-2 rounded" style="background:var(--surface-alt, #f8fafc); color:var(--text-primary, #1f2937);">{{ $check->notes }}</div>
                        @endif

                        @if($check->supportingDocument)
                        <div class="text-xs" style="color:#00d4aa;">
                            Document: {{ $check->supportingDocument->file_name }}
                        </div>
                        @endif

                        @if($screening->status === 'in_progress')
                        <div class="space-y-2">
                            <div class="grid grid-cols-2 gap-2">
                                <input type="text" placeholder="Reference number" class="px-2 py-1.5 text-xs border" style="border-color:var(--border, #e5e7eb); border-radius:3px;" x-ref="ref_{{ $check->id }}">
                                <textarea placeholder="Notes" rows="1" class="px-2 py-1.5 text-xs border" style="border-color:var(--border, #e5e7eb); border-radius:3px;" x-ref="notes_{{ $check->id }}">{{ $check->notes }}</textarea>
                            </div>
                            <div class="flex items-center gap-2">
                                <button @click="updateCheck({{ $check->id }}, 'clear')" class="px-2 py-1 text-[10px] font-semibold rounded" style="background:rgba(0,212,170,0.15); color:#00d4aa;">Clear</button>
                                <button @click="updateCheck({{ $check->id }}, 'concerns')" class="px-2 py-1 text-[10px] font-semibold rounded" style="background:rgba(234,179,8,0.15); color:#eab308;">Concerns</button>
                                <button @click="updateCheck({{ $check->id }}, 'fail')" class="px-2 py-1 text-[10px] font-semibold rounded" style="background:rgba(239,68,68,0.15); color:#ef4444;">Fail</button>
                                <button @click="updateCheck({{ $check->id }}, 'not_applicable')" class="px-2 py-1 text-[10px] font-semibold rounded" style="background:rgba(148,163,184,0.15); color:#94a3b8;">N/A</button>
                            </div>
                        </div>
                        @endif
                    </div>
                </div>
                @endforeach

                {{-- Complete screening --}}
                @if($screening->status === 'in_progress')
                <div class="bg-white border p-4 mt-4" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                    <h3 class="text-sm font-bold mb-3" style="color:#0f172a; font-family:'Plus Jakarta Sans',sans-serif;">Complete Screening</h3>
                    <form method="POST" action="{{ route('compliance.screenings.complete', $screening) }}" class="space-y-3">
                        @csrf
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:#64748b;">Overall Result *</label>
                                <select name="overall_result" required class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                                    <option value="">Select...</option>
                                    <option value="pass">Pass</option>
                                    <option value="concerns_flagged">Concerns Flagged</option>
                                    <option value="fail">Fail</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold mb-1" style="color:#64748b;">Summary Notes</label>
                                <textarea name="summary_notes" rows="2" class="w-full px-3 py-2 text-sm border" style="border-color:var(--border, #e5e7eb); border-radius:3px;"></textarea>
                            </div>
                        </div>
                        <button type="submit" class="px-4 py-2 text-sm font-semibold" style="background:#00d4aa; color:#0f172a; border-radius:3px;">Complete Screening</button>
                    </form>
                </div>
                @endif

                @if($screening->status === 'completed' && $screening->summary_notes)
                <div class="bg-white border p-4 mt-4" style="border-color:var(--border, #e5e7eb); border-radius:3px;">
                    <h3 class="text-xs font-bold uppercase mb-2" style="color:#94a3b8;">Summary</h3>
                    <div class="text-sm" style="color:var(--text-primary, #1f2937);">{{ $screening->summary_notes }}</div>
                    <div class="text-xs mt-2" style="color:#64748b;">Result: <strong>{{ $screening->overall_result }}</strong> | Completed by {{ $screening->completer?->name }} on {{ $screening->completed_on?->format('d M Y') }}</div>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Flag modal --}}
    <div x-show="showFlag" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center" style="background:rgba(0,0,0,0.5);">
        <form method="POST" action="{{ route('compliance.screenings.flag', $screening) }}" class="bg-white rounded p-6 w-full max-w-md mx-4" style="border-radius:3px;" @click.stop>
            @csrf
            <h3 class="text-sm font-bold mb-3" style="color:#0f172a;">Flag Screening</h3>
            <textarea name="summary_notes" rows="3" required placeholder="Describe the concerns..." class="w-full px-3 py-2 text-sm border mb-3" style="border-color:var(--border, #e5e7eb); border-radius:3px;"></textarea>
            <div class="flex items-center gap-2">
                <button type="submit" class="px-4 py-2 text-sm font-semibold" style="background:#ef4444; color:#fff; border-radius:3px;">Flag</button>
                <button type="button" @click="showFlag = false" class="px-4 py-2 text-sm" style="color:#6b7280;">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
function screeningShow() {
    return {
        showFlag: false,
        async updateCheck(checkId, result) {
            const refEl = this.$refs['ref_' + checkId];
            const notesEl = this.$refs['notes_' + checkId];
            try {
                const res = await fetch('/corex/compliance/screenings/check/' + checkId, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({
                        result: result,
                        reference_number: refEl?.value || null,
                        notes: notesEl?.value || null,
                    }),
                });
                if (res.ok) location.reload();
            } catch (e) { console.error(e); }
        }
    };
}
</script>
@endsection
