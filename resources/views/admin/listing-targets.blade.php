<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Listing Targets (Admin)
            </h2>
            <a href="{{ route('admin.dashboard', ['period' => $period]) }}"
               class="text-sm px-3 py-2 rounded bg-gray-800 text-white">
                Back to Dashboard
            </a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8 space-y-6">

            @if (session('status'))
                <div class="p-3 rounded bg-green-100 text-green-800">
                    {{ session('status') }}
                </div>
            @endif

            {{-- Period Selector --}}
            <div class="bg-white p-6 rounded shadow">
                <form method="GET" action="{{ route('admin.listing-targets') }}" class="flex items-end gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Period</label>
                        <input type="month"
                               name="period"
                               value="{{ $period }}"
                               class="mt-1 border-gray-300 rounded-md shadow-sm">
                    </div>

                    <div>
                        <button class="px-4 py-2 bg-gray-800 text-white rounded">
                            View
                        </button>
                    </div>
                </form>
            </div>

            {{-- Targets Table --}}
            <div class="bg-white p-6 rounded shadow">
                <form method="POST" action="{{ route('admin.listing-targets.store') }}">
                    @csrf
                    <input type="hidden" name="period" value="{{ $period }}">

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b">
                                    <th class="text-left p-2">Agent</th>
                                    <th class="text-left p-2">Target Listings (for {{ $period }})</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($agents as $agent)
                                    @php
                                        $existing = $targets->get($agent->id);
                                        $value = old('targets.' . $agent->id, $existing?->target_listings ?? 0);
                                    @endphp
                                    <tr class="border-b">
                                        <td class="p-2">{{ $agent->email }}</td>
                                        <td class="p-2">
                                            <input type="number"
                                                   min="0"
                                                   name="targets[{{ $agent->id }}]"
                                                   value="{{ $value }}"
                                                   class="border rounded p-2 w-40">
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="p-2 text-gray-600">
                                            No agents found.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @error('targets') <div class="text-red-600 text-sm mt-2">{{ $message }}</div> @enderror

                    <div class="mt-6">
                        <button class="px-4 py-2 bg-green-600 text-white rounded">
                            Save Targets
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</x-app-layout>
