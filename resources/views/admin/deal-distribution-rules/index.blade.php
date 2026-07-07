{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $modeLabels = ['secure_link' => 'Secure link + PIN', 'direct_attachment' => 'Email attachment'];
@endphp

<div class="w-full space-y-5">
    {{-- Page header (branded — Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Deal Document Distribution</h1>
                <p class="text-sm text-white/60 mt-1">
                    Decide how each document type reaches each party at each stage. Auto rules fire the moment
                    their stage is ticked. Secure link + PIN is the default; email attachment is for
                    low-sensitivity documents.
                </p>
            </div>
        </div>
    </div>

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-crimson, #c41e3a);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
            </svg>
            <div class="flex-1">{{ $errors->first() }}</div>
        </div>
    @endif

    {{-- Add a rule --}}
    <div class="rounded-md p-4" style="border: 1px solid var(--border); background: var(--surface);">
        <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Add a rule</h2>
        <form method="POST" action="{{ route('admin.settings.deal-distribution-rules.store') }}">
            @csrf
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="sm:col-span-2 lg:col-span-1">
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Stage</label>
                    <select name="pipeline_step_id" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="">Any stage / manual only</option>
                        @foreach($steps as $s)
                            <option value="{{ $s->id }}">{{ $s->template->name ?? 'Template' }} — {{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Document type</label>
                    <select name="document_type_id" required class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        @foreach($documentTypes as $dt)
                            <option value="{{ $dt->id }}">{{ $dt->label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Party role</label>
                    <select name="party_role" required class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        @foreach($partyRoles as $r)
                            <option value="{{ $r }}">{{ \Illuminate\Support\Str::headline($r) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Delivery</label>
                    <select name="delivery_mode" class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="secure_link">Secure link + PIN</option>
                        <option value="direct_attachment">Email attachment</option>
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-end gap-4 mt-4">
                <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer" style="color: var(--text-secondary);">
                    <input type="checkbox" name="auto_on_stage_tick" value="1" class="rounded" style="accent-color: var(--brand-button, #0ea5e9);">
                    Auto on stage tick
                </label>
                <button type="submit" class="corex-btn-primary">Add rule</button>
            </div>
        </form>
    </div>

    {{-- Existing rules --}}
    <div>
        <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Rules</h2>
        @if($rules->isEmpty())
            <div class="rounded-md py-12 px-6 text-center" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                     style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                    </svg>
                </div>
                <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No distribution rules yet</h3>
                <p class="text-sm" style="color: var(--text-muted);">Add your first rule using the form above.</p>
            </div>
        @else
            <div class="rounded-md overflow-hidden" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm ds-table">
                        <thead>
                            <tr style="background: var(--surface-2);">
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Stage</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Document</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Party</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Delivery</th>
                                <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Auto</th>
                                <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color: var(--text-muted);">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rules as $rule)
                                <tr class="transition-colors" style="border-top: 1px solid var(--border); color: var(--text-primary);">
                                    <td class="px-4 py-3">{{ $rule->pipelineStep ? (($rule->pipelineStep->template->name ?? '') . ' — ' . $rule->pipelineStep->name) : 'Any stage' }}</td>
                                    <td class="px-4 py-3">{{ $rule->documentType->label ?? '—' }}</td>
                                    <td class="px-4 py-3">{{ \Illuminate\Support\Str::headline($rule->party_role) }}</td>
                                    <td class="px-4 py-3">{{ $modeLabels[$rule->delivery_mode] ?? $rule->delivery_mode }}</td>
                                    <td class="px-4 py-3">
                                        @if($rule->auto_on_stage_tick)
                                            <span class="ds-badge" title="Sends automatically when this stage is ticked"
                                                  style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 14%, transparent); color: var(--brand-icon, #0ea5e9);">Auto</span>
                                        @else
                                            <span class="ds-badge ds-badge-default">Manual</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right">
                                        <form method="POST" action="{{ route('admin.settings.deal-distribution-rules.destroy', $rule) }}" onsubmit="return confirm('Remove this rule?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson, #c41e3a);">Remove</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
