<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::find(46);
Illuminate\Support\Facades\Auth::login($user);
$request = Illuminate\Http\Request::create('/admin/api', 'GET');
$request->setUserResolver(fn() => $user);
app()->instance('request', $request);
$response = $kernel->handle($request);
echo "STATUS:".$response->getStatusCode()."\n";
$html = $response->getContent();
file_put_contents(__DIR__.'/scratch_api_out.html', $html);
echo "LEN:".strlen($html)."\n";
