<?php

namespace App\Services\Presentations\Evidence\Parsers;

/**
 * Fallback parser for documents that don't match a known doc type.
 * Stores text only; does not write any evidence rows.
 */
class UnknownParser
{
    public const PARSER_VERSION = 'unknown_v1';
    public const DOC_TYPE       = 'unknown';

    public function parseText(string $text): array
    {
        return [];
    }

    public function parse(string $text): array
    {
        return [
            'parser_version' => self::PARSER_VERSION,
            'doc_type_guess' => self::DOC_TYPE,
            'parsed_counts'  => [],
            'errors'         => [],
        ];
    }
}
