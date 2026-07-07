<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::find(48);
Illuminate\Support\Facades\Auth::login($user);
$request = Illuminate\Http\Request::create('/corex/settings/agencies', 'GET');
$request->setUserResolver(fn() => $user);
app()->instance('request', $request);
$response = $kernel->handle($request);
echo "STATUS:".$response->getStatusCode()."\n";
echo $response->getContent();
