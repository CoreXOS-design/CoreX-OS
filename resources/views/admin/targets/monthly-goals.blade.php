<x-app-layout>
<div class="container">
    <h1>Monthly Goals</h1>

    {{-- Status / Errors --}}
    @if(session('status'))
        <div style="padding:8px;background:#e6ffed;border:1px solid #b7ebc6;margin-bottom:10px;">
            {{ session('status') }}
        </div>
    @endif

    @if($errors->any())
        <div style="padding:8px;background:#ffe6e6;border:1px solid #ebb7b7;margin-bottom:10px;">
            {{ implode(', ', $errors->all()) }}
        </div>
    @endif

    {{-- Period selector --}}
    <form method="GET" action="{{ route('admin.monthly-goals') }}" style="margin-bottom:20px;">
        <label>
            Period:
            <input type="month" name="period" value="{{ $period }}">
        </label>

        @if($isAdmin)
            <label style="margin-left:15px;">
                Branch:
                <select name="branch_id">
                    <option value="">Company scope</option>
                    @foreach($branchNames as $id => $name)
                        <option value="{{ $id }}" @selected((int)$branchId === (int)$id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </label>
        @endif

        <button type="submit">Load</button>
    </form>

    <hr>

    {{-- ADMIN: Company goal --}}
    @if($isAdmin)
        <h2>Company Monthly Goal</h2>

        <form method="POST" action="{{ route('admin.monthly-goals.save') }}" style="margin-bottom:20px;">
            @csrf
            <input type="hidden" name="scope" value="company">
            <input type="hidden" name="period" value="{{ $period }}">

            <label>Listings:
                <input type="number" name="listings_target" value="{{ $companyGoal->listings_target ?? 0 }}">
            </label>

            <label style="margin-left:10px;">Deals:
                <input type="number" name="deals_target" value="{{ $companyGoal->deals_target ?? 0 }}">
            </label>

            <label style="margin-left:10px;">Value:
                <input type="number" step="0.01" name="value_target" value="{{ $companyGoal->value_target ?? 0 }}">
            </label>

            <button type="submit" style="margin-left:10px;">Save Company Goal</button>
        </form>

        <hr>
    @endif

    {{-- Branch goal (Admin or BM) --}}
    <h2>Branch Monthly Goal</h2>

    <form method="POST" action="{{ route('admin.monthly-goals.save') }}" style="margin-bottom:20px;">
        @csrf
        <input type="hidden" name="scope" value="branch">
        <input type="hidden" name="period" value="{{ $period }}">

        @if($isAdmin)
            <label>
                Branch:
                <select name="branch_id" required>
                    <option value="">-- Select branch --</option>
                    @foreach($branchNames as $id => $name)
                        <option value="{{ $id }}" @selected((int)$branchId === (int)$id)>
                            {{ $name }}
                        </option>
                    @endforeach
                </select>
            </label>
        @else
            <p><strong>Branch:</strong> {{ $branchNames[$branchId] ?? 'Your branch' }}</p>
            <input type="hidden" name="branch_id" value="{{ $branchId }}">
        @endif

        <br><br>

        <label>Listings:
            <input type="number" name="listings_target" value="{{ $branchGoal->listings_target ?? 0 }}">
        </label>

        <label style="margin-left:10px;">Deals:
            <input type="number" name="deals_target" value="{{ $branchGoal->deals_target ?? 0 }}">
        </label>

        <label style="margin-left:10px;">Value:
            <input type="number" step="0.01" name="value_target" value="{{ $branchGoal->value_target ?? 0 }}">
        </label>

        <button type="submit" style="margin-left:10px;">Save Branch Goal</button>
    </form>

    <hr>

    {{-- Rollups --}}
    <h2>Rollups from Agent Targets ({{ $period }})</h2>

    <h3>Company Rollup</h3>
    <ul>
        <li>Agents with targets: {{ $companyRollup['agents_with_targets'] }}</li>
        <li>Listings target sum: {{ $companyRollup['listings_target_sum'] }}</li>
        <li>Deals target sum: {{ $companyRollup['deals_target_sum'] }}</li>
        <li>Value target sum: {{ $companyRollup['value_target_sum'] }}</li>
    </ul>

    <h3>By Branch</h3>
    <table border="1" cellpadding="6" cellspacing="0">
        <thead>
            <tr>
                <th>Branch</th>
                <th>Agents</th>
                <th>Listings</th>
                <th>Deals</th>
                <th>Value</th>
            </tr>
        </thead>
        <tbody>
            @foreach($branchRollups as $b)
                <tr>
                    <td>{{ $b['branch_name'] }}</td>
                    <td>{{ $b['agents_with_targets'] }}</td>
                    <td>{{ $b['listings_target_sum'] }}</td>
                    <td>{{ $b['deals_target_sum'] }}</td>
                    <td>{{ $b['value_target_sum'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

</div>
</x-app-layout>
