<x-app-layout>
    <x-slot name="header">Daily Activity</x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <div class="p-3 rounded bg-green-100 text-green-800">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="p-3 rounded bg-red-100 text-red-800">{{ $errors->first() }}</div>
        @endif

        <div class="shadow rounded-xl p-4 sm:p-5" style="background:var(--surface)">
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
                <form method="GET" action="{{ route('agent.daily') }}" class="flex items-end gap-2">
                    <div>
                        <label class="text-xs" style="color:var(--text-muted)">Month</label>
                        <input type="month" name="month" value="{{ $month }}" class="border rounded-lg px-3 py-2" style="border-color:var(--border)" />
                    </div>
                    <button class="text-white px-4 py-2 rounded-lg font-semibold" style="background:var(--brand-button)">View</button>
                </form>
                <div class="text-xs" style="color:var(--text-muted)">Month-at-a-glance capture (your data only).</div>
            </div>
        </div>

        <form method="POST" action="{{ route('agent.daily.save') }}" class="shadow rounded-xl overflow-hidden" style="background:var(--surface)">
            @csrf
            <input type="hidden" name="month" value="{{ $month }}" />

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead style="background:var(--surface-2); color:var(--text-secondary)">
                        <tr class="border-b" style="border-color:var(--border)">
                            <th class="text-left p-2">Date</th>
                            @foreach($dailyCols as $c)
                                <th class="text-left p-2">{{ $c['label'] }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($days as $d)
                            @php $row = $d['row']; @endphp
                            <tr class="border-b" style="border-color:var(--border)" onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background=''">
                                <td class="p-2 font-semibold">
                                    {{ $d['date'] }}
                                    <div class="text-xs" style="color:var(--text-muted)">{{ $d['dow'] }}</div>
                                </td>
                                @foreach($dailyCols as $c)
                                    @php $k = $c['key']; @endphp
                                    <td class="p-2">
                                        <input type="number" min="0"
                                               class="border rounded-lg px-2 py-1 w-24 text-right"
                                               style="border-color:var(--border)"
                                               name="daily[{{ $d['date'] }}][{{ $k }}]"
                                               value="{{ old('daily.'.$d['date'].'.'.$k, (int)($row?->$k ?? 0)) }}">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t flex items-center justify-end" style="background:var(--surface-2); border-color:var(--border)">
                <button class="bg-green-600 hover:bg-green-700 text-white px-5 py-2 rounded-lg font-semibold shadow border">💾 Save Month</button>
            </div>
        </form>
    </div>
</x-app-layout>
