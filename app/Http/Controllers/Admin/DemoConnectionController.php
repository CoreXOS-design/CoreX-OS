<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Demo\DemoControlClient;
use App\Support\Instance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Demo Connection — the DEMO side of the link. Owner-only, demo-instance-only.
 *
 * Spec: .ai/specs/demo-access-control.md §5.2
 *
 * This is where a System Owner, signed in on demo1.corexos.co.za via
 * demo-owner-login, pastes the CoreX URL and the connector token minted on Live.
 * No .env edit, no deploy, no SSH.
 *
 * ══ WHY THIS PAGE IS REACHABLE WHEN THE GATE IS NOT ══
 *
 * The demo gate FAILS CLOSED — if the demo cannot reach primary, nobody gets in. If
 * that also locked out the owner, a bad paste would BRICK the demo: the only way to
 * fix the connection would be the connection. So EnsureDemoGrant exempts
 * demo-owner-login (password-protected, owner-only) and bypasses any signed-in owner
 * on a LOCAL role check that does not consult primary. That is what keeps this page
 * reachable precisely when it is needed.
 *
 * Only exists on a demo host. On primary it 404s — the connector is minted there
 * instead (Dev Settings → Demo Access → Connection).
 */
class DemoConnectionController extends Controller
{
    public function __construct(private readonly DemoControlClient $client)
    {
    }

    private function assertOwnerOnDemo(): void
    {
        abort_unless(Auth::user()?->isOwnerRole(), 403, 'This area is restricted to System Owners.');

        // On primary there is no connection to configure — the connector is MINTED
        // here, not consumed. Rendering a "paste your token" form on live would
        // invite someone to configure the live box to call itself.
        if (! Instance::isDemo()) {
            throw new NotFoundHttpException();
        }
    }

    /** GET /admin/dev-settings/demo-connection */
    public function edit()
    {
        $this->assertOwnerOnDemo();

        return view('admin.demo-connection.edit', [
            'controlUrl'  => Instance::controlUrl(),
            'tokenPrefix' => Instance::controlTokenPrefix(),
            'isWired'     => Instance::isDemoWired(),
            'testResult'  => session('demo_connection_test'),
        ]);
    }

    /** PUT /admin/dev-settings/demo-connection */
    public function update(Request $request)
    {
        $this->assertOwnerOnDemo();

        $data = $request->validate([
            'control_url'   => ['required', 'url', 'max:255'],
            'control_token' => ['nullable', 'string', 'max:255'],
        ], [
            'control_url.required' => 'Enter the CoreX (live) address, e.g. https://corex.hfcoastal.co.za',
            'control_url.url'      => 'That does not look like a valid address. Include https://',
        ]);

        Instance::setControlUrl($data['control_url']);

        // Blank = "leave the existing token alone". The form never renders the
        // secret back (only its prefix), so an empty field means "I did not touch
        // it" — not "clear it". Treating blank as clear would silently break the
        // demo every time someone edited only the URL.
        if (! empty($data['control_token'])) {
            Instance::setControlToken($data['control_token']);
        }

        // The gate caches primary's verdict per session for the TTL. After a
        // re-point those cached verdicts were computed against the OLD connection
        // and are now meaningless — flush them so the new settings take effect at
        // once rather than up to 60s later, which would read as "the save didn't work".
        Cache::flush();

        return redirect()
            ->route('admin.demo-connection.edit')
            ->with('status', 'Connection saved. Use "Test connection" to confirm CoreX answers.');
    }

    /**
     * POST /admin/dev-settings/demo-connection/test
     *
     * Calls primary's /api/v1/demo-access/ping. The whole point is to turn a silent
     * fail-closed gate into a legible sentence at the moment of configuration.
     */
    public function test()
    {
        $this->assertOwnerOnDemo();

        if (! Instance::isDemoWired()) {
            return back()->with('demo_connection_test', [
                'ok'      => false,
                'message' => 'Enter the CoreX address and a connector token first.',
            ]);
        }

        $res = $this->client->ping();

        if (! $res['success']) {
            return back()->with('demo_connection_test', [
                'ok'      => false,
                'message' => $res['status_code'] === 401
                    ? 'CoreX rejected the token. It may have been revoked, or replaced by a newer one — mint a fresh connector on CoreX and paste it here.'
                    : ($res['message'] ?: 'Could not reach CoreX at that address.'),
            ]);
        }

        $data = $res['data'];

        // A live connection with NO published terms is still a hard block: the
        // clickwrap has nothing to render, so every prospect stops at the terms
        // screen. Reporting this as a clean pass would be a lie by omission.
        if (! ($data['tnc_published'] ?? false)) {
            return back()->with('demo_connection_test', [
                'ok'      => false,
                'message' => 'Connected to CoreX successfully — but CoreX has no published demo Terms & Conditions, so every prospect will be blocked at the terms screen. Publish version 1 on CoreX (Dev Settings → Demo Access → Terms & Conditions).',
            ]);
        }

        return back()->with('demo_connection_test', [
            'ok'      => true,
            'message' => 'Connected. CoreX answered as "' . ($data['connector'] ?? 'demo connector')
                       . '", terms version ' . ($data['tnc_version'] ?? '?') . ' is published. The demo is ready for prospects.',
        ]);
    }
}
