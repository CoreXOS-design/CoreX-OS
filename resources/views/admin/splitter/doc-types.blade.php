@extends('layouts.corex')

@section('corex-content')

<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">Splitter Document Types</h1>
            <p class="text-sm text-gray-500 mt-1">Manage the label types used in the PDF Pack Splitter review page.</p>
        </div>
        <a href="{{ route('tools.pdf_splitter.index') }}"
           class="px-3 py-1.5 bg-gray-200 text-gray-700 text-xs font-medium rounded hover:bg-gray-300">
            Back to Splitter
        </a>
    </div>
</div>

{{-- Flash messages handled by global toast system --}}

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- LEFT: Add New Type --}}
    <div class="lg:col-span-1">
        <div class="bg-white rounded-xl shadow p-4">
            <h2 class="text-sm font-semibold text-gray-700 mb-3">Add New Type</h2>
            <form method="POST" action="{{ route('admin.splitter.doc-types.store') }}" class="space-y-2">
                @csrf
                <div>
                    <label class="block text-xs text-gray-500 mb-1">Label</label>
                    <input type="text" name="label" value="{{ old('label') }}" required
                           class="w-full border border-gray-300 rounded px-2 py-1.5 text-xs" placeholder="e.g. Title Deed">
                    @error('label')
                        <p class="text-xs text-red-600 mt-1">{{ $message }}</p>
                    @enderror
                </div>
                <p class="text-xs text-gray-400">Slug is auto-generated from the label.</p>
                <button type="submit"
                        class="w-full px-3 py-1.5 bg-[#0b2a4a] text-white text-xs font-medium rounded hover:bg-[#081f36]">
                    Add Type
                </button>
            </form>
        </div>
    </div>

    {{-- RIGHT: Existing Types --}}
    <div class="lg:col-span-2">
        <form method="POST" action="{{ route('admin.splitter.doc-types.bulk-save') }}" id="bulkForm">
            @csrf

            <div class="mb-3 flex items-center justify-between">
                <p class="text-xs text-gray-500">{{ $types->count() }} document type(s)</p>
                <button type="submit"
                        class="px-4 py-2 bg-[#0b2a4a] text-white text-sm font-medium rounded-lg hover:bg-[#081f36]">
                    Save All Changes
                </button>
            </div>

            @if($types->isEmpty())
                <div class="bg-white rounded-xl shadow p-6 text-center">
                    <p class="text-sm text-gray-400 italic">No document types yet. Add one to get started.</p>
                </div>
            @else
                <div class="bg-white rounded-xl shadow overflow-hidden">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-gray-50 text-gray-500 uppercase tracking-wider">
                            <tr>
                                <th class="px-3 py-2 w-16">Order</th>
                                <th class="px-3 py-2">Label</th>
                                <th class="px-3 py-2 w-24">Slug</th>
                                <th class="px-3 py-2 w-20 text-center">Active</th>
                                <th class="px-3 py-2 w-16 text-right">Delete</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($types as $i => $t)
                                <tr>
                                    <td class="px-3 py-2">
                                        <input type="hidden" name="types[{{ $i }}][id]" value="{{ $t->id }}">
                                        <input type="number" name="types[{{ $i }}][sort_order]" value="{{ $t->sort_order }}"
                                               class="w-14 border border-gray-300 rounded px-2 py-1 text-xs text-center" min="0">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input type="text" name="types[{{ $i }}][label]" value="{{ $t->label }}"
                                               class="w-full border border-gray-300 rounded px-2 py-1 text-xs">
                                    </td>
                                    <td class="px-3 py-2 text-gray-400 font-mono">{{ $t->slug }}</td>
                                    <td class="px-3 py-2 text-center">
                                        <select name="types[{{ $i }}][is_active]"
                                                class="border border-gray-300 rounded px-1 py-1 text-xs">
                                            <option value="1" {{ $t->is_active ? 'selected' : '' }}>Yes</option>
                                            <option value="0" {{ !$t->is_active ? 'selected' : '' }}>No</option>
                                        </select>
                                    </td>
                                    <td class="px-3 py-2 text-right">
                                        <form method="POST" action="{{ route('admin.splitter.doc-types.destroy', $t) }}"
                                              onsubmit="return confirm('Delete \'{{ $t->label }}\'?')" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-400 hover:text-red-600 text-xs font-medium"
                                                    title="Delete">&times;</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </form>
    </div>
</div>

@endsection
