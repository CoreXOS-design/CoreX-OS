<?php
/**
 * P24 Vetting — create two test agents (Jon Snow, Pauly Shore) with photos.
 * Run: php artisan tinker --execute="require 'scripts/p24-create-vetting-agents.php';"
 */

use App\Services\Syndication\Property24\Property24ApiClient;

$client = app(Property24ApiClient::class);
$agencyId = (int) config('services.property24_syndication.agency_id');

$agents = [
    [
        'firstname'        => 'Jon',
        'lastname'         => 'Snow',
        'emailAddress'     => 'jon.snow+vetting@hfcoastal.co.za',
        'mobileNumber'     => '0825550101',
        'sourceReference'  => 'CoreX-Vetting-JonSnow',
        'jobTitle'         => 'Sales Agent',
        'description'      => 'Vetting test agent.',
        'photoUrl'         => 'https://i.pravatar.cc/400?img=12',
    ],
    [
        'firstname'        => 'Pauly',
        'lastname'         => 'Shore',
        'emailAddress'     => 'pauly.shore+vetting@hfcoastal.co.za',
        'mobileNumber'     => '0825550202',
        'sourceReference'  => 'CoreX-Vetting-PaulyShore',
        'jobTitle'         => 'Sales Agent',
        'description'      => 'Vetting test agent.',
        'photoUrl'         => 'https://i.pravatar.cc/400?img=33',
    ],
];

foreach ($agents as $a) {
    $payload = [
        'agencyId'         => $agencyId,
        'firstname'        => $a['firstname'],
        'lastname'         => $a['lastname'],
        'emailAddress'     => $a['emailAddress'],
        'mobileNumber'     => $a['mobileNumber'],
        'sourceReference'  => $a['sourceReference'],
        'published'        => true,
        'receiveStatsMail' => false,
        'countryId'        => 1,
        'jobTitle'         => $a['jobTitle'],
        'description'      => $a['description'],
    ];

    echo "\n--- Creating {$a['firstname']} {$a['lastname']} ---\n";
    $res = $client->createAgent($payload);

    if (!$res['success']) {
        echo "FAILED: " . ($res['message'] ?? 'unknown') . "\n";
        echo json_encode($res['data'] ?? [], JSON_PRETTY_PRINT) . "\n";
        continue;
    }

    $agentId = $res['data']['id'] ?? $res['data']['Id'] ?? null;
    echo "agentId = {$agentId}\n";

    // Upload photo
    try {
        $bytes = @file_get_contents($a['photoUrl']);
        if ($bytes && strlen($bytes) > 100) {
            $up = $client->uploadAgentPhoto((int) $agentId, [
                'bytes'           => base64_encode($bytes),
                'mimeContentType' => 'image/jpeg',
            ]);
            echo "photo upload: " . ($up['success'] ? 'OK' : ('FAIL — ' . ($up['message'] ?? '?'))) . "\n";
        } else {
            echo "photo fetch failed, skipped upload\n";
        }
    } catch (\Throwable $e) {
        echo "photo error: {$e->getMessage()}\n";
    }
}

echo "\nDone.\n";
