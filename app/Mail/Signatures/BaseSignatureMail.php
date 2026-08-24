<?php

namespace App\Mail\Signatures;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

abstract class BaseSignatureMail extends Mailable
{
    use Queueable, SerializesModels;

    protected ?User $sendingAgent = null;

    /** Per-instance memo for resolveAgencyForAgent() — see its docblock. */
    private ?Agency $resolvedAgencyMemo = null;
    private bool $resolvedAgencyMemoSet = false;

    /**
     * Free/public email providers a CoreX-hosted domain is never SPF/DKIM
     * authorized to send as. Without this guard, an agency whose contact
     * email happens to be a personal address (e.g. admin@gmail.com) would
     * make companyDomainForAgent() trust "gmail.com" as their company
     * domain, and any agent with a personal gmail address would be sent
     * DIRECTLY from that address — which real mail providers reject or
     * spam-fold, since CoreX's infrastructure has no sending authority for
     * gmail.com. Not exhaustive; covers the common cases (found in review,
     * 2026-08-12).
     */
    private const PUBLIC_EMAIL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'yahoo.co.uk', 'outlook.com',
        'hotmail.com', 'live.com', 'icloud.com', 'me.com', 'aol.com',
        'protonmail.com', 'proton.me', 'mail.com', 'gmx.com', 'yandex.com', 'zoho.com',
    ];

    /**
     * Set the agent who is sending this email.
     * External-facing emails should always call this.
     */
    public function fromAgent(?User $agent): static
    {
        $this->sendingAgent = $agent;
        return $this;
    }

    /**
     * The sending agent's own agency, resolved once and memoized (found in
     * review, 2026-08-12): getFromAddress() and getAgentFooter() each used
     * to run their own independent Agency::find($agencyId) for the SAME
     * agent, doubling the DB round-trips per email — real cost in bulk-send
     * loops (SendSalesDocumentReminders and friends).
     */
    private function resolveAgencyForAgent(User $agent): ?Agency
    {
        if ($this->resolvedAgencyMemoSet) {
            return $this->resolvedAgencyMemo;
        }

        $agencyId = $agent->effectiveAgencyId();
        $this->resolvedAgencyMemo = $agencyId ? Agency::find($agencyId) : null;
        $this->resolvedAgencyMemoSet = true;

        return $this->resolvedAgencyMemo;
    }

    /**
     * Get the From address.
     * - Company-domain agents: send directly from their address.
     * - Personal-email agents: send from system with "Name via CoreX OS".
     * - No agent: system default.
     */
    protected function getFromAddress(): Address
    {
        if (!$this->sendingAgent) {
            return new Address(
                config('mail.from.address'),
                config('mail.from.name', 'CoreX OS')
            );
        }

        $companyDomain = $this->companyDomainForAgent($this->sendingAgent);
        // AT-79 — outward-facing identity: use the display_email override when
        // set (falls back to the real email). Same company domain, so the
        // domain check below is unaffected.
        $agentEmail = $this->sendingAgent->outward_email;
        $agentName = $this->sendingAgent->name;

        if ($companyDomain && str_ends_with(strtolower($agentEmail), '@' . $companyDomain)) {
            return new Address($agentEmail, $agentName);
        }

        // Personal email — send from system but show agent name
        return new Address(
            config('mail.from.address'),
            "{$agentName} via CoreX OS"
        );
    }

    /**
     * The email domain THIS agent's own agency uses, derived from the
     * agency's own contact email (e.g. admin@hfcoastal.co.za -> hfcoastal.co.za).
     *
     * Was previously a single hardcoded config value (signatures.emails.
     * company_domain, default 'hfcoastal.co.za') — correct only for CoreX's
     * first tenant, and silently wrong for every other agency on the platform:
     * their agents' real company-domain emails would never match the
     * hardcoded domain and would incorrectly fall into the "personal email"
     * branch every time. Fixed 2026-08-12 to resolve per-agency instead.
     *
     * Returns null (never send directly as the agent) when:
     *  - the agency has no email on file to derive a domain from — absent
     *    data means "can't confirm this is the company domain", not "assume
     *    it matches anyway"; or
     *  - the derived domain is a known public/free email provider (see
     *    PUBLIC_EMAIL_DOMAINS) — agencies.email is a free-text contact field
     *    with no format validation and no domain-ownership verification
     *    (found in review, 2026-08-12), so an agency whose contact email
     *    happens to be e.g. admin@gmail.com must never be trusted as a
     *    "verified sending domain" — CoreX has no SPF/DKIM authority to send
     *    as gmail.com, and doing so gets flagged as spoofing.
     */
    private function companyDomainForAgent(User $agent): ?string
    {
        $agencyEmail = $this->resolveAgencyForAgent($agent)?->email;

        if (!$agencyEmail || !str_contains($agencyEmail, '@')) {
            return null;
        }

        $domain = strtolower(trim(explode('@', $agencyEmail, 2)[1]));

        return in_array($domain, self::PUBLIC_EMAIL_DOMAINS, true) ? null : $domain;
    }

    /**
     * Get Reply-To — the agent's outward-facing email so replies go to the
     * address the recipient sees (display_email override when set, else the
     * real email). AT-79.
     */
    protected function getReplyTo(): array
    {
        if (!$this->sendingAgent) {
            return [];
        }

        return [new Address($this->sendingAgent->outward_email, $this->sendingAgent->name)];
    }

    /**
     * Get agent contact details for the email footer/signature.
     */
    protected function getAgentFooter(): array
    {
        $agency = null;
        $branch = null;
        if ($this->sendingAgent) {
            $agency = $this->resolveAgencyForAgent($this->sendingAgent);
            // Phase 9c-1 — resolve sending agent's branch so PPRA + FFC can
            // cascade branch → agency.
            $branchId = $this->sendingAgent->effectiveBranchId();
            $branch   = $branchId ? \App\Models\Branch::find($branchId) : null;
        }
        // NOTE (found in review, 2026-08-12, not fixed here — needs a real
        // agency context threaded through, not a quick patch): the "no
        // sending agent" case (below) has no way to know WHICH agency a
        // system-level signature email belongs to, so it falls back to
        // config('mail.from.name') for the display name but still has no
        // per-tenant agency (logo, PPRA number, disclaimer, POPI URL) to
        // show — those fields render null/absent rather than showing the
        // wrong tenant's data, which is the safe failure mode until agency
        // context is threaded through every no-agent call site.

        // Branch-or-agency cascade for regulatory numbers.
        $agencyPpra = $branch?->ppra_number ?: ($agency?->ppra_number ?? null);

        if (!$this->sendingAgent) {
            // $agency is always null here (see the NOTE above) — no per-tenant
            // fields to show, so they render absent rather than guessing.
            return [
                'name'             => config('mail.from.name', 'CoreX OS'),
                'email'            => config('mail.from.address'),
                'phone'            => null,
                'designation'      => null,
                'cell'             => null,
                'fax'              => null,
                'ffc_number'       => null,
                'agency_ppra_number' => $agencyPpra,
                'website'          => $agency->website ?? null, // AT-296 — never leak the agency admin@ inbox as a "website"
                'agent_photo_url'  => null,
                'logo_url'         => null,
                'email_disclaimer' => null,
                'popi_url'         => null,
                'agency_name'      => config('mail.from.name', 'CoreX OS'),
            ];
        }

        $agent = $this->sendingAgent;

        return [
            'name'             => $agent->name,
            'email'            => $agent->outward_email, // AT-79 outward override
            'phone'            => $agent->phone ?? null,
            'designation'      => $agent->designation ?? null,
            'cell'             => $agent->cell ?? null,
            'fax'              => $agent->fax ?? null,
            'ffc_number'       => $agent->ffc_number ?? null,
            'agency_ppra_number' => $agencyPpra,
            'website'          => $agent->website ?? ($agency->website ?? null), // AT-296 — agent's own site, else agency site; never the admin@ inbox
            'agent_photo_url'  => $agent->agent_photo_path ? asset('storage/' . $agent->agent_photo_path) : null,
            'logo_url'         => $agency?->logo_path ? asset('storage/' . $agency->logo_path) : null,
            'email_disclaimer' => $agency?->email_disclaimer,
            'popi_url'         => $agency?->popi_url,
            // $agency can be null here too — an agent with no resolvable
            // agency (e.g. a System Owner passed to fromAgent()). Falls back
            // to the platform identity rather than a specific tenant's name.
            'agency_name'      => $agency?->name ?? config('mail.from.name', 'CoreX OS'),
        ];
    }
}
