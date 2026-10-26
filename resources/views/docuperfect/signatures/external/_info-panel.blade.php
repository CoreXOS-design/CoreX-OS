{{--
  Recipient signing info panel — B3.
  Persistent left rail on desktop (>=1024px), collapsible top banner
  below that. Three blocks: how-to-sign guide, agent contact, signing-as.
  Content for the guide reads from config so it can be localised or
  agency-customised without touching the view.
--}}
@php
    $guidance = config('docuperfect.signing_guidance', []);
    $steps = is_array($guidance['steps'] ?? null) ? $guidance['steps'] : [];

    // Resolve the dispatching agent. Templates ship with a creator; fall
    // back to the document owner where the template predates the agent
    // dispatch convention. Either way we want a real human name + phone.
    $agentUser = null;
    if (isset($template) && $template) {
        $agentUser = $template->creator ?? null;
        if ($agentUser === null && isset($document) && $document) {
            $agentUser = $document->owner ?? null;
        }
    }

    $agentName  = $agentUser?->name ?? 'Your agent';
    $agentPhone = $agentUser?->phone
        ?? $agentUser?->mobile
        ?? $agentUser?->contact_number
        ?? null;
    $agentEmail = $agentUser?->outward_email ?? null; // AT-79 outward override

    // Signing-as label resolution: prefer the B1 indexed identity. Falls
    // back to a humanised party_role when only the legacy column is set.
    $signingAsName  = $currentRecipient->signer_name ?? 'Recipient';
    $partyRole      = strtolower((string) ($currentRecipient->party_role ?? ''));
    $roleIndex      = (int) ($currentRecipient->role_index ?? 1);
    $isSales        = isset($docTemplate) ? $docTemplate->isSalesDocument() : true;
    // Count co-recipients of the same role to drive sing vs plural labels.
    $allOfRole = 1;
    if (isset($template) && $template && $partyRole !== '') {
        $allOfRole = \App\Models\Docuperfect\SignatureRequest::where('signature_template_id', $template->id)
            ->where('party_role', $partyRole)
            ->count();
    }
    $signingAsLabel = $signingAsLabel ?? \App\Models\Docuperfect\Template::roleDisplayLabel(
        $partyRole !== '' ? $partyRole : 'recipient',
        $isSales,
        $roleIndex,
        max(1, $allOfRole),
    );
@endphp

<aside class="recipient-info-panel" data-recipient-info-panel>
    <div class="recipient-info-panel__inner">

        {{-- The three steps (first-time signer flow, esign-signing-modes.md §13.5): one sentence each, the live one lit, finished ones ticked. --}}
        <section class="recipient-info-panel__section">
            <h3 class="recipient-info-panel__heading">{{ $guidance['heading'] ?? 'Your three steps' }}</h3>
            <ol class="recipient-info-panel__steps" data-steps-rail>
                @foreach ($steps as $i => $step)
                    @php $n = $i + 1; @endphp
                    <li class="recipient-info-panel__step" data-step="{{ $n }}"
                        :class="{ 'is-live': signingMethod === 'electronic' && stepNo === {{ $n }}, 'is-done': signingMethod === 'electronic' && stepNo > {{ $n }} }">
                        <span class="recipient-info-panel__step-number">
                            <template x-if="signingMethod === 'electronic' && stepNo > {{ $n }}"><span>&#10003;</span></template>
                            <template x-if="!(signingMethod === 'electronic' && stepNo > {{ $n }})"><span>{{ $n }}</span></template>
                        </span>
                        <div class="recipient-info-panel__step-body">
                            @if (!empty($step['title_each']))
                                <strong x-show="adoption && adoption.required">{{ $step['title'] ?? '' }}</strong>
                                <strong x-show="!(adoption && adoption.required)">{{ $step['title_each'] }}</strong>
                            @else
                                <strong>{{ $step['title'] ?? '' }}</strong>
                            @endif
                            <p x-show="adoption && adoption.required">{{ $step['body'] ?? '' }}</p>
                            <p x-show="!(adoption && adoption.required)">{{ $step['body_each'] ?? ($step['body'] ?? '') }}</p>
                            @if ($n === 1)
                                <button type="button" data-change-signature class="recipient-info-panel__link"
                                        x-show="adoption && adoption.required && adoption.adopted" x-cloak @click="showAdoption = true">Change my signature</button>
                            @endif
                            @if ($n === 2)
                                <ul class="recipient-info-panel__fields" x-show="detailsToFill.length > 0" x-cloak data-details-to-fill>
                                    <li class="recipient-info-panel__fields-count" x-text="detailsToFill.length + (detailsToFill.length === 1 ? ' detail to fill' : ' details to fill')"></li>
                                    <template x-for="(d, i) in detailsToFill" :key="'df' + i">
                                        <li><button type="button" class="recipient-info-panel__link" @click="goToDetail(i)" x-text="d.label"></button></li>
                                    </template>
                                </ul>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
            <p class="recipient-info-panel__progress" data-rail-progress x-show="isWebTemplate && signingMethod === 'electronic'" x-cloak x-text="progressText"></p>
        </section>

        <div class="recipient-info-panel__divider" aria-hidden="true"></div>

        {{-- Help: one line --}}
        <p class="recipient-info-panel__help" data-help-line>
            Questions? Call <strong>{{ $agentName }}</strong>@if ($agentPhone) <a href="tel:{{ preg_replace('/\s+/', '', $agentPhone) }}" class="recipient-info-panel__agent-link" style="display:inline;">{{ $agentPhone }}</a>@endif
        </p>

    </div>
