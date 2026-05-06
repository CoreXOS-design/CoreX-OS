{{-- Step 8: Review & Sign-Off --}}
@php $pe = $takeOn->payrollEmployee; @endphp

<div class="space-y-4">
    <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">8. Review & Sign-Off</h4>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
            <div>
                <strong style="color:var(--text-secondary, #94a3b8);">Employee:</strong>
                <p style="color:var(--text-primary, #0f172a);">{{ $takeOn->user->name }} ({{ $takeOn->user->email }})</p>
            </div>
            <div>
                <strong style="color:var(--text-secondary, #94a3b8);">Take-On Type:</strong>
                <p style="color:var(--text-primary, #0f172a);">{{ ucfirst(str_replace('_', ' ', $takeOn->take_on_type)) }}</p>
            </div>
            <div>
                <strong style="color:var(--text-secondary, #94a3b8);">Employment Date:</strong>
                <p style="color:var(--text-primary, #0f172a);">{{ $takeOn->original_employment_start_date?->format('d M Y') }}</p>
            </div>
            <div>
                <strong style="color:var(--text-secondary, #94a3b8);">Designation:</strong>
                <p style="color:var(--text-primary, #0f172a);">{{ $pe?->designation_snapshot ?? '-' }}</p>
            </div>
            <div>
                <strong style="color:var(--text-secondary, #94a3b8);">Branch:</strong>
                <p style="color:var(--text-primary, #0f172a);">{{ $takeOn->user->branch->name ?? '-' }}</p>
            </div>
            <div>
                <strong style="color:var(--text-secondary, #94a3b8);">Working Pattern:</strong>
                <p style="color:var(--text-primary, #0f172a);">{{ ucfirst(str_replace('_', ' ', $pe?->working_pattern ?? '-')) }} ({{ $pe?->working_days_per_week ?? 5 }}-day)</p>
            </div>
            @if($pe)
            <div>
                <strong style="color:var(--text-secondary, #94a3b8);">Basic Salary:</strong>
                <p style="color:var(--text-primary, #0f172a);">R {{ number_format($pe->basicSalaryAmount(), 2) }}</p>
            </div>
            @endif
        </div>
    </div>

    {{-- Verification checklist --}}
    <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
        <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">Verification Status</h4>
        <div class="space-y-1.5 text-xs">
            @foreach([
                ['Personal details', $takeOn->personal_details_verified],
                ['Banking & tax', $takeOn->banking_details_verified && $takeOn->tax_details_verified],
                ['Employment terms', $takeOn->employment_terms_verified],
                ['Compensation', $takeOn->compensation_setup_verified],
                ['Leave balances', $takeOn->leave_balances_captured],
                ['Compliance docs', $takeOn->compliance_documents_uploaded],
                ['Employment contract', $takeOn->signed_employment_contract_uploaded],
            ] as [$label, $done])
                <div class="flex items-center gap-2">
                    @if($done)
                        <svg class="w-3.5 h-3.5" style="color:var(--brand-icon);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    @else
                        <svg class="w-3.5 h-3.5" style="color:var(--ds-crimson);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    @endif
                    <span style="color:{{ $done ? 'var(--text-primary, #0f172a)' : '#ef4444' }};">{{ $label }}</span>
                </div>
            @endforeach
        </div>
        <p class="text-[10px] mt-2" style="color:var(--text-secondary, #94a3b8);">Progress: {{ $takeOn->progressPercentage() }}%</p>
    </div>

    @if(!$takeOn->isComplete())
    <form method="POST" action="{{ route('staff-take-on.complete', $takeOn) }}" x-data="{ confirmed: false }">
        @csrf
        <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
            <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-primary, #0f172a);">
                <input type="checkbox" x-model="confirmed" style="accent-color:var(--brand-icon);">
                I confirm all details have been verified and are correct.
            </label>
            <p class="text-[10px] mt-1" style="color:var(--text-secondary, #94a3b8);">Signed by: {{ auth()->user()->name }}</p>
        </div>
        <button type="submit" :disabled="!confirmed" class="mt-4 px-4 py-2 text-sm font-semibold text-white transition" style="background:var(--brand-icon); border-radius:6px;" :style="!confirmed && 'opacity:0.5; cursor:not-allowed'">Submit Take-On</button>
    </form>
    @else
        <div class="p-3 text-xs font-semibold" style="background:color-mix(in srgb, var(--brand-icon) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent); border-radius:6px; color:var(--brand-icon);">
            Take-on completed on {{ $takeOn->completed_at->format('d M Y H:i') }} by {{ $takeOn->completedBy->name ?? '?' }}.
        </div>
    @endif
</div>
