{{-- Step 6: Leave Opening Balances --}}
@php $pe = $payrollEmployee ?? $takeOn->payrollEmployee; @endphp
<form method="POST" action="{{ route('staff-take-on.save-step', [$takeOn, 'leave']) }}">
    @csrf
    @method('PATCH')

    <div class="space-y-4">
        <div class="p-3 text-xs" style="background:rgba(0,212,170,0.04); border:1px solid color-mix(in srgb, var(--brand-icon) 15%, transparent); border-radius:6px; color:var(--text-secondary, #6b7280);">
            Enter days already taken this cycle and any carryover from previous cycles. The system will calculate available balances based on the accrual engine.
        </div>

        @if(!$pe)
            <p class="text-xs" style="color:var(--ds-crimson);">Employment must be set up first (Step 4).</p>
        @else
            @foreach($leaveTypes as $type)
                @php
                    $cycleStart = $balanceService->getCurrentCycleStart($pe, $type);
                    $cycleEnd = $balanceService->getCycleEnd($pe, $type, $cycleStart);
                    $entitlement = $type->entitlementForPattern($pe->working_days_per_week ?? 5);
                @endphp
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:6px;">
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-sm font-semibold" style="color:var(--text-primary, #0f172a);">{{ $type->label }}</h4>
                        @if($type->is_system)
                            <span class="px-1.5 py-0.5 text-[10px] font-bold uppercase" style="background:rgba(148,163,184,0.15); color:#94a3b8; border-radius:6px;">System</span>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs mb-3">
                        <div>
                            <span style="color:var(--text-secondary, #6b7280);">Cycle:</span><br>
                            <strong>{{ $cycleStart->format('d M Y') }} â€” {{ $cycleEnd->format('d M Y') }}</strong>
                        </div>
                        <div>
                            <span style="color:var(--text-secondary, #6b7280);">Entitlement:</span><br>
                            <strong>{{ number_format($entitlement, 1) }} days</strong>
                        </div>
                        <div>
                            <span style="color:var(--text-secondary, #6b7280);">Pattern:</span><br>
                            <strong>{{ $pe->working_days_per_week ?? 5 }}-day week</strong>
                        </div>
                        <div>
                            <span style="color:var(--text-secondary, #6b7280);">Cycle length:</span><br>
                            <strong>{{ $type->cycle_months > 0 ? $type->cycle_months . ' months' : 'Per child' }}</strong>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Days already taken this cycle</label>
                            <input type="number" name="taken_{{ $type->id }}" step="0.5" min="0" value="0"
                                   class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                        </div>
                        @if($type->carries_over_to_next_cycle)
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Carryover from previous cycle</label>
                            <input type="number" name="carryover_{{ $type->id }}" step="0.5" min="0" value="0"
                                   class="w-32 px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:6px;">
                        </div>
                        @endif
                    </div>
                </div>
            @endforeach
        @endif
    </div>

    <button type="submit" class="mt-4 px-4 py-2 text-sm font-semibold text-white transition" style="background:var(--brand-icon); border-radius:6px;">Save & Continue</button>
</form>
