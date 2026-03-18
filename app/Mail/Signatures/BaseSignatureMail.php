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
     * Get the From address.
     * - Company-domain agents: send directly from their address.
     * - Personal-email agents: send from system with "Name via Home Finders Coastal".
     * - No agent: system default.
     */
    protected function getFromAddress(): Address
    {
        if (!$this->sendingAgent) {
            return new Address(
                config('mail.from.address'),
                config('mail.from.name', 'Home Finders Coastal')
            );
        }

        $companyDomain = config('signatures.emails.company_domain', 'hfcoastal.co.za');
        $agentEmail = $this->sendingAgent->email;
        $agentName = $this->sendingAgent->name;

        if (str_ends_with(strtolower($agentEmail), '@' . $companyDomain)) {
            return new Address($agentEmail, $agentName);
        }

        // Personal email — send from system but show agent name
        return new Address(
            config('mail.from.address'),
            "{$agentName} via Home Finders Coastal"
        );
    }

    /**
     * Get Reply-To — always the agent's actual email so replies go to them.
     */
    protected function getReplyTo(): array
    {
        if (!$this->sendingAgent) {
            return [];
        }

        return [new Address($this->sendingAgent->email, $this->sendingAgent->name)];
    }

    /**
     * Get agent contact details for the email footer/signature.
     */
    protected function getAgentFooter(): array
    {
        $agency = null;
        if ($this->sendingAgent) {
            $agencyId = $this->sendingAgent->effectiveAgencyId();
            $agency = $agencyId ? Agency::find($agencyId) : Agency::where('slug', 'hfc-coastal')->first();
        } else {
            $agency = Agency::where('slug', 'hfc-coastal')->first();
        }

        if (!$this->sendingAgent) {
            return [
                'name'             => 'Home Finders Coastal',
                'email'            => config('mail.from.address'),
                'phone'            => null,
                'designation'      => null,
                'cell'             => null,
                'fax'              => null,
                'ffc_number'       => null,
                'website'          => $agency->email ?? null,
                'agent_photo_url'  => null,
                'logo_url'         => $agency && $agency->logo_path ? asset('storage/' . $agency->logo_path) : null,
                'email_disclaimer' => $agency->email_disclaimer ?? null,
                'popi_url'         => $agency->popi_url ?? null,
                'agency_name'      => $agency->name ?? 'Home Finders Coastal',
            ];
        }

        $agent = $this->sendingAgent;

        return [
            'name'             => $agent->name,
            'email'            => $agent->email,
            'phone'            => $agent->phone ?? null,
            'designation'      => $agent->designation ?? null,
            'cell'             => $agent->cell ?? null,
            'fax'              => $agent->fax ?? null,
            'ffc_number'       => $agent->ffc_number ?? null,
            'website'          => $agent->website ?? ($agency->email ?? null),
            'agent_photo_url'  => $agent->agent_photo_path ? asset('storage/' . $agent->agent_photo_path) : null,
            'logo_url'         => $agency && $agency->logo_path ? asset('storage/' . $agency->logo_path) : null,
            'email_disclaimer' => $agency->email_disclaimer ?? null,
            'popi_url'         => $agency->popi_url ?? null,
            'agency_name'      => $agency->name ?? 'Home Finders Coastal',
        ];
    }
}
