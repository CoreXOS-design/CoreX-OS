@extends('layouts.corex')

@section('corex-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5"
     x-data="{ activeTab: '{{ $activeTab }}' }"
     x-init="$watch('activeTab', v => { const u = new URL(window.location); u.searchParams.set('tab', v); window.history.replaceState({}, '', u); })">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <a href="{{ route('corex.settings') }}" class="inline-flex items-center gap-1 text-xs no-underline" style="color: rgba(255,255,255,0.7);">← Back to Settings</a>
                <h1 class="text-xl font-bold text-white leading-tight mt-1">Seller Outreach Templates</h1>
                <p class="text-sm text-white/60">
                    Pre-written templates agents use to pitch sellers via WhatsApp and email. Every template must include the
                    <code style="color:#fff;">{{ '{tracking_link}' }}</code> merge field and the opt-out word
                    <code style="color:#fff;">STOP</code>.
                </p>
            </div>
        </div>
    </div>

    {{-- Flash messages --}}
    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('status') }}
        </div>
    @endif
    @if(session('error'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            {{ session('error') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <div class="font-semibold mb-1">Please correct the following:</div>
            <ul class="list-disc pl-5">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Tabs --}}
    <div class="flex overflow-x-auto" style="border-bottom: 1px solid var(--border);">
        <button type="button" @click="activeTab = 'whatsapp'"
                :class="activeTab === 'whatsapp' ? 'border-b-2' : 'border-b-2 border-transparent'"
                :style="activeTab === 'whatsapp' ? 'color: #00d4aa; border-color: #00d4aa;' : 'color: var(--text-secondary);'"
                class="px-4 py-3 text-xs font-semibold whitespace-nowrap">
            WhatsApp Templates ({{ $whatsappTemplates->count() }})
        </button>
        <button type="button" @click="activeTab = 'email'"
                :class="activeTab === 'email' ? 'border-b-2' : 'border-b-2 border-transparent'"
                :style="activeTab === 'email' ? 'color: #00d4aa; border-color: #00d4aa;' : 'color: var(--text-secondary);'"
                class="px-4 py-3 text-xs font-semibold whitespace-nowrap">
            Email Templates ({{ $emailTemplates->count() }})
        </button>
    </div>

    {{-- WhatsApp panel --}}
    <div x-show="activeTab === 'whatsapp'" x-cloak>
        @include('settings.outreach-templates._channel-panel', [
            'channel'     => 'whatsapp',
            'templates'   => $whatsappTemplates,
            'mergeFields' => $mergeFields,
        ])
    </div>

    {{-- Email panel --}}
    <div x-show="activeTab === 'email'" x-cloak>
        @include('settings.outreach-templates._channel-panel', [
            'channel'     => 'email',
            'templates'   => $emailTemplates,
            'mergeFields' => $mergeFields,
        ])
    </div>

</div>
@endsection
