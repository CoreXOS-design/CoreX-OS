@extends('layouts.corex-app')

@section('corex-content')
<div class="p-6 lg:p-8 max-w-2xl">
    {{-- Header --}}
    <div class="mb-6 flex items-start justify-between gap-3" data-tour="comp-fica-create-intro">
        <div>
            <a href="{{ route('compliance.fica.index') }}" class="text-sm text-slate-500 hover:text-slate-700 mb-2 inline-block">&larr; Back to Compliance</a>
            <h1 class="text-2xl font-bold text-slate-900">Send FICA Request</h1>
            <p class="text-sm text-slate-500 mt-1">Select a contact to send a FICA verification form to</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            @include('layouts.partials.tour-header-launcher', ['variant' => 'surface'])
        </div>
    </div>

    @if ($errors->any())
        <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-800 text-sm">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="bg-white border border-slate-200 p-6">
        <form method="POST" action="{{ route('compliance.fica.store') }}">
            @csrf

            <div class="mb-4" x-data="{ search: '', open: false, selected: null, selectedName: '' }" data-tour="comp-fica-create-contact">
                <label class="block text-sm font-semibold text-slate-700 mb-1">Contact *</label>
                <div class="relative">
                    <input type="text"
                           x-model="search"
                           @focus="open = true"
                           @click.away="open = false"
                           placeholder="Search contacts..."
                           class="w-full px-3 py-2 border border-slate-300 text-sm focus:outline-none focus:border-teal-500"
                           x-show="!selected">
                    <div x-show="selected" class="flex items-center justify-between px-3 py-2 border border-slate-300 bg-slate-50">
                        <span class="text-sm font-medium text-slate-900" x-text="selectedName"></span>
                        <button type="button" @click="selected = null; selectedName = ''; search = ''" class="text-slate-400 hover:text-red-500">&times;</button>
                    </div>
                    <input type="hidden" name="contact_id" :value="selected">

                    <div x-show="open && search.length >= 2" x-cloak
                         class="absolute z-10 mt-1 w-full bg-white border border-slate-200 shadow-lg max-h-60 overflow-y-auto">
                        @foreach($contacts as $c)
                            @php
                                $haystack = strtolower(trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '') . ' ' . ($c->email ?? '')));
                                $label    = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')) . ($c->email ? ' (' . $c->email . ')' : '');
                            @endphp
                            <button type="button"
                                    x-show="{{ \Illuminate\Support\Js::from($haystack) }}.includes(search.toLowerCase())"
                                    @click="selected = {{ (int) $c->id }}; selectedName = {{ \Illuminate\Support\Js::from($label) }}; open = false"
                                    class="w-full text-left px-3 py-2 text-sm hover:bg-slate-50 border-b border-slate-100">
                                <div class="font-medium text-slate-900">{{ $c->first_name }} {{ $c->last_name }}</div>
                                <div class="text-xs text-slate-400">{{ $c->email ?? 'No email' }} {{ $c->phone ? '/ ' . $c->phone : '' }}</div>
                            </button>
                        @endforeach
                    </div>
                </div>
                <p class="text-xs text-slate-400 mt-1">The contact must have an email address on file.</p>
            </div>

            <div class="flex items-center gap-3 mt-6">
                <button type="submit" class="px-6 py-2 bg-slate-900 text-white text-sm font-semibold hover:bg-slate-800 transition" data-tour="comp-fica-create-send">
                    Send FICA Request
                </button>
                <a href="{{ route('compliance.fica.index') }}" class="px-4 py-2 text-sm text-slate-600 hover:text-slate-800">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
