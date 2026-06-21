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
    $signingAsLabel = \App\Models\Docuperfect\Template::roleDisplayLabel(
        $partyRole !== '' ? $partyRole : 'recipient',
        $isSales,
        $roleIndex,
        max(1, $allOfRole),
    );
@endphp

<aside class="recipient-info-panel" data-recipient-info-panel>
    <div class="recipient-info-panel__inner">

        {{-- How to sign --}}
        <section class="recipient-info-panel__section">
            <h3 class="recipient-info-panel__heading">{{ $guidance['heading'] ?? 'How to sign' }}</h3>
            <ol class="recipient-info-panel__steps">
                @foreach ($steps as $i => $step)
                    <li class="recipient-info-panel__step">
                        <span class="recipient-info-panel__step-number">{{ $i + 1 }}</span>
                        <div class="recipient-info-panel__step-body">
                            <strong>{{ $step['title'] ?? '' }}</strong>
                            <p>{{ $step['body'] ?? '' }}</p>
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        <div class="recipient-info-panel__divider" aria-hidden="true"></div>

        {{-- Agent contact --}}
        <section class="recipient-info-panel__section recipient-info-panel__agent">
            <h4 class="recipient-info-panel__subheading">{{ $guidance['help_heading'] ?? 'Need help?' }}</h4>
            <p class="recipient-info-panel__help-intro">
                {{ $guidance['help_intro'] ?? 'Call the agent who sent this document.' }}
            </p>
            <div class="recipient-info-panel__agent-name">{{ $agentName }}</div>
            @if ($agentPhone)
                <a href="tel:{{ preg_replace('/\s+/', '', $agentPhone) }}"
                   class="recipient-info-panel__agent-link">
                    {{ $agentPhone }}
                </a>
            @endif
            @if ($agentEmail)
                <a href="mailto:{{ $agentEmail }}"
                   class="recipient-info-panel__agent-link recipient-info-panel__agent-link--soft">
                    {{ $agentEmail }}
                </a>
            @endif
        </section>

        <div class="recipient-info-panel__divider" aria-hidden="true"></div>

        {{-- Signing as --}}
        <section class="recipient-info-panel__section recipient-info-panel__signing-as">
            <h4 class="recipient-info-panel__subheading">Signing as</h4>
            <div class="recipient-info-panel__signer-name">{{ $signingAsName }}</div>
            <div class="recipient-info-panel__signer-role">{{ $signingAsLabel }}</div>
        </section>

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
