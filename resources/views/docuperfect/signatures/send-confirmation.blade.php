@extends('layouts.corex')

@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-4">

    {{-- Header --}}
    <div style="background:var(--brand-default);" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">
            Send Document for Signing
        </h2>
        <div class="text-sm text-white/60">{{ $document->name }}</div>
    </div>

    {{-- Validation error banner --}}
    @if($errors->any())
    <div class="rounded-2xl border border-red-300 bg-red-50 px-6 py-4">
        <div class="flex items-start gap-3">
            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-red-100 flex items-center justify-center">
                <svg class="w-6 h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-red-800">Cannot send document</h3>
                <ul class="mt-1 text-sm text-red-700 list-disc list-inside">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
    @endif

    {{-- Success banner --}}
    <div class="ds-status-card p-5">
        <div class="flex items-start gap-3">
            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-emerald-100 flex items-center justify-center">
                <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                </svg>
            </div>
            <div>
                <h3 class="text-lg font-semibold" style="color:var(--text-primary)">You've signed all your markers</h3>
                <p class="text-sm mt-1" style="color:var(--text-muted)">Ready to send to the next party for their signature.</p>
            </div>
        </div>
    </div>

    {{-- Tenant info --}}
    <div class="ds-status-card p-5 space-y-4">
        <div>
            <div class="text-xs font-semibold uppercase tracking-wider mb-3" style="color:var(--text-faint)">Sending To</div>

            <div class="p-4 rounded-xl border border-green-200 bg-green-50/50">
                <div class="text-sm font-semibold text-green-700 uppercase tracking-wider mb-2">{{ ucfirst($nextPartyRole ?? 'tenant') }}</div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
                    <div>
                        <span style="color:var(--text-muted)">Name:</span>
                        <span class="font-medium" style="color:var(--text-primary)">{{ $tenant['name'] ?? 'Not set' }}</span>
                    </div>
                    <div>
                        <span style="color:var(--text-muted)">Email:</span>
                        <span class="font-medium" style="color:var(--text-primary)">{{ $tenant['email'] ?? 'Not set' }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Send form --}}
        <form action="{{ route('docuperfect.signatures.send', $document) }}" method="POST" class="space-y-4">
            @csrf

            <div>
                <label class="block text-sm font-medium mb-1" style="color:var(--text-secondary)">
                    Optional message to include in the email
                </label>
                <textarea name="message" rows="4"
                          class="w-full rounded-lg border-[color:var(--border)] text-sm px-3 py-2 focus:ring-[color:var(--brand-icon)] focus:border-[color:var(--brand-icon)]"
                          style="background:var(--surface);color:var(--text-primary)"
                          placeholder="Hi {{ $tenant['name'] ?? 'there' }}, please find the lease agreement for your review and signature. Please sign at all marked positions.">{{ old('message') }}</textarea>
            </div>

            <div class="rounded-xl p-4 text-sm" style="background:var(--surface-2);color:var(--text-muted)">
                <div class="flex items-start gap-2">
                    <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color:var(--text-faint)" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div>
                        The {{ $nextPartyRole ?? 'tenant' }} will receive an email with a secure signing link that expires in <strong>14 days</strong>.
                        They can sign electronically without needing an account.
                    </div>
                </div>
            </div>

            <div class="flex items-center justify-between pt-2">
                <a href="{{ route('docuperfect.signatures.sign', $document) }}"
                   class="text-sm font-medium text-[color:var(--text-muted)] hover:text-[color:var(--text-secondary)]">
                    &larr; Back to Signing
                </a>
                <button type="submit"
                        class="corex-btn-primary text-sm px-6 py-2.5">
                    Send to {{ ucfirst($nextPartyRole ?? 'Tenant') }} for Signature &rarr;
                </button>
            </div>
        </form>
    </div>

    {{-- DEV TESTING: Signing links for external parties --}}
    @if(config('app.debug') && isset($template))
        @php
            $sigRequests = $template->requests()->orderBy('signing_order')->get();
        @endphp
        @if($sigRequests->count() > 0)
            <div class="bg-amber-50 border-2 border-amber-300 rounded-lg p-4 text-left">
                <div class="text-xs font-bold text-amber-800 uppercase tracking-wider mb-3 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                    DEV TESTING — Signing Links
                </div>
                <div class="space-y-2">
                    @foreach($sigRequests as $sr)
                        @if($sr->party_role === 'agent')
                            @continue
                        @endif
                        <div class="flex items-center gap-2 text-sm">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium
                                {{ $sr->status === 'pending' ? 'bg-green-100 text-green-800' :
                                   ($sr->status === 'waiting' ? 'bg-gray-100 text-gray-600' :
                                   ($sr->status === 'completed' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-500')) }}">
                                {{ strtoupper($sr->status) }}
                            </span>
                            <span class="font-medium text-amber-900">
                                {{ $sr->signer_name ?? ucfirst($sr->party_role) }}
                                ({{ ucfirst($sr->party_role) }})
                            </span>
                            @if($sr->token && in_array($sr->status, ['pending', 'waiting']))
                                <a href="{{ url('/sign/' . $sr->token) }}"
                                   class="text-amber-700 hover:text-amber-900 underline font-mono text-xs ml-auto"
                                   target="_blank">
                                    /sign/{{ \Illuminate\Support\Str::limit($sr->token, 12) }}...
                                </a>
                            @elseif($sr->status === 'completed')
                                <span class="text-gray-400 text-xs ml-auto">Signed</span>
                            @elseif($sr->status === 'waiting')
                                <span class="text-gray-400 text-xs ml-auto">Waiting for previous party</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

</div>
@endsection
