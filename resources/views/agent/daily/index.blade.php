<x-app-layout>
    <x-slot name="header">Daily Activity</x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <div class="p-3 rounded bg-green-100 text-green-800">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="p-3 rounded bg-red-100 text-red-800">{{ $errors->first() }}</div>
        @endif

        <div class="bg-white shadow rounded-xl p-4 sm:p-5">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                <form method="GET" action="{{ route('agent.daily') }}" class="flex items-end gap-2">
                    <div>
                        <label class="text-xs text-gray-500">Month</label>
                        <input type="month" name="month" value="{{ $month }}" class="border border-gray-300 rounded-lg px-3 py-2" />
                    </div>
                    <button class="bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 rounded-lg font-semibold">View</button>
                </form>
                <div class="text-xs text-gray-500">Month-at-a-glance capture (your data only).</div>
            </div>
        </div>

        <form method="POST" action="{{ route('agent.daily.save') }}" class="bg-white shadow rounded-xl overflow-hidden">
            @csrf
            <input type="hidden" name="month" value="{{ $month }}" />

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr class="border-b">
                            <th class="text-left p-2">Date</th>
                            @foreach($dailyCols as $c)
                                <th class="text-left p-2">{{ $c['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($days as $d)
                            @php $row = $d['row']; @endphp
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-2 font-semibold">
                                    {{ $d['date'] }}
                                    <div class="text-xs text-gray-500">{{ $d['dow'] }}</div>
                                </td>
                                @foreach($dailyCols as $c)
                                    @php $k = $c['key']; @endphp
                                    <td class="p-2">
                                        <input type="number" min="0"
                                               class="border border-gray-300 rounded-lg px-2 py-1 w-24 text-right"
                                               name="daily[{{ $d['date'] }}][{{ $k }}]"
                                               value="{{ old('daily.'.$d['date'].'.'.$k, (int)($row?->$k ?? 0)) }}">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t bg-gray-50 flex items-center justify-end">
                <button class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg font-semibold shadow border">💾 Save Month</button>
            </div>
        </form>
    </div>
</x-app-layout>
