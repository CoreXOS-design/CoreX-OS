{{-- Step 8: Review & Sign-Off --}}
@php $pe = $takeOn->payrollEmployee; @endphp

<div class="space-y-4">
    <div class="p-4 rounded-md" style="background:var(--surface); border:1px solid var(--border);">
        <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-muted); letter-spacing:0.05em;">8. Review & Sign-Off</h4>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
            <div>
                <strong style="color:var(--text-muted);">Employee:</strong>
                <p style="color:var(--text-primary);">{{ $takeOn->user->name }} ({{ $takeOn->user->email }})</p>
            </div>
            <div>
                <strong style="color:var(--text-muted);">Take-On Type:</strong>
                <p style="color:var(--text-primary);">{{ ucfirst(str_replace('_', ' ', $takeOn->take_on_type)) }}</p>
            </div>
            <div>
                <strong style="color:var(--text-muted);">Employment Date:</strong>
                <p style="color:var(--text-primary);">{{ $takeOn->original_employment_start_date?->format('d M Y') }}</p>
            </div>
            <div>
                <strong style="color:var(--text-muted);">Designation:</strong>
                <p style="color:var(--text-primary);">{{ $pe?->designation_snapshot ?? '-' }}</p>
            </div>
            <div>
                <strong style="color:var(--text-muted);">Branch:</strong>
                <p style="color:var(--text-primary);">{{ $takeOn->user->branch->name ?? '-' }}</p>
            </div>
            <div>
                <strong style="color:var(--text-muted);">Working Pattern:</strong>
                <p style="color:var(--text-primary);">{{ ucfirst(str_replace('_', ' ', $pe?->working_pattern ?? '-')) }} ({{ $pe?->working_days_per_week ?? 5 }}-day)</p>
            </div>
            @if($pe)
            <div>
                <strong style="color:var(--text-muted);">Basic Salary:</strong>
                <p style="color:var(--text-primary);">R {{ number_format($pe->basicSalaryAmount(), 2) }}</p>
            </div>
            @endif
        </div>
    </div>

    {{-- Verification checklist --}}
    <div class="p-4 rounded-md" style="background:var(--surface); border:1px solid var(--border);">
        <h4 class="text-xs font-bold uppercase mb-2" style="color:var(--text-muted); letter-spacing:0.05em;">Verification Status</h4>
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
                        <svg class="w-3.5 h-3.5" style="color:var(--ds-amber);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                    @endif
                    <span style="color:{{ $done ? 'var(--text-primary)' : 'var(--ds-amber)' }};">{{ $label }}</span>
                </div>
            @endforeach
        </div>
        <p class="text-[10px] mt-2" style="color:var(--text-muted);">Progress: {{ number_format($takeOn->progressPercentage()) }}%</p>
    </div>

    @if(!$takeOn->isComplete())
    <form method="POST" action="{{ route('staff-take-on.complete', $takeOn) }}" x-data="{ confirmed: false }">
        @csrf
        <div class="p-4 rounded-md" style="background:var(--surface); border:1px solid var(--border);">
            <label class="flex items-center gap-2 text-sm cursor-pointer" style="color:var(--text-primary);">
                <input type="checkbox" x-model="confirmed" style="accent-color:var(--brand-icon);">
                I confirm all details have been verified and are correct.
            </label>
            <p class="text-[10px] mt-1" style="color:var(--text-muted);">Signed by: {{ auth()->user()->name }}</p>
        </div>
        <button type="submit" :disabled="!confirmed" class="corex-btn-primary mt-4 disabled:opacity-40 disabled:cursor-not-allowed">Submit Take-On</button>
    </form>
    @else
        <div class="p-3 text-xs font-semibold rounded-md" style="background:color-mix(in srgb, var(--brand-icon) 8%, transparent); border:1px solid color-mix(in srgb, var(--brand-icon) 25%, transparent); color:var(--brand-icon);">
            Take-on completed on {{ $takeOn->completed_at->format('d M Y H:i') }} by {{ $takeOn->completedBy->name ?? '?' }}.
        </div>
    @endif
</div>
