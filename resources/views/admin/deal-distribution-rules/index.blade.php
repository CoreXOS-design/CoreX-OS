{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
@extends('layouts.corex-app')

@section('corex-content')
@php
    $modeLabels = ['secure_link' => 'Secure link + PIN', 'direct_attachment' => 'Email attachment'];
@endphp

<div class="max-w-5xl mx-auto p-4 lg:p-6">
    <div class="mb-5">
        <h1 class="text-xl font-semibold" style="color: var(--text-primary);">Deal Document Distribution</h1>
        <p class="text-sm mt-1" style="color: var(--text-muted);">
            Decide how each document type reaches each party at each stage. Rules set to
            <strong>auto</strong> fire the moment their stage is ticked — the electrician's COC request goes
            out automatically, generated from the deal. Secure link + PIN is the default; email attachment is
            for low-sensitivity documents.
        </p>
    </div>

    @if(session('status'))
        <div class="p-3 rounded-lg text-sm mb-4" style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); color: #34d399;">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="p-3 rounded-lg text-sm mb-4" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171;">{{ $errors->first() }}</div>
    @endif

    {{-- Add a rule --}}
    <div class="rounded-xl p-4 mb-6" style="border: 1px solid var(--border); background: var(--surface);">
        <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Add a rule</h2>
        <form method="POST" action="{{ route('admin.settings.deal-distribution-rules.store') }}" class="grid grid-cols-1 md:grid-cols-6 gap-3 items-end">
            @csrf
            <div class="md:col-span-2">
                <label class="block text-xs mb-1" style="color: var(--text-muted);">Stage</label>
                <select name="pipeline_step_id" class="w-full rounded-md text-sm px-2 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="">Any stage / manual only</option>
                    @foreach($steps as $s)
                        <option value="{{ $s->id }}">{{ $s->template->name ?? 'Template' }} — {{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs mb-1" style="color: var(--text-muted);">Document type</label>
                <select name="document_type_id" required class="w-full rounded-md text-sm px-2 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    @foreach($documentTypes as $dt)
                        <option value="{{ $dt->id }}">{{ $dt->label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs mb-1" style="color: var(--text-muted);">Party role</label>
                <select name="party_role" required class="w-full rounded-md text-sm px-2 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    @foreach($partyRoles as $r)
                        <option value="{{ $r }}">{{ \Illuminate\Support\Str::headline($r) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs mb-1" style="color: var(--text-muted);">Delivery</label>
                <select name="delivery_mode" class="w-full rounded-md text-sm px-2 py-1.5" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    <option value="secure_link">Secure link + PIN</option>
                    <option value="direct_attachment">Email attachment</option>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <label class="inline-flex items-center gap-1.5 text-sm cursor-pointer" style="color: var(--text-secondary);">
                    <input type="checkbox" name="auto_on_stage_tick" value="1" class="rounded" style="accent-color: #14b8a6;"> Auto
                </label>
                <button type="submit" class="px-3 py-1.5 rounded-lg text-xs font-medium" style="background: #2dd4bf; color: #04121f;">Add</button>
            </div>
        </form>
    </div>

    {{-- Existing rules --}}
    <div class="rounded-xl p-4" style="border: 1px solid var(--border); background: var(--surface);">
        <h2 class="text-sm font-semibold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Rules</h2>
        @if($rules->isEmpty())
            <div class="text-sm" style="color: var(--text-muted);">No distribution rules yet. Add one above.</div>
        @else
            <div style="overflow-x:auto;">
            <table class="w-full text-sm">
                <thead>
                    <tr style="color: var(--text-muted); text-align:left;">
                        <th class="py-1 pr-3 font-medium">Stage</th>
                        <th class="py-1 pr-3 font-medium">Document</th>
                        <th class="py-1 pr-3 font-medium">Party</th>
                        <th class="py-1 pr-3 font-medium">Delivery</th>
                        <th class="py-1 pr-3 font-medium">Auto</th>
                        <th class="py-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rules as $rule)
                        <tr style="border-top: 1px solid var(--border); color: var(--text-primary);">
                            <td class="py-2 pr-3">{{ $rule->pipelineStep ? (($rule->pipelineStep->template->name ?? '') . ' — ' . $rule->pipelineStep->name) : 'Any stage' }}</td>
                            <td class="py-2 pr-3">{{ $rule->documentType->label ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ \Illuminate\Support\Str::headline($rule->party_role) }}</td>
                            <td class="py-2 pr-3">{{ $modeLabels[$rule->delivery_mode] ?? $rule->delivery_mode }}</td>
                            <td class="py-2 pr-3">
                                @if($rule->auto_on_stage_tick)
                                    <span class="text-xs px-1.5 py-0.5 rounded" style="background: rgba(45,212,191,0.15); color: #2dd4bf;">Auto on tick</span>
                                @else
                                    <span class="text-xs" style="color: var(--text-muted);">Manual</span>
                                @endif
                            </td>
                            <td class="py-2 text-right">
                                <form method="POST" action="{{ route('admin.settings.deal-distribution-rules.destroy', $rule) }}" onsubmit="return confirm('Remove this rule?');">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="text-xs" style="color: #f87171;">Remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>
@endsection
