@extends('layouts.corex-app')

@section('corex-content')
<div class="-m-4 lg:-m-6" x-data="policyStep()">
    <x-page-header :title="$policyModel->name" :back-route="route('agent.portal')" back-label="My Portal" :flush="true">
        <x-slot:actions>
            <span class="text-xs font-semibold" style="color:#64748b;">Step {{ $order }} of {{ $total }}</span>
            <div class="w-24 h-1.5 rounded-full overflow-hidden" style="background:color-mix(in srgb, var(--brand-icon) 15%, transparent);">
                <div class="h-full rounded-full transition-all" style="background:var(--brand-icon); width:{{ $ack->progressPercent() }}%;"></div>
            </div>
            <span class="text-xs font-semibold" style="color:var(--brand-icon);">{{ $ack->progressPercent() }}%</span>
        </x-slot:actions>
    </x-page-header>

    <div class="p-4 lg:p-6" style="padding-bottom:80px;">
        <div class="max-w-3xl mx-auto">
            <div class="bg-white border" style="border-color:var(--border, #e5e7eb); border-radius:6px;">
                <div class="px-6 py-4" style="border-bottom:2px solid var(--brand-icon);">
                    <div class="text-xs font-semibold uppercase" style="color:#64748b; letter-spacing:0.05em;">{{ $section->section_type === 'section' ? 'Section' : ucfirst($section->section_type) }} {{ $section->section_number }}</div>
                    <h2 class="text-lg font-bold mt-1" style="color:var(--text-primary);">{{ $section->title }}</h2>
                </div>
                <div class="px-6 py-5 prose prose-sm max-w-none" style="color:#334155; line-height:1.7; font-size:0.9375rem;">
                    {!! $section->renderedBody($variables) !!}
                </div>
            </div>
        </div>
    </div>

    {{-- Fixed footer bar --}}
    <div class="fixed bottom-0 left-0 right-0 z-40" style="background:var(--surface, #fff); border-top:1px solid var(--border, #e5e7eb); box-shadow:0 -2px 8px rgba(0,0,0,0.05);">
        <div class="max-w-3xl mx-auto px-4 lg:px-6 py-3">
            <div x-show="errorMessage" x-cloak x-transition class="mb-2 px-3 py-2 text-xs" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid rgba(239,68,68,0.3); border-radius:6px; color:var(--ds-crimson);" x-text="errorMessage"></div>

            <div class="flex items-center justify-between gap-4">
                <div class="text-xs" style="color:#64748b;">
                    <span class="font-semibold" style="color:var(--brand-icon);">{{ $ackedCount }}</span> of {{ $total }} complete
                </div>

                <div class="flex items-center gap-3">
                    @if($order > 1)
                    <a href="{{ route('policy.ack.step', [$policyKey, $order - 1]) }}" class="text-xs font-semibold px-3 py-2" style="border:1px solid var(--border, #e5e7eb); border-radius:6px; color:var(--text-secondary, #6b7280);">Previous</a>
                    @endif

                    @if($section->acknowledgement_prompt && !$isAcked)
                    <label class="flex items-center gap-2 text-xs font-semibold cursor-pointer p-2 -m-2" style="color:var(--text-primary, #1f2937);"
                           :class="isSubmitting ? 'opacity-50 cursor-wait' : ''">
                        <input type="checkbox" x-model="checked" @change="if(checked) confirmAndNext()" :disabled="isSubmitting"
                               style="accent-color:var(--brand-icon); width:24px; height:24px; flex-shrink:0;">
                        <span class="max-w-xs truncate">{{ $section->acknowledgement_prompt }}</span>
                    </label>
                    @endif

                    @if($isAcked)
                    @if($isLast)
                    <a href="{{ route('policy.ack.sign', $policyKey) }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold transition" style="background:var(--brand-icon); color:var(--text-primary); border-radius:6px;">
                        Continue to Signature
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                    @else
                    <a href="{{ route('policy.ack.step', [$policyKey, $order + 1]) }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold transition" style="background:var(--brand-icon); color:var(--text-primary); border-radius:6px;">
                        Next
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                    @endif
                    @else
                    <span class="px-4 py-2 text-xs font-semibold" style="background:#e5e7eb; color:#94a3b8; border-radius:6px; cursor:not-allowed;" x-show="!confirmed">
                        @if($isLast) Continue to Signature @else Next @endif
                    </span>
                    <a x-show="confirmed" x-cloak
                       :href="nextUrl"
                       class="inline-flex items-center gap-1.5 px-4 py-2 text-xs font-semibold transition" style="background:var(--brand-icon); color:var(--text-primary); border-radius:6px;">
                        @if($isLast) Continue to Signature @else Next @endif
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </a>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function policyStep() {
    return {
        checked: false,
        confirmed: false,
        nextUrl: '',
        isSubmitting: false,
        errorMessage: '',

        async confirmAndNext() {
            this.isSubmitting = true;
            this.errorMessage = '';

            try {
                const res = await fetch('{{ route("policy.ack.confirm", [$policyKey, $order]) }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({}),
                });

                if (!res.ok) {
                    throw new Error('Server returned ' + res.status);
                }

                const data = await res.json();
                if (data.success) {
                    this.confirmed = true;
                    this.nextUrl = data.next_url;
                } else {
                    throw new Error('Save failed');
                }
            } catch (e) {
                this.checked = false;
                this.confirmed = false;
                this.errorMessage = 'Could not save. Please check your connection and try again.';
                setTimeout(() => this.errorMessage = '', 6000);
            } finally {
                this.isSubmitting = false;
            }
        }
    };
}
</script>
@endsection
