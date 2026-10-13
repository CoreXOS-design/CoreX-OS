<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\PortalDemoSignin;
use App\Support\PortalDemoSigninGate;
use Illuminate\Console\Command;

/** QA DEMO SIGN-IN (never on Staging/live): issue or revoke a one-click portal link for a demo contact. Refuses unless the gate is open. */
class PortalDemoSigninCommand extends Command
{
    protected $signature = 'portal:demo-signin {contact : Contact id} {role : owner or tenant} {--revoke : Revoke this contact\'s links instead of issuing one}';

    protected $description = 'QA test site only: issue (or --revoke) a one-click portal sign-in link for a demo contact';

    public function handle(): int
    {
        if (! PortalDemoSigninGate::open()) {
            $this->error('The demo sign-in is not available here (it needs APP_ENV=qa and PORTAL_DEMO_SIGNIN=true).');

            return self::FAILURE;
        }
        $role = (string) $this->argument('role');
        if (! in_array($role, PortalDemoSignin::ROLES, true)) {
            $this->error('Role must be owner or tenant.');

            return self::FAILURE;
        }
        $contact = Contact::withoutGlobalScopes()->whereNull('deleted_at')->find((int) $this->argument('contact'));
        if (! $contact) {
            $this->error('No such contact.');

            return self::FAILURE;
        }
        if ($this->option('revoke')) {
            $this->info(PortalDemoSignin::revokeFor((int) $contact->id, $role) . ' link(s) revoked.');

            return self::SUCCESS;
        }
        $token = PortalDemoSignin::issue($contact, $role);
        $this->line(url('/demo-signin/' . $token));

        return self::SUCCESS;
    }
}
