<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Push\PushTestLab;
use App\Support\Push\PushTarget;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * System Developer -> Push Test Lab. Owner-only and DELIBERATELY no permission key (a grantable key could let an agency admin push to any
 * agency's staff and tenants). Every action re-checks isOwnerRole(). Spec: .ai/specs/push-test-lab.md.
 */
class PushTestLabController extends Controller
{
    public function __construct(private PushTestLab $lab) {}

    public function index(Request $request): View
    {
        $this->guard($request);

        $q = trim((string) $request->query('q', ''));
        $who = $this->parseWho((string) $request->query('who', ''));
        $selected = null;
        if ($who) {
            [$kind, $id] = $who;
            $name = $kind === PushTestLab::KIND_CLIENT ? $this->lab->clientName($id) : $this->lab->staffName($id);
            $selected = $name === null ? null : [
                'kind' => $kind, 'id' => $id, 'name' => $name, 'who' => "{$kind}:{$id}",
                'devices' => $this->lab->devices($kind, $id),
            ];
        }
        $staffUser = $selected && $selected['kind'] === PushTestLab::KIND_STAFF ? User::withoutGlobalScopes()->find($selected['id']) : null;

        return view('admin.push-test-lab.index', [
            'q' => $q,
            'staffHits' => $this->lab->searchStaff($q),
            'clientHits' => $this->lab->searchClients($q),
            'selected' => $selected,
            'ready' => $this->lab->transportReady(),
            'staffCatalogue' => $this->lab->staffCatalogue($staffUser),
            'staffNotOnPhone' => $this->lab->staffNotOnPhone(),
            'clientCatalogue' => $this->lab->clientCatalogue(),
            'destinations' => PushTarget::destinations(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        $this->guard($request);
        $data = $request->validate([
            'who' => ['required', 'string', 'max:40'],
            'what' => ['required', 'string', 'max:120'],
            'record_id' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'title' => ['nullable', 'string', 'max:80'],
            'body' => ['nullable', 'string', 'max:200'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $who = $this->parseWho($data['who']);
        abort_unless($who, 422, 'Choose a person first.');
        [$kind, $id] = $who;

        $result = $this->lab->send($kind, $id, $data['what'], isset($data['record_id']) ? (string) $data['record_id'] : null, $data['title'] ?? null, $data['body'] ?? null);
        Log::info('Push Test Lab: test notification', ['by_user' => $request->user()->id, 'to' => $data['who'], 'what' => $data['what'], 'ok' => $result['ok']]);

        return redirect()->route('admin.push-test-lab.index', ['who' => $data['who'], 'q' => $data['q'] ?? ''])
            ->with('push_test_result', $result);
    }

    private function guard(Request $request): void
    {
        abort_unless($request->user() && $request->user()->isOwnerRole(), 403, 'This area is restricted to System Owners.');
    }

    /** @return array{0:string,1:int}|null */
    private function parseWho(string $who): ?array
    {
        if (! preg_match('/^(staff|client):(\d+)$/', $who, $m)) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }
}
