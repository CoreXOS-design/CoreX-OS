{{-- Roles & permissions — EXPLAINER ONLY. No inputs, nothing to save.
     $roleRows / $permissionCount / $splitOn come from
     AgencySetupWizardController::stepData(). The roles shown are the agency's
     real ones with real headcounts, so the example is theirs, not a mock-up. --}}

<div class="space-y-6">

    {{-- The roles this agency actually has --}}
    <div>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary,#0f172a);">The roles you have right now</h3>
        <p class="text-xs mb-3" style="color:var(--text-muted,#64748b);">
            CoreX ships with these. You can rename them, change what they're allowed to do, or add your own —
            all from <span class="font-semibold">Settings → Role Manager</span>.
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr style="border-bottom:1px solid var(--border,#e5e7eb);">
                        <th class="text-left text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted,#64748b);">Role</th>
                        <th class="text-left text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted,#64748b);">Typically</th>
                        <th class="text-right text-xs font-semibold uppercase tracking-wider px-3 py-2" style="color:var(--text-muted,#64748b);">People</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $blurbs = [
                            'admin'          => 'The principal or owner. Sees everything, configures everything.',
                            'branch_manager' => 'Runs one office. Sees their branch\'s people, stock and deals.',
                            'agent'          => 'Works listings and deals. Sees their own work, and what you choose to share.',
                            'office_admin'   => 'Reception and support. Captures and files; not a commission earner.',
                            'viewer'         => 'Read-only. Useful for an accountant or an auditor.',
                        ];
                    @endphp
                    @foreach ($roleRows as $row)
                        <tr style="border-bottom:1px solid var(--border,#e5e7eb);">
                            <td class="px-3 py-2 font-semibold align-top" style="color:var(--text-primary,#0f172a);">{{ $row['label'] }}</td>
                            <td class="px-3 py-2 align-top" style="color:var(--text-secondary,#475569);">
                                {{ $blurbs[$row['name']] ?? 'A role you or CoreX created.' }}
                            </td>
                            <td class="px-3 py-2 text-right align-top whitespace-nowrap" style="color:var(--text-secondary,#475569);">
                                {{ $row['count'] }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-[11px] mt-2" style="color:var(--text-muted,#94a3b8);">
            Each role is a saved answer to {{ number_format($permissionCount) }} separate permissions.
        </p>
    </div>

    {{-- Worked example --}}
    <div class="rounded-lg p-4" style="border:1px solid var(--border,#e5e7eb); background:var(--surface-2,#f8fafc);">
        <h3 class="text-sm font-bold mb-2" style="color:var(--text-primary,#0f172a);">A worked example</h3>
        <div class="space-y-2 text-sm" style="color:var(--text-secondary,#475569);">
            <p>
                You hire <span class="font-semibold" style="color:var(--text-primary,#0f172a);">Thandeka</span>. You add her
                once and give her the <span class="font-semibold" style="color:var(--text-primary,#0f172a);">Agent</span> role.
                You configure nothing else. From that moment she can capture a property, load a buyer,
                run a CMA and open a deal — because that is what the Agent role says an agent may do.
            </p>
            <p>
                She <span class="font-semibold">cannot</span> see the agency's commission settings, delete
                another agent's listing, or approve a FICA pack. Not because you blocked Thandeka —
                because the Agent role was never granted those.
            </p>
            <p>
                Six months later you decide agents should be allowed to publish straight to Property24
                without a manager checking first. You do <span class="font-semibold">not</span> go and edit
                Thandeka. You open Role Manager, find <span class="font-semibold">Agent</span>, and switch on
                "publish to portals" — and <span class="font-semibold" style="color:var(--text-primary,#0f172a);">every agent
                in your agency can do it from that moment</span>, including the three you hire next year.
            </p>
            <p class="pt-1" style="color:var(--text-primary,#0f172a);">
                That is the whole idea, and it is also the trap: <span class="font-semibold">a role is never one
                person.</span> If you want to give one individual something extra, promote them to a role that
                has it, or create a new role — don't loosen a role that forty people share.
            </p>
        </div>
    </div>

    {{-- How it meets the branch split --}}
    <div>
        <h3 class="text-sm font-bold mb-1" style="color:var(--text-primary,#0f172a);">How this meets your branch setting</h3>
        <p class="text-sm" style="color:var(--text-secondary,#475569);">
            Roles decide <span class="font-semibold">what</span> a person may do. Branches decide
            <span class="font-semibold">whose records</span> they may do it to. The two work together:
            a Branch Manager in Margate and a Branch Manager in Port Shepstone hold the identical role and
            the identical permissions — they just see different stock, because they sit in different branches.
        </p>
        <p class="text-sm mt-2" style="color:var(--text-secondary,#475569);">
            @if ($splitOn)
                You turned <span class="font-semibold" style="color:var(--text-primary,#0f172a);">branch separation ON</span>
                back in step 3, so this is live for you: your people see their own branch only. The one exception
                is a role holding the "see all branches" permission — your Administrator has it, which is how a
                principal still sees the whole agency.
            @else
                You left <span class="font-semibold" style="color:var(--text-primary,#0f172a);">branch separation OFF</span>
                in step 3, so right now everyone works one shared pool of stock regardless of branch, and the role
                alone decides what they can do. Turn it on later and the same roles immediately start seeing only
                their own branch — you won't need to re-do any of this.
            @endif
        </p>
    </div>

    <p class="text-[11px] italic" style="color:var(--text-muted,#94a3b8);">
        Nothing on this step needs saving — press Continue when you're ready.
    </p>
</div>
