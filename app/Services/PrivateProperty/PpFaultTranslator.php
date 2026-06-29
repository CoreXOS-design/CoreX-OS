<?php

namespace App\Services\PrivateProperty;

/**
 * Turns a raw Private Property SOAP fault into a short, human message for the
 * agent. PP wraps its real reason inside a .NET SoapException stack trace, e.g.
 *
 *   System.Web.Services.Protocols.SoapException: Server was unable to process
 *   request. ---> ...ASAPIException: PP60 - The address details are
 *   insufficient, please add a Scheme/Complex name and resubmit.
 *      at PPLSystems.Web.WebServices.AgentImport.AgentImport.MapProperty(...)
 *
 * The agent should never see that wall of text — only "Complex/Scheme name is
 * required." The raw fault is still kept in the logs (private_property channel)
 * and in the `raw` key of the SOAP result for debugging.
 */
class PpFaultTranslator
{
    /** Known PP error codes → short, agent-friendly message. */
    private const FRIENDLY = [
        'PP60'  => 'Complex/Scheme name is required for this listing.',
        'PP106' => 'Location is invalid — provide either the PP suburb or the suburb/town/province, not both.',
        'PP119' => 'A street number and street name are required.',
    ];

    public static function friendly(?string $raw): string
    {
        $raw = (string) $raw;

        // 1) PP business error code → mapped short message, else PP's own text.
        //    Stop before the .NET stack trace (" at Some.Namespace.Method(...)").
        if (preg_match('/(PP\d+)\s*-\s*(.*?)(?:\s+at\s+[\w.]+\(|\r|\n|$)/s', $raw, $m)) {
            $code = strtoupper($m[1]);
            return self::FRIENDLY[$code] ?? trim($m[2]);
        }

        // 2) Network / connectivity faults — never show raw socket text.
        foreach (['Error Fetching http headers', 'Could not connect', 'timed out', "Couldn't load from", 'failed to load external entity'] as $needle) {
            if (str_contains($raw, $needle)) {
                return 'Private Property is not responding right now. Please try again in a moment.';
            }
        }

        // 3) .NET inner exception ("---> Some.Exception: <message> at ...").
        if (preg_match('/--->\s*[\w.]+:\s*(.*?)(?:\s+at\s+[\w.]+\(|\r|\n|$)/s', $raw, $m)) {
            $msg = trim($m[1]);
            if ($msg !== '' && !str_contains($msg, 'Server was unable to process request')) {
                return $msg;
            }
        }

        // 4) Fallback — strip any stack trace, keep the first meaningful line.
        $firstLine = trim(strtok($raw, "\n"));
        if ($firstLine === '' || str_contains($firstLine, 'Server was unable to process request')) {
            return 'Private Property could not process this listing. Please check the listing details and try again.';
        }

        return $firstLine;
    }
}
