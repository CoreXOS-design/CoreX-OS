<?php

namespace Tests\Unit\Support;

use App\Support\SaPhoneNumber;
use PHPUnit\Framework\TestCase;

class SaPhoneNumberTest extends TestCase
{
    /**
     * @dataProvider numbers
     */
    public function test_normalize(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, SaPhoneNumber::normalize($input));
    }

    public static function numbers(): array
    {
        return [
            'spaced mobile (the PP107 case)' => ['076 901 7397', '0769017397'],
            'already canonical'              => ['0769017397', '0769017397'],
            'plus-27 spaced'                 => ['+27 76 901 7397', '0769017397'],
            'bare 27 prefix'                 => ['27769017397', '0769017397'],
            'double-zero 27 prefix'          => ['0027769017397', '0769017397'],
            'landline with parens/dash'      => ['(031) 312-1234', '0313121234'],
            'dropped leading zero'           => ['769017397', '0769017397'],
            'tabs and dots'                  => ["076.901.7397", '0769017397'],
            'null'                           => [null, null],
            'blank'                          => ['', null],
            'whitespace only'                => ['   ', null],
            'letters stripped'              => ['Cell: 076 901 7397', '0769017397'],
        ];
    }

    public function test_normalize_is_idempotent(): void
    {
        $once = SaPhoneNumber::normalize('+27 76 901 7397');
        $this->assertSame($once, SaPhoneNumber::normalize($once));
    }
}