</aside>

<style>
    .recipient-info-panel {
        display: none;
    }
    @media (min-width: 1024px) {
        .recipient-info-panel {
            display: block;
            position: fixed;
            top: 0;
            left: 0;
            width: 280px;
            height: 100vh;
            background: #f8fafc;
            border-right: 1px solid #e2e8f0;
            overflow-y: auto;
            z-index: 30;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        body.has-recipient-info-panel .recipient-info-main {
            margin-left: 304px;
        }
    }
    .recipient-info-panel__inner {
        padding: 24px 20px;
    }
    .recipient-info-panel__section + .recipient-info-panel__section { margin-top: 0; }
    .recipient-info-panel__heading {
        font-size: 15px;
        font-weight: 600;
        color: #0f172a;
        margin: 0 0 14px 0;
        letter-spacing: -0.01em;
    }
    .recipient-info-panel__subheading {
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #64748b;
        margin: 0 0 10px 0;
    }
    .recipient-info-panel__steps {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .recipient-info-panel__step {
        display: flex;
        gap: 10px;
        align-items: flex-start;
    }
    .recipient-info-panel__step-number {
        flex-shrink: 0;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        background: #00d4aa;
        color: white;
        font-size: 12px;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
    }
    .recipient-info-panel__step-body strong {
        display: block;
        font-size: 13px;
        color: #0f172a;
        font-weight: 600;
        margin-bottom: 2px;
    }
    .recipient-info-panel__step-body p {
        font-size: 12px;
        line-height: 1.45;
        color: #475569;
        margin: 0;
    }
    .recipient-info-panel__divider {
        height: 1px;
        background: #e2e8f0;
        margin: 22px 0;
    }
    .recipient-info-panel__help-intro {
        font-size: 12px;
        line-height: 1.45;
        color: #64748b;
        margin: 0 0 10px 0;
    }
    .recipient-info-panel__agent-name {
        font-size: 14px;
        font-weight: 600;
        color: #0f172a;
        margin-bottom: 4px;
    }
    .recipient-info-panel__agent-link {
        display: block;
        font-size: 13px;
        color: #00d4aa;
        text-decoration: none;
        margin-bottom: 2px;
    }
    .recipient-info-panel__agent-link:hover { text-decoration: underline; }
    .recipient-info-panel__agent-link--soft {
        color: #64748b;
        font-size: 12px;
    }
    .recipient-info-panel__step { opacity: .55; }
    .recipient-info-panel__step.is-live, .recipient-info-panel__step.is-done { opacity: 1; }
    .recipient-info-panel__step.is-live { background: #ecfdf5; border-radius: 8px; padding: 8px; margin: -8px; box-shadow: 0 0 0 1px #6ee7b7; }
    .recipient-info-panel__step.is-done .recipient-info-panel__step-number { background: #059669; }
    .recipient-info-panel__link { background: none; border: 0; padding: 2px 0; font-size: 12px; font-weight: 600; color: #0f766e; text-decoration: underline; cursor: pointer; text-align: left; }
    .recipient-info-panel__fields { list-style: none; padding: 0; margin: 6px 0 0 0; }
    .recipient-info-panel__fields li { font-size: 12px; color: #475569; }
    .recipient-info-panel__fields-count { font-weight: 700; color: #92400e; margin-bottom: 2px; }
    .recipient-info-panel__progress { font-size: 12px; font-weight: 600; color: #334155; margin: 14px 0 0 0; }
    .recipient-info-panel__help { font-size: 12px; line-height: 1.5; color: #475569; margin: 0; }
    .recipient-info-panel__signer-name {
        font-size: 14px;
        font-weight: 600;
        color: #0f172a;
        margin-bottom: 2px;
    }
    .recipient-info-panel__signer-role {
        font-size: 12px;
        color: #64748b;
    }

    /* Below 1024px — collapsed top banner with only the essentials. */
    @media (max-width: 1023px) {
        .recipient-info-panel--mobile {
            display: block;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            padding: 12px 16px;
            font-size: 13px;
            color: #475569;
        }
        .recipient-info-panel--mobile .recipient-info-panel__inner {
            padding: 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .recipient-info-panel--mobile .recipient-info-panel__steps,
        .recipient-info-panel--mobile .recipient-info-panel__divider,
        .recipient-info-panel--mobile .recipient-info-panel__heading,
        .recipient-info-panel--mobile .recipient-info-panel__help-intro {
            display: none;
        }
        .recipient-info-panel--mobile .recipient-info-panel__signing-as,
        .recipient-info-panel--mobile .recipient-info-panel__agent {
            margin: 0;
        }
    }
</style>
