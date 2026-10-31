{{--
    AT-460 - "The job": one compact definition grid, each fact once. Tenant, Landlord, Access contact, Crew, Scheduled, Due, Source. One Edit button (a draft card only,
    as before: title + access contact + access note; same route, same fields). No description here - it is the one line under the title in the header.
    Receives $jobCard, $access, $landlords, $displayFaultReport, $displayWorkOrder, $screen (tz).
--}}
@php
    $jfTz = $screen['tz'];
    $jfDt = fn ($d) => $d ? \App\Support\AppDate::dateTime($d, $jfTz) : '—';
    $jfTenants = $jobCard->lease ? $jobCard->lease->tenants->map(fn ($t) => $t->contact)->filter() : collect();
    $jfInspection = $jobCard->workOrder?->reportedInspectionObservation?->inspection;
    $jfPerson = function ($contact) {
        $tel = \App\Services\Rentals\JobSiteInfo::tel($contact->phone ?? null);
        return e($contact->full_name) . ($tel ? ' · <a href="tel:' . e($tel) . '" class="underline">' . e($contact->phone) . '</a>' : '');
    };
@endphp
<div class="jc-card" id="jc-job" x-data="{ editing: false }" data-job-facts data-tour="rmnt-jcd-job">
    <div class="jc-card-head">
        <h2>The job</h2>
        @permission('rental_job_cards.create')
        @if($jobCard->status === \App\Models\RentalJobCard::STATUS_DRAFT)
            <button type="button" x-show="!editing" @click="editing = true" class="corex-btn-outline text-xs">Edit</button>
        @endif
        @endpermission
    </div>

    <dl class="jc-facts" x-show="!editing">
        <dt>Tenant</dt>
        <dd>@if($jfTenants->isEmpty()){{ $jobCard->lease ? 'No tenant linked' : 'None — vacancy period' }}@else @foreach($jfTenants as $tc){!! $jfPerson($tc) !!}@if(! $loop->last)<br>@endif @endforeach @endif</dd>
        <dt>Landlord</dt>
        <dd>@if($landlords->isEmpty())No landlord linked @else @foreach($landlords as $ll){!! $jfPerson($ll) !!}@if(! $loop->last)<br>@endif @endforeach @endif</dd>

        <dt>Access contact</dt>
        <dd data-access-contact>
            @if($access['name'] || $access['phone']){{ $access['name'] }}@if($access['phone'])@if($access['name']) · @endif<a href="tel:{{ $access['tel'] }}" class="underline">{{ $access['phone'] }}</a>@endif @else — @endif
            @if($access['note'])<div class="text-xs" style="color: var(--text-muted);">{{ $access['note'] }}</div>@endif
        </dd>
        <dt>Crew</dt>
        <dd>@if($jobCard->crew){{ $jobCard->crew->name }}@if($jobCard->crew->trashed()) <span style="color: var(--text-muted);">(archived)</span>@endif @elseif($jobCard->assigned_user_id)<span style="color: var(--text-muted);">Previously assigned: {{ $jobCard->assignedUser?->name ?? '—' }}</span> @else — @endif</dd>

        <dt>Scheduled</dt><dd>{{ $jfDt($jobCard->scheduled_at) }}</dd>
        <dt>Due</dt><dd>{{ $jfDt($jobCard->due_at) }}</dd>

        <dt>Source</dt>
        <dd class="jc-wide" data-job-source>
            @php $jfSources = []; @endphp
            @if($displayWorkOrder)
                @php ob_start(); @endphp
                @feature('rental-work-orders')<a href="{{ route('corex.rental-work-orders.show', $displayWorkOrder) }}" class="underline">Work order #{{ $displayWorkOrder->id }}</a>@else Work order #{{ $displayWorkOrder->id }} @endfeature
                @php $jfSources[] = ob_get_clean(); @endphp
            @endif
            @if($displayFaultReport)
                @php ob_start(); @endphp
                @feature('rental-faults')<a href="{{ route('corex.rental-fault-reports.show', $displayFaultReport) }}" class="underline">Fault report #{{ $displayFaultReport->id }}</a>@else Fault report #{{ $displayFaultReport->id }} @endfeature
                @php $jfSources[] = ob_get_clean(); @endphp
            @endif
            @if($jfInspection)
                @php
                    ob_start();
                    $jfInspLabel = ucfirst(str_replace('_', '-', $jfInspection->type)) . '-inspection ' . ($jfInspection->scheduled_for?->format('Y-m-d') ?? $jfInspection->created_at?->format('Y-m-d'));
                @endphp
                @feature('rental-inspections')<a href="{{ route('corex.rental-inspections.show', $jfInspection) }}" class="underline">{{ $jfInspLabel }}</a>@else {{ $jfInspLabel }} @endfeature
                @php $jfSources[] = ob_get_clean(); @endphp
            @endif
            @if($jfSources === []) No source — created directly. @else {!! implode(' &middot; ', array_map('trim', $jfSources)) !!} @endif
        </dd>
    </dl>

    @permission('rental_job_cards.create')
    @if($jobCard->status === \App\Models\RentalJobCard::STATUS_DRAFT)
    <form data-keep-scroll x-show="editing" x-cloak method="POST" action="{{ route('corex.rental-job-cards.update', $jobCard) }}" class="space-y-3">
        @csrf
        @method('PUT')
        <div>
            <label class="text-xs font-medium">Title</label>
            <input type="text" name="title" required maxlength="191" value="{{ old('title', $jobCard->title) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="text-xs font-medium">Access contact name</label>
                <input type="text" name="access_contact_name" maxlength="191" value="{{ old('access_contact_name', $jobCard->workOrder?->access_contact_name ?? $jobCard->access_contact_name) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
            <div>
                <label class="text-xs font-medium">Access contact phone</label>
                <input type="text" name="access_contact_phone" maxlength="50" value="{{ old('access_contact_phone', $jobCard->workOrder?->access_contact_phone ?? $jobCard->access_contact_phone) }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
            </div>
        </div>
        <div>
            <label class="text-xs font-medium">Access note</label>
            <textarea name="access_notes" rows="2" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">{{ old('access_notes', $jobCard->workOrder?->access_note ?? $jobCard->access_notes) }}</textarea>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
            <button type="button" @click="editing = false" class="corex-btn-outline text-xs">Cancel</button>
        </div>
    </form>
    @endif
    @endpermission
</div>
