{{-- AT-534 — leads table shared by the campaign List tab and the list page (§7.2, §7.3). Each row expands in place to show its sends.
     Receives: $leads (rows/sort/direction), $manage (bool — show edit / archive / reset), $archivedView (bool). --}}
@php
    $sort = $leads['sort'];
    $direction = $leads['direction'];
    $cols = $manage ? 10 : 9;
@endphp
<div class="cmp-panel overflow-x-auto">
    <table class="w-full ds-table" data-leads-table>
        <thead style="background: var(--surface-2);">
            <tr>
                <th class="cmp-th" style="width: 24px;"></th>
                @include('admin.campaigns._sort', ['field' => 'last_name', 'label' => 'Name'])
                @include('admin.campaigns._sort', ['field' => 'company', 'label' => 'Company'])
                @include('admin.campaigns._sort', ['field' => 'town', 'label' => 'Town'])
                @include('admin.campaigns._sort', ['field' => 'email', 'label' => 'Email'])
                @include('admin.campaigns._sort', ['field' => 'phone', 'label' => 'Phone'])
                @include('admin.campaigns._sort', ['field' => 'status', 'label' => 'Status'])
                @include('admin.campaigns._sort', ['field' => 'last_step', 'label' => 'Step', 'align' => 'right'])
                @include('admin.campaigns._sort', ['field' => 'last_sent', 'label' => 'Last sent'])
                @if($manage)<th class="cmp-th"></th>@endif
            </tr>
        </thead>
        @forelse($leads['rows'] as $lead)
            <tbody x-data="{ open: false, edit: {{ $errors->getBag('lead_' . $lead->id)->any() ? 'true' : 'false' }} }" data-lead-row="{{ $lead->id }}">
                <tr class="cmp-row">
                    <td class="cmp-td"><button type="button" @click="open = !open" class="cmp-muted" :aria-expanded="open" aria-label="Show sends"><span x-text="open ? '▾' : '▸'">▸</span></button></td>
                    <td class="cmp-td font-medium">{{ $lead->fullName() !== '' ? $lead->fullName() : '—' }}</td>
                    <td class="cmp-td">{{ $lead->company ?: '—' }}</td>
                    <td class="cmp-td">{{ $lead->town ?: '—' }}</td>
                    <td class="cmp-td">{{ $lead->email }}</td>
                    <td class="cmp-td cmp-muted whitespace-nowrap">{{ $lead->phone ?: '—' }}</td>
                    <td class="cmp-td">@include('admin.campaigns._status', ['status' => $archivedView ? 'archived' : $lead->status])</td>
                    <td class="cmp-td text-right">{{ $lead->last_step ?? '—' }}</td>
                    <td class="cmp-td cmp-muted whitespace-nowrap">{{ $lead->last_sent_at ? \Illuminate\Support\Carbon::parse($lead->last_sent_at)->format('Y-m-d H:i') : '—' }}</td>
                    @if($manage)
                        <td class="cmp-td whitespace-nowrap text-right">
                            @if($archivedView)
                                <form method="POST" action="{{ route('admin.campaigns.leads.restore', $lead->id) }}" class="inline">@csrf<button class="corex-btn-outline text-xs">Restore</button></form>
                            @else
                                <button type="button" @click="edit = !edit; open = true" class="corex-btn-outline text-xs">Edit</button>
                                @if(in_array($lead->status, ['suppressed', 'stopped', 'bounced', 'replied'], true))
                                    <form method="POST" action="{{ route('admin.campaigns.leads.reset', $lead->id) }}" class="inline">@csrf<button class="corex-btn-outline text-xs">Reset</button></form>
                                @endif
                                <form method="POST" action="{{ route('admin.campaigns.leads.archive', $lead->id) }}" class="inline" onsubmit="return confirm('Archive this lead? It will not be emailed.')">@csrf @method('DELETE')<button class="corex-btn-outline text-xs">Archive</button></form>
                            @endif
                        </td>
                    @endif
                </tr>
                <tr x-show="open" x-cloak style="background: var(--surface-2);">
                    <td></td>
                    <td colspan="{{ $cols - 1 }}" class="px-3 py-2 text-xs">
                        @if($manage && ! $archivedView)
                            @php $bag = $errors->getBag('lead_' . $lead->id); @endphp
                            <form x-show="edit" method="POST" action="{{ route('admin.campaigns.leads.update', $lead->id) }}" class="grid grid-cols-2 md:grid-cols-7 gap-2 mb-2">
                                @csrf @method('PUT')
                                @foreach(['first_name' => 'First name', 'last_name' => 'Last name', 'company' => 'Company', 'town' => 'Town', 'email' => 'Email', 'phone' => 'Phone'] as $f => $label)
                                    <div><label class="cmp-label">{{ $label }}</label><input name="{{ $f }}" value="{{ $bag->any() ? old($f) : $lead->{$f} }}" class="cmp-input" @if($f === 'email') required @endif>@if($bag->has($f))<div class="cmp-err">{{ $bag->first($f) }}</div>@endif</div>
                                @endforeach
                                <div class="flex items-end"><button class="corex-btn-primary text-xs w-full justify-center">Save</button></div>
                                <div class="col-span-2 md:col-span-7"><label class="cmp-label">Notes</label><textarea name="notes" rows="2" class="cmp-input">{{ $bag->any() ? old('notes') : $lead->notes }}</textarea></div>
                            </form>
                        @endif
                        @if($lead->notes && ! $manage)<div class="mb-2 whitespace-pre-wrap cmp-muted">{{ $lead->notes }}</div>@endif
                        @if($lead->sends->isEmpty())
                            <span class="cmp-muted">Not emailed yet.</span>
                        @else
                            <table class="w-full">
                                @foreach($lead->sends as $send)
                                    <tr x-data="{ body: false }">
                                        <td class="py-1 pr-3 whitespace-nowrap cmp-muted">Step {{ $send->step?->step_number }}</td>
                                        <td class="py-1 pr-3 whitespace-nowrap cmp-muted">{{ ($send->sent_at ?? $send->created_at)?->format('Y-m-d H:i') }}</td>
                                        <td class="py-1 pr-3">@include('admin.campaigns._status', ['status' => $send->status])
                                            @if($send->replied_at)<span class="ds-badge ds-badge-success">Replied</span>@endif
                                            @if($send->bounced_at)<span class="ds-badge ds-badge-danger">Bounced</span>@endif
                                            @if($send->unsubscribed_at)<span class="ds-badge ds-badge-danger">Unsubscribed</span>@endif
                                        </td>
                                        <td class="py-1 w-full"><button type="button" class="cmp-link text-left" @click="body = !body">{{ $send->subject }}</button>
                                            @if($send->error_reason)<span class="cmp-muted"> — {{ $send->error_reason }}</span>@endif
                                            <pre x-show="body" x-cloak class="mt-1 p-2 rounded-md whitespace-pre-wrap" style="font-family: inherit; background: var(--surface); border: 1px solid var(--border);">{{ $send->body }}</pre>
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                    </td>
                </tr>
            </tbody>
        @empty
            <tbody><tr><td colspan="{{ $cols }}" class="px-3 py-10 text-center text-sm cmp-muted">
                @if($filtered ?? false)
                    No leads match these filters. <a href="{{ request()->url() }}{{ request('tab') ? '?tab=' . request('tab') : '' }}" class="cmp-link">Clear filters</a>
                @else
                    {{ $emptyText ?? 'No leads on this list yet.' }}
                @endif
            </td></tr></tbody>
        @endforelse
    </table>
</div>
<div class="mt-3">{{ $leads['rows']->links() }}</div>
