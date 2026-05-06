@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6">
    <x-page-header title="Apply for Leave" :back-route="route('my-portal.leave.index')" back-label="My Leave" :flush="true" />

    <div class="p-4 lg:p-6">
        @if(session('error'))
            <div class="mb-4 p-3 text-sm font-semibold" style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.25); border-radius:3px; color:#ef4444;">{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('my-portal.leave.store') }}" enctype="multipart/form-data">
            @csrf

            @php
                $balancesJson = collect($balances)->mapWithKeys(fn($b, $id) => [$id => ['available' => $b['available_days'], 'entitlement' => $b['entitlement_days']]])->toArray();
                $typesJson = $leaveTypes->mapWithKeys(fn($t) => [$t->id => [
                    'requires_documentation' => $t->requires_documentation,
                    'documentation_label' => $t->documentation_label,
                    'documentation_threshold_days' => $t->documentation_threshold_days,
                    'category' => $t->category,
                ]])->toArray();
            @endphp

            <div class="max-w-3xl space-y-5" x-data="{
                typeId: '{{ old('leave_type_id', '') }}',
                startDate: '{{ old('start_date', '') }}',
                endDate: '{{ old('end_date', '') }}',
                isHalfDay: {{ old('is_half_day', false) ? 'true' : 'false' }},
                workingDays: null,
                balanceBefore: null,
                balanceAfter: null,
                warnings: [],
                holidays: [],
                balances: {{ Js::from($balancesJson) }},
                typesData: {{ Js::from($typesJson) }},
                get selectedType() { return this.typeId ? this.typesData[this.typeId] : null; },
                async calculate() {
                    if (!this.typeId || !this.startDate || !this.endDate) return;
                    try {
                        const res = await fetch('{{ route('my-portal.leave.calculate-days') }}', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}'},
                            body: JSON.stringify({leave_type_id: this.typeId, start_date: this.startDate, end_date: this.endDate, is_half_day: this.isHalfDay})
                        });
                        const data = await res.json();
                        this.workingDays = data.working_days;
                        this.balanceBefore = data.balance_before;
                        this.balanceAfter = data.balance_after;
                        this.warnings = data.warnings || [];
                        this.holidays = data.holidays || [];
                    } catch(e) { console.error(e); }
                }
            }">
                {{-- 1. Leave Type --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">1. Leave Type</h4>
                    <select name="leave_type_id" x-model="typeId" @change="calculate()" required class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                        <option value="">-- Select leave type --</option>
                        @foreach($leaveTypes as $lt)
                            <option value="{{ $lt->id }}">{{ $lt->label }} ({{ number_format((float)($balances[$lt->id]['available_days'] ?? 0), 1) }} days available)</option>
                        @endforeach
                    </select>
                    @error('leave_type_id') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- 2. Dates --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">2. Dates</h4>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Start Date <span class="text-red-500">*</span></label>
                            <input type="date" name="start_date" x-model="startDate" @change="calculate()" required class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                            @error('start_date') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">End Date <span class="text-red-500">*</span></label>
                            <input type="date" name="end_date" x-model="endDate" @change="calculate()" required class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">
                            @error('end_date') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="mt-3" x-show="startDate && endDate && startDate === endDate">
                        <label class="flex items-center gap-2 text-xs cursor-pointer" style="color:var(--text-primary, #0f172a);">
                            <input type="hidden" name="is_half_day" value="0">
                            <input type="checkbox" name="is_half_day" value="1" x-model="isHalfDay" @change="calculate()" style="accent-color:#00d4aa;">
                            Half day only
                        </label>
                        <div x-show="isHalfDay" x-cloak class="mt-2 flex gap-4">
                            <label class="flex items-center gap-1.5 text-xs"><input type="radio" name="half_day_period" value="morning" style="accent-color:#00d4aa;"> Morning</label>
                            <label class="flex items-center gap-1.5 text-xs"><input type="radio" name="half_day_period" value="afternoon" style="accent-color:#00d4aa;"> Afternoon</label>
                        </div>
                    </div>

                    {{-- Live calculation --}}
                    <div x-show="workingDays !== null" x-cloak class="mt-3 p-3" style="background:rgba(0,212,170,0.04); border:1px solid rgba(0,212,170,0.15); border-radius:3px;">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                            <div><span style="color:var(--text-secondary, #94a3b8);">Working days:</span><br><strong x-text="workingDays" style="color:var(--text-primary, #0f172a);"></strong></div>
                            <div><span style="color:var(--text-secondary, #94a3b8);">Balance before:</span><br><strong x-text="parseFloat(balanceBefore || 0).toFixed(2)" style="color:var(--text-primary, #0f172a);"></strong></div>
                            <div><span style="color:var(--text-secondary, #94a3b8);">After application:</span><br><strong x-text="parseFloat(balanceAfter || 0).toFixed(2)" :style="parseFloat(balanceAfter) < 0 ? 'color:#ef4444' : 'color:#00d4aa'"></strong></div>
                            <div x-show="holidays.length > 0"><span style="color:var(--text-secondary, #94a3b8);">Public holidays:</span><br>
                                <template x-for="h in holidays"><span class="text-[10px] block" x-text="h.name + ' (' + h.date + ')'"></span></template>
                            </div>
                        </div>
                        <template x-for="w in warnings">
                            <p class="text-[10px] mt-1 font-semibold" style="color:#ef4444;" x-text="w"></p>
                        </template>
                    </div>
                </div>

                {{-- 3. Reason --}}
                <div class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">3. Reason</h4>
                    <textarea name="reason" rows="3" maxlength="2000" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:#fff; border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;" placeholder="Required for family responsibility, special, and unpaid leave. Optional for others.">{{ old('reason', '') }}</textarea>
                    @error('reason') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- 4. Documents (conditional) --}}
                <div x-show="selectedType && selectedType.requires_documentation" x-cloak class="p-4" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); border-radius:3px;">
                    <h4 class="text-xs font-bold uppercase mb-3" style="color:var(--text-secondary, #94a3b8); letter-spacing:0.05em;">4. Supporting Documents</h4>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">
                        <span x-text="selectedType?.documentation_label || 'Supporting document'"></span> <span class="text-red-500">*</span>
                    </label>
                    <p x-show="selectedType?.documentation_threshold_days > 0" class="text-[10px] mb-2" style="color:var(--text-secondary, #94a3b8);">
                        Required if leave is more than <span x-text="selectedType?.documentation_threshold_days"></span> days.
                    </p>
                    <input type="file" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png"
                           class="block w-full text-xs" style="color:var(--text-secondary, #6b7280);">
                    <p class="text-[10px] mt-1" style="color:var(--text-secondary, #94a3b8);">PDF or image, max 5MB per file.</p>
                    @error('documents') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    @error('documents.*') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>

                {{-- 5. Notes --}}
                <div>
                    <label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary, #6b7280);">Notes (optional)</label>
                    <textarea name="notes" rows="2" maxlength="2000" class="w-full px-3 py-2 text-sm focus:outline-none" style="background:var(--surface-2, #f8fafc); border:1px solid var(--border, #e5e7eb); color:var(--text-primary, #0f172a); border-radius:3px;">{{ old('notes', '') }}</textarea>
                </div>

                {{-- Actions --}}
                <div class="flex items-center gap-3">
                    <button type="submit" class="px-4 py-2 text-sm font-semibold text-white transition" style="background:#00d4aa; border-radius:3px;">Submit Application</button>
                    <a href="{{ route('my-portal.leave.index') }}" class="px-4 py-2 text-sm font-semibold transition" style="color:var(--text-secondary, #6b7280); border:1px solid var(--border, #e5e7eb); border-radius:3px;">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection
