<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

class ApiCatalogController extends Controller
{
    public function index()
    {
        $routes = collect(Route::getRoutes())
            ->filter(fn (LaravelRoute $r) => $this->isApiRoute($r))
            ->map(fn (LaravelRoute $r) => $this->describe($r))
            ->sortBy(['group', 'uri'])
            ->values();

        $groups = $routes->groupBy('group');

        return view('admin.api.index', [
            'groups' => $groups,
            'total'  => $routes->count(),
        ]);
    }

    private function isApiRoute(LaravelRoute $r): bool
    {
        $uri = $r->uri();
        return str_starts_with($uri, 'api/')
            || str_starts_with($uri, 'api')
            || in_array('api', $r->gatherMiddleware(), true);
    }

    private function describe(LaravelRoute $r): array
    {
        $action = $r->getActionName();
        $middleware = collect($r->gatherMiddleware())
            ->reject(fn ($m) => in_array($m, ['web', 'api'], true))
            ->values()
            ->all();

        $uri = $r->uri();
        return [
            'methods'    => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->implode('|'),
            'uri'        => '/' . ltrim($uri, '/'),
            'name'       => $r->getName(),
            'action'     => $action,
            'middleware' => $middleware,
            'group'      => $this->groupFor($uri, $action),
        ];
    }

    private function groupFor(string $uri, string $action): string
    {
        $segments = explode('/', $uri);
        // api/v1/properties/{property} → "v1 / properties"
        if (($segments[0] ?? '') === 'api') {
            $version = $segments[1] ?? 'misc';
            $resource = $segments[2] ?? 'root';
            if (in_array($version, ['v1', 'v2', 'v3'], true)) {
                return "API {$version} — " . ucfirst($resource);
            }
            return 'API — ' . ucfirst($version);
        }
        return 'Other';
    }
}
