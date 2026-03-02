@extends('layouts.corex')

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">

    {{-- Header --}}
    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">
            Send Document for Signing
        </h2>
        <div class="text-sm text-white/60">{{ $document->name }}</div>
    </div>

    {{-- Flash messages handled by global toast system --}}

    {{-- Success banner --}}
    <div class="ds-status-card p-5">
        <div class="flex items-start gap-3">
            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center">
                <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-slate-800">You've signed all your markers</h3>
                <p class="text-sm text-slate-500 mt-1">Ready to send to the next party for their signature.</p>
            </div>
        </div>
    </div>

    {{-- Tenant info --}}
    <div class="ds-status-card p-5 space-y-4">
        <div>
            <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Sending To</div>

            <div class="p-4 rounded-xl border border-green-200 bg-green-50/50">
                <div class="text-sm font-semibold text-green-700 uppercase tracking-wider mb-2">Tenant</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div>
                        <span class="text-slate-500">Name:</span>
                        <span class="font-medium text-slate-800">{{ $tenant['name'] ?? 'Not set' }}</span>
                    </div>
                    <div>
                        <span class="text-slate-500">Email:</span>
                        <span class="font-medium text-slate-800">{{ $tenant['email'] ?? 'Not set' }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Send form --}}
        <form action="{{ route('docuperfect.signatures.send', $document) }}" method="POST" class="space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">
                    Optional message to include in the email
                </label>
                <textarea name="message" rows="4"
                          class="w-full rounded-lg border-slate-300 text-sm px-3 py-2 focus:ring-cyan-500 focus:border-cyan-500"
                          placeholder="Hi {{ $tenant['name'] ?? 'there' }}, please find the lease agreement for your review and signature. Please sign at all marked positions.">{{ old('message') }}</textarea>
            </div>

            <div class="bg-slate-50 rounded-xl p-4 text-sm text-slate-500">
                <div class="flex items-start gap-2">
                    <svg class="w-5 h-5 text-slate-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        The tenant will receive an email with a secure signing link that expires in <strong>14 days</strong>.
                        They can sign electronically without needing an account.
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <a href="{{ route('docuperfect.signatures.sign', $document) }}"
                   class="text-sm text-slate-500 hover:text-slate-700 font-medium">
                    &larr; Back to Signing
                </a>
                <button type="submit"
                        class="corex-btn-primary text-sm px-6 py-2.5">
                    Send to Tenant for Signature &rarr;
                </button>
            </div>
        </form>
    </div>

</div>
@endsection
