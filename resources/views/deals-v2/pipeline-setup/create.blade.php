<x-app-layout>
    <div>
        <div class="sticky top-0 z-30 -mx-4 -mt-4 mb-0 lg:-mx-6 lg:-mt-6" style="background: var(--surface); border-bottom: 1px solid var(--border);">
            <div class="flex items-center justify-between px-4 sm:px-6 lg:px-8 py-3">
                <div class="flex items-center gap-3 min-w-0">
                    <a href="{{ route('deals-v2.pipeline.index') }}" class="inline-flex items-center gap-1 text-sm flex-shrink-0 transition-colors" style="color: var(--text-muted);">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                        Back
                    </a>
                    <span class="flex-shrink-0" style="color: var(--border);">|</span>
                    <h1 class="text-lg font-semibold truncate" style="color: var(--text-primary);">New Pipeline Template</h1>
                </div>
            </div>
        </div>

        <div class="p-4 lg:p-6 max-w-2xl mx-auto">
            @if($errors->any())
                <div class="mb-4 p-3 rounded-lg text-sm" style="background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.3); color: #f87171;">
                    @foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('deals-v2.pipeline.store') }}" class="rounded-xl p-5" style="border: 1px solid var(--border); background: var(--surface);">
                @csrf

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-medium uppercase tracking-wider mb-1" style="color: var(--text-muted);">Template Name</label>
                        <input type="text" name="name" required value="{{ old('name') }}" placeholder="e.g. Standard Bond Sale"
                               class="w-full rounded-md text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-teal-500"
                               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wider mb-1" style="color: var(--text-muted);">Deal Type</label>
                        <select name="deal_type" required class="w-full rounded-md text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            <option value="bond" {{ old('deal_type') === 'bond' ? 'selected' : '' }}>Bond Sale</option>
                            <option value="cash" {{ old('deal_type') === 'cash' ? 'selected' : '' }}>Cash Sale</option>
                            <option value="sale_of_2nd" {{ old('deal_type') === 'sale_of_2nd' ? 'selected' : '' }}>Sale of 2nd Property</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium uppercase tracking-wider mb-1" style="color: var(--text-muted);">Branch</label>
                        <select name="branch_id" class="w-full rounded-md text-sm px-3 py-2 focus:outline-none focus:ring-1 focus:ring-teal-500"
                                style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            <option value="">All Branches</option>
                            @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex items-center gap-3 md:col-span-2">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="hidden" name="is_default" value="0">
                            <input type="checkbox" name="is_default" value="1" {{ old('is_default') ? 'checked' : '' }}
                                   class="rounded" style="accent-color: #14b8a6;">
                            <span class="text-sm" style="color: var(--text-secondary);">Set as default template for this deal type</span>
                        </label>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-teal-600 hover:bg-teal-500 text-white text-sm font-medium transition-colors">
                        Create Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
