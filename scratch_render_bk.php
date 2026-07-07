<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user = App\Models\User::whereNotNull('id')->get()->first(fn($u)=>method_exists($u,'hasPermission') && $u->hasPermission('view_backups'));
if(!$user){ echo "NO USER WITH view_backups\n"; exit; }
echo "USER: ".$user->id." ".$user->name."\n";
Illuminate\Support\Facades\Auth::login($user);
$request = Illuminate\Http\Request::create('/corex/admin/backups', 'GET');
$request->setUserResolver(fn() => $user);
app()->instance('request', $request);
$response = $kernel->handle($request);
echo "STATUS:".$response->getStatusCode()."\n";
file_put_contents(__DIR__.'/scratch_bk_out.html', $response->getContent());
echo "LEN:".strlen($response->getContent())."\n";
