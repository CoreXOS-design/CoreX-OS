<?php

namespace App\Services\Importer;

class P24AgentsCsvParser
{
    /**
     * Returns: array of row arrays with keys: external_id, payload, mapped, errors.
     */
    public function parse(string $path): array
    {
        $rows = [];
        $seenEmails = [];
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open agents CSV: {$path}");
        }
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return $rows;
        }

        while (($data = fgetcsv($handle)) !== false) {
            if (count($data) === 1 && ($data[0] === null || $data[0] === '')) continue;
            $raw = array_combine($header, array_pad($data, count($header), null)) ?: [];

            $errors = [];
            $agentId = $raw['AgentId'] ?? null;
            if (!is_numeric($agentId)) {
                $errors[] = 'Invalid AgentId';
            }
            $email = trim((string)($raw['EmailAddress'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Invalid or missing EmailAddress';
            } else {
                $lower = strtolower($email);
                if (isset($seenEmails[$lower])) {
                    $errors[] = "Duplicate email in file: {$email}";
                } else {
                    $seenEmails[$lower] = true;
                }
            }

            $name = trim(($raw['Firstname'] ?? '') . ' ' . ($raw['Lastname'] ?? ''));
            $mapped = [
                'p24_agent_id'        => is_numeric($agentId) ? (int)$agentId : null,
                'name'                => $name,
                'email'               => $email,
                'phone'               => $raw['MobileNumber'] ?? null,
                'cell'                => $raw['MobileNumber'] ?? null,
                'work_phone'          => $raw['WorkNumber'] ?? null,
                'bio'                 => $raw['About'] ?? null,
                'designation'         => $raw['Qualification'] ?? null,
                'profile_photo_url'   => $raw['Property24ProfilePictureURL'] ?? null,
                'is_active'           => false, // always inactive on import
                'is_published'        => (string)($raw['Published'] ?? '0') === '1',
                'source_reference'    => $raw['SourceReference'] ?? null,
                'p24_status'          => $raw['Status'] ?? null,
            ];

            $rows[] = [
                'external_id' => (string)($agentId ?? ''),
                'payload'     => $raw,
                'mapped'      => $mapped,
                'errors'      => $errors,
                'action'      => 'create',
            ];
        }
        fclose($handle);
        return $rows;
    }
}
