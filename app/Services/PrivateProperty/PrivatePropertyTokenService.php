<?php

namespace App\Services\PrivateProperty;

use App\Models\Agency;
use Illuminate\Support\Str;

class PrivatePropertyTokenService
{
    /**
     * Generate a valid security token for a PP SOAP call.
     *
     * Token construction (Agency Feed Service spec Rev 4.6):
     *  - UID: random UUID per call
     *  - StampTime: current UTC time  (format: 2026-03-23T10:00:00Z)
     *  - Expires: StampTime + 24 hours
     *  - Digest: Base64(SHA1(UID + StampTime + Password + Expires))
     *  - The password is NEVER sent — only used in digest calculation
     */
    public function generate(?Agency $agency = null): array
    {
        $cfg = PrivatePropertyConfig::for($agency);

        $uid       = (string) Str::uuid();
        $stampTime = gmdate('Y-m-d\TH:i:s\Z');
        $expires   = gmdate('Y-m-d\TH:i:s\Z', time() + 86400);
        $password  = $cfg['password'];
        $username  = $cfg['username'];

        $digest = base64_encode(sha1($uid . $stampTime . $password . $expires, true));

        return [
            'Digest'    => $digest,
            'UserName'  => $username,
            'StampTime' => $stampTime,
            'Expires'   => $expires,
            'UID'       => $uid,
        ];
    }
}
