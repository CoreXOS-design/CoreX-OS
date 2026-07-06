{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{-- AT-177 / WS4-S — Compile Studio home: start a compile + in-progress drafts + published versions. --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" x-data="{ source: 'reference' }">

    {{-- Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Compile Studio</h1>
                <p class="text-sm text-white/60">Internal tool — compile a document once into a canonical, linted, versioned e-sign template. The signed artifact is the compiled artifact.</p>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-green) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 12%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') }}</div>
    @endif

    {{-- Start a compile --}}
    <div class="rounded-md p-5" style="background: var(--surface); border:1px solid var(--border);">
        <h2 class="text-sm font-bold uppercase tracking-wider mb-3" style="color: var(--text-muted);">Start a compile</h2>
        <form method="POST" action="{{ route('docuperfect.compiler.start') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <div class="flex flex-wrap gap-2">
                <label class="text-xs font-semibold px-3 py-2 rounded cursor-pointer" :style="source==='reference' ? 'background:var(--brand-default,#0b2a4a);color:#fff;' : 'background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);'">
                    <input type="radio" name="source" value="reference" x-model="source" class="hidden"> CoreX reference template
                </label>
                <label class="text-xs font-semibold px-3 py-2 rounded cursor-pointer" :style="source==='html' ? 'background:var(--brand-default,#0b2a4a);color:#fff;' : 'background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);'">
                    <input type="radio" name="source" value="html" x-model="source" class="hidden"> Paste HTML
                </label>
                <label class="text-xs font-semibold px-3 py-2 rounded cursor-pointer" :style="source==='upload' ? 'background:var(--brand-default,#0b2a4a);color:#fff;' : 'background:var(--surface-2);color:var(--text-secondary);border:1px solid var(--border);'">
                    <input type="radio" name="source" value="upload" x-model="source" class="hidden"> Upload DOCX / PDF / HTML
                </label>
            </div>

            {{-- Reference --}}
            <div x-show="source==='reference'" class="space-y-2">
                <select name="reference" class="w-full md:w-96 text-sm rounded px-3 py-2" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                    @foreach($references as $ref)
                        <option value="{{ $ref['key'] }}" @disabled(!$ref['available'])>{{ $ref['label'] }}@if(!$ref['available']) (not available yet)@endif</option>
                    @endforeach
                </select>
                <p class="text-[11px]" style="color: var(--text-muted);">Seeds a draft from a CoreX standard template — already typed, ready to review, bind, and publish.</p>
            </div>

            {{-- HTML --}}
            <div x-show="source==='html'" class="space-y-2">
                <textarea name="html" rows="6" placeholder="Paste document body HTML…" class="w-full text-sm rounded px-3 py-2 font-mono" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);"></textarea>
            </div>

            {{-- Upload --}}
            <div x-show="source==='upload'" class="space-y-2">
                <input type="file" name="document" accept=".docx,.pdf,.html,.htm" class="text-sm" style="color: var(--text-secondary);">
                <p class="text-[11px]" style="color: var(--text-muted);">DOCX/PDF are ingested and segmented into typed blocks; you confirm the segmentation in the studio.</p>
            </div>

            <div class="flex items-center gap-3 pt-1">
                <input type="text" name="family" placeholder="Document family (e.g. otp_sale, 116)…" class="text-sm rounded px-3 py-2 w-full md:w-72" style="background: var(--surface-2); border:1px solid var(--border); color: var(--text-primary);">
                <button type="submit" class="corex-btn-primary text-sm">Create draft →</button>
            </div>
        </form>
    </div>

    {{-- Drafts --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border:1px solid var(--border);">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
            <h2 class="text-sm font-bold uppercase tracking-wider" style="color: var(--text-muted);">Drafts in progress</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead><tr style="background: var(--surface-2);">
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Family</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Lint</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Updated</th>
                    <th class="text-right px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Actions</th>
                </tr></thead>
                <tbody>
                @forelse($drafts as $d)
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-3 font-medium" style="color:var(--text-primary);">{{ $d->family }}</td>
                        <td class="px-4 py-3">
                            @php $ls = $d->lint_status; $badge = ['passed'=>['Lint clean','ds-badge-success'],'failed'=>['Lint failing','ds-badge-danger'],'pending'=>['Not linted','ds-badge-default']][$ls] ?? ['Not linted','ds-badge-default']; @endphp
                            <span class="ds-badge {{ $badge[1] }}">{{ $badge[0] }}</span>
                        </td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $d->updated_at?->format('d M H:i') }}</td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('docuperfect.compiler.studio', $d->id) }}" class="text-xs font-semibold" style="color: var(--brand-icon);">Open studio</a>
                            <form method="POST" action="{{ route('docuperfect.compiler.archive', $d->id) }}" class="inline ml-2" onsubmit="return confirm('Archive this draft?');">
                                @csrf
                                <button type="submit" class="text-xs font-semibold" style="color: var(--ds-crimson);">Archive</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-sm" style="color:var(--text-muted);">No drafts yet. Start a compile above.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Published --}}
    <div class="rounded-md overflow-hidden" style="background: var(--surface); border:1px solid var(--border);">
        <div class="px-5 py-3" style="border-bottom:1px solid var(--border);">
            <h2 class="text-sm font-bold uppercase tracking-wider" style="color: var(--text-muted);">Published versions <span class="normal-case font-normal">(immutable)</span></h2>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm ds-table">
                <thead><tr style="background: var(--surface-2);">
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Family</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Version</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Content hash</th>
                    <th class="text-left px-4 py-2.5 text-xs font-semibold uppercase tracking-wider" style="color:var(--text-muted);">Published</th>
                </tr></thead>
                <tbody>
                @forelse($published as $p)
                    <tr style="border-top:1px solid var(--border);">
                        <td class="px-4 py-3 font-medium" style="color:var(--text-primary);">{{ $p->family }}</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">v{{ $p->version }}</td>
                        <td class="px-4 py-3 font-mono text-xs" style="color:var(--text-muted);">{{ substr((string)$p->content_hash, 0, 16) }}…</td>
                        <td class="px-4 py-3" style="color:var(--text-secondary);">{{ $p->published_at?->format('d M Y H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-10 text-center text-sm" style="color:var(--text-muted);">Nothing published yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
