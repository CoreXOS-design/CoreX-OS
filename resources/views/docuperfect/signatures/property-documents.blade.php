@extends('layouts.corex')

@section('corex-content')
@include('corex.rentals.partials._page-styles')
@include('docuperfect.partials._dp-page-styles')
<div class="rx-page dp-page w-full space-y-5">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Property Documents</h1>
                <p class="text-xs" style="color: var(--text-muted);">{{ $property->title ?? $property->address ?? 'Property #' . $property->id }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ url()->previous() }}" class="corex-btn-outline text-xs shrink-0 inline-flex items-center gap-1.5"><svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>Back</a>
            </div>
        </div>
    </div>
    <div class="max-w-5xl space-y-5">

    @if($documentRows->isEmpty())
        <div class="rounded-md border border-slate-200 bg-white p-8 text-center">
            <div class="text-slate-400 text-sm">No signing documents found for this property.</div>
        </div>
    @else
        <div class="space-y-3">
            @foreach($documentRows as $row)
                @php
                    $doc = $row['document'];
                    $tmpl = $row['template'];
                    $statuses = $row['party_statuses'];
                @endphp
                <div class="rounded-md border {{ $row['is_complete'] ? 'border-emerald-200 bg-emerald-50/30' : ($row['is_deferred'] ? 'border-amber-200 bg-amber-50/30' : 'border-slate-200 bg-white') }} p-4">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <h3 class="font-semibold text-slate-800">{{ $doc->name }}</h3>
                            <div class="text-xs text-slate-500 mt-0.5">{{ $doc->created_at->format('d M Y') }}</div>
                        </div>
                        @if($row['is_complete'])
                            <span class="text-xs px-2 py-1 rounded-full bg-emerald-100 text-emerald-700 font-medium">Complete</span>
                        @elseif($row['is_deferred'])
                            <span class="text-xs px-2 py-1 rounded-full bg-amber-100 text-amber-700 font-medium">Awaiting Deferred</span>
                        @else
                            <span class="text-xs px-2 py-1 rounded-full bg-blue-100 text-blue-700 font-medium">In Progress</span>
                        @endif
                    </div>

                    {{-- Party status tree --}}
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        @foreach($statuses as $ps)
                            <div class="flex items-center gap-1.5">
                                @if($ps['is_complete'])
                                    <span class="text-emerald-500 inline-flex"><svg class="dp-ico" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></span>
                                @elseif($ps['is_deferred'])
                                    <span class="text-amber-500 inline-flex"><svg class="dp-ico" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                                @elseif(in_array($ps['status'], ['pending', 'viewed', 'partially_signed']))
                                    <span class="text-blue-500 inline-flex"><svg class="dp-ico" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
                                @else
                                    <span class="text-slate-300 inline-flex"><svg class="dp-ico" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg></span>
                                @endif
                                <span class="capitalize {{ $ps['is_deferred'] ? 'text-amber-600' : ($ps['is_complete'] ? 'text-emerald-600' : 'text-slate-500') }}">
                                    {{ ucfirst(preg_replace('/_\d+$/', '', $ps['role_label'])) }}
                                    @if($ps['name'])
                                        <span class="font-medium">{{ $ps['name'] }}</span>
                                    @endif
                                </span>
                                @if($ps['is_deferred'])
                                    <span class="text-amber-500 text-xs font-medium">Deferred</span>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Resume button for deferred --}}
                    @if($row['is_deferred'])
                        @php $deferredStatus = collect($statuses)->firstWhere('is_deferred', true); @endphp
                        @if($deferredStatus)
                            <div class="mt-3 pt-3 border-t border-slate-100">
                                <button type="button"
                                        onclick="document.getElementById('prop-resume-{{ $doc->id }}').showModal()"
                                        class="text-sm px-4 py-1.5 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-medium">
                                    Resume Signing &mdash; Enter {{ ucfirst(preg_replace('/_\d+$/', '', $deferredStatus['role_label'])) }} Details
                                </button>

                                <dialog id="prop-resume-{{ $doc->id }}" class="rounded-md p-0 w-full max-w-md backdrop:bg-black/30">
                                    <form method="POST" action="{{ route('docuperfect.signatures.resumeDeferred', $doc) }}" class="p-6 space-y-4">
                                        @csrf
                                        <input type="hidden" name="request_id" value="{{ $deferredStatus['request_id'] }}">
                                        <h3 class="text-lg font-semibold text-slate-800">Resume Signing</h3>
                                        <p class="text-sm text-slate-600">
                                            Enter the details for the <strong class="capitalize">{{ ucfirst(preg_replace('/_\d+$/', '', $deferredStatus['role_label'])) }}</strong>.
                                        </p>
                                        <div class="space-y-3">
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1">Full Name</label>
                                                <input type="text" name="signer_name" required
                                                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1">Email Address</label>
                                                <input type="email" name="signer_email" required
                                                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1">ID / Passport Number</label>
                                                <input type="text" name="signer_id_number"
                                                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-600 mb-1">Cell Number</label>
                                                <input type="text" name="signer_cell"
                                                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                            </div>
                                        </div>
                                        <div class="flex items-center justify-end gap-3 pt-2">
                                            <button type="button" onclick="this.closest('dialog').close()"
                                                    class="text-sm px-4 py-2 rounded-lg border border-slate-300 text-slate-600 hover:bg-slate-50">
                                                Cancel
                                            </button>
                                            <button type="submit"
                                                    class="text-sm px-4 py-2 rounded-lg bg-emerald-600 text-white hover:bg-emerald-700 font-medium">
                                                Resume & Send
                                            </button>
                                        </div>
                                    </form>
                                </dialog>
                            </div>
                        @endif
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
</div>
@endsection
