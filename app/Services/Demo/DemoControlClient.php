<?php

namespace App\Services\Demo;

use App\Support\Instance;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The demo host's HTTP client into PRIMARY.
 *
 * Spec: .ai/specs/demo-access-control.md §5, §13
 *
 * Follows the house outbound-HTTP pattern (Property24ApiClient::request):
 * Http::withToken()->timeout()->connectTimeout(15), try/catch on both
 * ConnectionException and \Exception, NEVER throws to the caller, and always
 * returns the same envelope:
 *
 *     ['success' => bool, 'status_code' => ?int, 'message' => ?string, 'data' => array]
 *
 * WHY IT NEVER THROWS. The two callers want opposite things from a failure:
 *
 *   - EnsureDemoGrant (the gate) must fail CLOSED — no verdict means no entry.
 *   - FlushDemoPageViewJob (telemetry) must fail OPEN — a dropped page view must
 *     never surface to a user.
 *
 * Neither is served by an exception propagating up an unknown stack. Both read
 * ['success'] and decide for themselves. The distinction between "primary said
 * no" (success=true, ok=false in data) and "primary could not be reached"
 * (success=false) is load-bearing: the gate must not tell a prospect their code
 * is wrong when the truth is that our own server is down.
 */
class DemoControlClient
{
    private const TIMEOUT         = 8;    // read: the gate sits in front of a page load
    private const CONNECT_TIMEOUT = 15;   // fast-fail a dead host, per the house pattern

    /** Exchange email + code for a session. */
    public function verify(string $email, string $code, ?string $ip, ?string $userAgent): array
    {
        return $this->post('/api/v1/demo-access/verify', [
            'email'      => $email,
            'code'       => $code,
            'ip'         => $ip,
            'user_agent' => $userAgent,
        ]);
    }

    /** Re-check a live session. The gate calls this every request (cached its side). */
    public function checkSession(string $token): array
    {
        return $this->get('/api/v1/demo-access/session/' . urlencode($token));
    }

    /** Record clickwrap acceptance. */
    public function acceptTnc(string $sessionToken, ?string $ip, ?string $userAgent): array
    {
        return $this->post('/api/v1/demo-access/accept-tnc', [
            'session_token' => $sessionToken,
            'ip'            => $ip,
            'user_agent'    => $userAgent,
        ]);
    }

    /** Telemetry. Called from a queued job, never from the request path. */
    public function pageView(string $sessionToken, string $path, ?string $routeName, ?string $title): array
    {
        return $this->post('/api/v1/demo-access/page-view', [
            'session_token' => $sessionToken,
            'path'          => $path,
            'route_name'    => $routeName,
            'title'         => $title,
        ]);
    }

    // ---- Transport ---------------------------------------------------------

    private function get(string $path): array
    {
        return $this->request('GET', $path, []);
    }

    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    private function request(string $method, string $path, array $payload): array
    {
        $baseUrl = Instance::controlUrl();
        $token   = Instance::controlToken();

        // Misconfiguration, not a network failure. Distinguish it — this is the
        // single most likely cause of "nobody can get into the demo" after a
        // deploy, and "connection failed" would send someone hunting the network.
        if (! $baseUrl || ! $token) {
            Log::error('[demo-access] COREX_DEMO_CONTROL_URL / _TOKEN are not set on this demo host. The gate will fail closed and nobody can enter the demo.', [
                'has_url'   => (bool) $baseUrl,
                'has_token' => (bool) $token,
            ]);

            return [
                'success'     => false,
                'status_code' => null,
                'message'     => 'The demo is not configured to reach the CoreX control server.',
                'data'        => [],
            ];
        }

        try {
            $http = Http::withToken($token)
                ->acceptJson()
                ->timeout(self::TIMEOUT)
                ->connectTimeout(self::CONNECT_TIMEOUT);

            $response = match ($method) {
                'GET'   => $http->get($baseUrl . $path),
                'POST'  => $http->post($baseUrl . $path, $payload),
                default => throw new \InvalidArgumentException("Unsupported method: {$method}"),
            };

            $data = $response->json() ?? [];

            if (! $response->successful()) {
                Log::warning('[demo-access] Control API returned a non-2xx.', [
                    'path'   => $path,
                    'status' => $response->status(),
                ]);

                return [
                    'success'     => false,
                    'status_code' => $response->status(),
                    'message'     => is_array($data) ? ($data['message'] ?? null) : null,
                    'data'        => is_array($data) ? $data : [],
                ];
            }

            return [
                'success'     => true,
                'status_code' => $response->status(),
                'message'     => null,
                'data'        => is_array($data) ? $data : [],
            ];
        } catch (ConnectionException $e) {
            Log::error('[demo-access] Could not reach the control server.', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'success'     => false,
                'status_code' => null,
                'message'     => 'Could not reach the CoreX control server.',
                'data'        => [],
            ];
        } catch (\Exception $e) {
            Log::error('[demo-access] Control API call failed.', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);

            return [
                'success'     => false,
                'status_code' => null,
                'message'     => 'The demo control server returned an unexpected error.',
                'data'        => [],
            ];
        }
    }
}
