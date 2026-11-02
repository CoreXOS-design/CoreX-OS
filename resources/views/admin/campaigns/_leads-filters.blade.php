{{-- AT-534 — search + status + last-sent date range for a leads table. Receives: $action, optional $hidden (extra query fields). --}}
<form method="GET" action="{{ $action }}" class="cmp-panel p-3 flex-shrink-0 grid grid-cols-2 md:grid-cols-6 gap-2 items-end">
    @foreach(($hidden ?? []) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
    <div class="col-span-2">
        <label class="cmp-label">Search</label>
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Name, company, town or email" class="cmp-input">
    </div>
    <div>
        <label class="cmp-label">Status</label>
        <select name="status" class="cmp-input">
            <option value="">All</option>
            @foreach(\App\Models\Campaigns\CampaignLead::STATUSES as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div><label class="cmp-label">Last sent from</label><input type="date" name="from" value="{{ request('from') }}" class="cmp-input"></div>
    <div><label class="cmp-label">Last sent to</label><input type="date" name="to" value="{{ request('to') }}" class="cmp-input"></div>
    <div class="flex gap-2">
        <button class="corex-btn-primary text-xs flex-1 justify-center">Apply</button>
        <a href="{{ $action }}{{ ($hidden ?? []) ? '?' . http_build_query($hidden) : '' }}" class="corex-btn-outline text-xs">Reset</a>
    </div>
</form>
