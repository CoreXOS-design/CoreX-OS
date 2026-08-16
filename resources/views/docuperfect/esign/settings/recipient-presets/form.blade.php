@extends('layouts.corex')

@section('corex-content')
<div class="w-full max-w-3xl space-y-5"
     x-data="presetForm({
        phrasing: @js(old('phrasing_template', $preset->phrasing_template)),
        caption: @js(old('signature_caption', $preset->signature_caption)),
        proxyPhrasing: @js(old('proxy_phrasing_template', $preset->proxy_phrasing_template)),
        proxyCaption: @js(old('proxy_signature_caption', $preset->proxy_signature_caption)),
        defaultProxyPhrasing: @js(\App\Models\Docuperfect\EsignRecipientPreset::DEFAULT_PROXY_PHRASING),
        defaultProxyCaption: @js(\App\Models\Docuperfect\EsignRecipientPreset::DEFAULT_PROXY_CAPTION),
     })">
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">
                    {{ $method === 'PUT' ? 'Edit recipient preset' : 'New recipient preset' }}
                </h1>
                <p class="text-xs" style="color: var(--text-muted);">Tokens: <code>{entity_name}</code> <code>{rep_name}</code> <code>{capacity}</code> <code>{entity_reg_no}</code> — an empty <code>()</code> collapses automatically.</p>
            </div>
            <a href="{{ route('docuperfect.esign.recipient-presets.index') }}" class="corex-btn-outline text-xs no-underline">← Back</a>
        </div>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc pl-5">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ $action }}" class="rounded-md p-6 space-y-5" style="background: var(--surface); border: 1px solid var(--border);">
        @csrf
        @if($method === 'PUT') @method('PUT') @endif

        <div>
            <label class="block text-xs font-semibold mb-1.5" style="color: var(--text-secondary);">Preset name</label>
            <input type="text" name="name" value="{{ old('name', $preset->name) }}" required
                   class="w-full rounded-md px-3 py-2 text-sm outline-none"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1.5" style="color: var(--text-secondary);">Applies to</label>
            <select name="applies_to" class="w-full rounded-md px-3 py-2 text-sm outline-none"
                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                @foreach($appliesTo as $opt)
                    <option value="{{ $opt }}" @selected(old('applies_to', $preset->applies_to) === $opt)>
                        {{ $opt === 'entity' ? 'Entity / company recipients only' : 'All recipients' }}
                    </option>
                @endforeach
            </select>
        </div>

        {{-- Ordinary representative phrasing --}}
        <div>
            <label class="block text-xs font-semibold mb-1.5" style="color: var(--text-secondary);">Representative phrasing (recipient / party name)</label>
            <textarea name="phrasing_template" rows="2" x-model="phrasing" required
                      class="w-full rounded-md px-3 py-2 text-sm outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); font-family: ui-monospace, monospace;"></textarea>
            <div class="mt-1 text-xs" style="color: var(--text-muted);">Preview: <span class="font-semibold" style="color: var(--brand-icon,#2563eb);" x-text="sub(phrasing)"></span></div>
        </div>

        <div>
            <label class="block text-xs font-semibold mb-1.5" style="color: var(--text-secondary);">Signature caption (painted under the signature)</label>
            <textarea name="signature_caption" rows="2" x-model="caption"
                      class="w-full rounded-md px-3 py-2 text-sm outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); font-family: ui-monospace, monospace;"></textarea>
            <div class="mt-1 text-xs" style="color: var(--text-muted);">Preview: <span class="italic" style="color: var(--ds-green,#059669);" x-text="sub(caption)"></span></div>
        </div>

        {{-- Proxy wording --}}
        <div class="pt-4 mt-2" style="border-top: 1px dashed var(--border);">
            <h3 class="text-xs font-bold uppercase tracking-widest mb-3" style="color: var(--text-muted);">Proxy wording <span class="font-normal normal-case">— used when the representative signs as a proxy (e.g. an executor under a power of attorney). A proxy always renders with distinct wording; leave blank to use CoreX's standard proxy phrasing.</span></h3>
            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold mb-1.5" style="color: var(--text-secondary);">Proxy representative phrasing</label>
                    <textarea name="proxy_phrasing_template" rows="2" x-model="proxyPhrasing"
                              class="w-full rounded-md px-3 py-2 text-sm outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); font-family: ui-monospace, monospace;"></textarea>
                    <div class="mt-1 text-xs" style="color: var(--text-muted);">Preview: <span class="font-semibold" style="color: var(--brand-icon,#2563eb);" x-text="sub(proxyPhrasing || defaultProxyPhrasing)"></span></div>
                </div>
                <div>
                    <label class="block text-xs font-semibold mb-1.5" style="color: var(--text-secondary);">Proxy signature caption</label>
                    <textarea name="proxy_signature_caption" rows="2" x-model="proxyCaption"
                              class="w-full rounded-md px-3 py-2 text-sm outline-none" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); font-family: ui-monospace, monospace;"></textarea>
                    <div class="mt-1 text-xs" style="color: var(--text-muted);">Preview: <span class="italic" style="color: var(--ds-green,#059669);" x-text="sub(proxyCaption || defaultProxyCaption)"></span></div>
                </div>
            </div>
        </div>

        <label class="flex items-center gap-2 text-sm" style="color: var(--text-primary);">
            <input type="checkbox" name="is_default" value="1" @checked(old('is_default', $preset->is_default)) style="width:1rem;height:1rem;">
            Make this the agency default (used automatically by the esign wizard)
        </label>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="corex-btn-primary">{{ $method === 'PUT' ? 'Save changes' : 'Create preset' }}</button>
            <a href="{{ route('docuperfect.esign.recipient-presets.index') }}" class="text-sm no-underline" style="color: var(--text-muted);">Cancel</a>
        </div>
    </form>
</div>

<script>
function presetForm(init) {
    return {
        phrasing: init.phrasing || '',
        caption: init.caption || '',
        proxyPhrasing: init.proxyPhrasing || '',
        proxyCaption: init.proxyCaption || '',
        defaultProxyPhrasing: init.defaultProxyPhrasing || '',
        defaultProxyCaption: init.defaultProxyCaption || '',
        sample: { entity_name: 'Coastal Holdings (Pty) Ltd', rep_name: 'Jane Smith', capacity: 'Executor', entity_reg_no: '2015/123456/07' },
        // Mirrors EsignRecipientPreset::substitute() so the preview matches the server.
        sub(tpl) {
            let out = (tpl || '')
                .split('{entity_name}').join(this.sample.entity_name)
                .split('{rep_name}').join(this.sample.rep_name)
                .split('{capacity}').join(this.sample.capacity)
                .split('{entity_reg_no}').join(this.sample.entity_reg_no);
            out = out.replace(/\(\s*\)/g, '').replace(/\s{2,}/g, ' ').trim();
            return out || '—';
        },
    };
}
</script>
@endsection
