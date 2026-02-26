@extends('layouts.nexus')

@section('nexus-content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-white leading-tight">Document Types</h2>
            <div class="text-sm text-white/60">
                <a href="{{ route('rental.settings') }}" class="text-white/60 hover:text-white">&larr; Rental Settings</a>
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3 text-sm">
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="rounded-2xl border border-red-200 bg-red-50 text-red-900 px-4 py-3 text-sm">
            {{ session('error') }}
        </div>
    @endif

    <div x-data="{ showAdd: false, editId: null }" class="max-w-2xl space-y-3">

        {{-- Existing types --}}
        @foreach($types as $type)
        <div class="bg-white border rounded-lg p-4 flex items-center justify-between {{ !$type->is_active ? 'opacity-50' : '' }}">
            <div class="flex items-center gap-3">
                <span class="w-4 h-4 rounded-full flex-shrink-0" style="background-color: {{ $type->color }}"></span>
                <div>
                    <span class="font-medium text-gray-900">{{ $type->name }}</span>
                    @if($type->is_system)
                        <span class="text-xs text-gray-400 ml-2">(system)</span>
                    @endif
                    @if($type->is_lease)
                        <span class="text-xs bg-green-100 text-green-700 px-1.5 py-0.5 rounded ml-2">Lease tracking</span>
                    @endif
                    @if(!$type->is_active)
                        <span class="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded ml-2">Inactive</span>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button @click="editId = editId === {{ $type->id }} ? null : {{ $type->id }}"
                        class="text-sm text-blue-600 hover:text-blue-800">Edit</button>
                @if(!$type->is_system)
                <form method="POST" action="{{ route('rental.settings.document-types.toggle', $type) }}">
                    @csrf
                    <button type="submit" class="text-sm {{ $type->is_active ? 'text-orange-500' : 'text-green-600' }}">
                        {{ $type->is_active ? 'Deactivate' : 'Activate' }}
                    </button>
                </form>
                @endif
            </div>
        </div>

        {{-- Inline edit form --}}
        <div x-show="editId === {{ $type->id }}" x-collapse class="bg-blue-50 border border-blue-200 rounded-lg p-4 -mt-1">
            <form method="POST" action="{{ route('rental.settings.document-types.update', $type) }}" class="flex flex-wrap items-end gap-3">
                @csrf @method('PUT')
                <div class="flex-1 min-w-[200px]">
                    <label class="block text-xs text-gray-500 mb-1">Name</label>
                    <input type="text" name="name" value="{{ $type->name }}" required
                           class="w-full border rounded px-3 py-1.5 text-sm">
                </div>
                <div class="w-24">
                    <label class="block text-xs text-gray-500 mb-1">Color</label>
                    <input type="color" name="color" value="{{ $type->color }}"
                           class="w-full h-8 border rounded cursor-pointer">
                </div>
                <label class="flex items-center gap-1.5 text-sm">
                    <input type="checkbox" name="is_lease" value="1" {{ $type->is_lease ? 'checked' : '' }}
                           class="rounded border-gray-300 text-green-600">
                    Lease tracking
                </label>
                <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded hover:bg-blue-700">Save</button>
                <button type="button" @click="editId = null" class="px-3 py-1.5 text-sm text-gray-500">Cancel</button>
            </form>
        </div>
        @endforeach

        {{-- Add new type --}}
        <div class="mt-6">
            <button @click="showAdd = !showAdd" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                + Add Document Type
            </button>
            <div x-show="showAdd" x-collapse class="bg-green-50 border border-green-200 rounded-lg p-4 mt-2">
                <form method="POST" action="{{ route('rental.settings.document-types.store') }}" class="flex flex-wrap items-end gap-3">
                    @csrf
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs text-gray-500 mb-1">Name *</label>
                        <input type="text" name="name" required
                               class="w-full border rounded px-3 py-1.5 text-sm"
                               placeholder="e.g. Deposit Receipt">
                    </div>
                    <div class="w-24">
                        <label class="block text-xs text-gray-500 mb-1">Color</label>
                        <input type="color" name="color" value="#6B7280"
                               class="w-full h-8 border rounded cursor-pointer">
                    </div>
                    <label class="flex items-center gap-1.5 text-sm">
                        <input type="checkbox" name="is_lease" value="1"
                               class="rounded border-gray-300 text-green-600">
                        Lease tracking
                    </label>
                    <button type="submit" class="px-3 py-1.5 bg-green-600 text-white text-sm rounded hover:bg-green-700">Add</button>
                </form>
            </div>
        </div>
    </div>

</div>
@endsection
