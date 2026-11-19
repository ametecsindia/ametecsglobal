<?php

namespace Tests\Unit;

use App\Services\SensitiveData;
use PHPUnit\Framework\TestCase;

/** 20-Oct-2026 — personal-data detector (DPDP Act). Same vectors as the agent's test/sensitive.test.js. */
class SensitiveDataTest extends TestCase
{
    private function types(array $texts, ?array $kinds = null): array
    {
        $x = 0; $w = [];
        foreach ($texts as $t) { $w[] = ['text' => $t, 'x' => $x, 'y' => 10, 'w' => strlen($t) * 8, 'h' => 14, 'line' => 1]; $x += strlen($t) * 8 + 8; }

        return array_column(SensitiveData::find($w, $kinds ?? SensitiveData::TYPES), 'type');
    }

    public function test_finds_every_kind(): void
    {
        foreach ([
            [['Card:', '4111', '1111', '1111', '1111'], ['card']], [['Aadhaar', '2345', '6789', '0124'], ['aadhaar']],
            [['PAN:', 'ABCDEL234F'], ['pan']], [['A/c:50100123456788'], ['account']], [['Mobile:', '9876543210'], ['phone']],
            [['WhatsApp', '+91', '98765', '43210'], ['phone']], [['Call', '09876543210'], ['phone']], [['Tel', '040-23456789'], ['account']],
            [['Email:', 'ramesh.kumar@gmail.com'], ['email']], [['UPI:', '9876543210@ybl'], ['upi']], [['SSN', '123-45-6789'], ['ssn']],
            [['ABHA', '12-3456-7890-1234'], ['abha']], [['EPIC', 'ABC1234567'], ['voter']], [['Passport', 'J8369854'], ['passport']],
            [['DL:', 'MH12', '20110012345'], ['dl']], [['DL:', 'MH1220110012345'], ['dl']],
        ] as [$w, $e]) $this->assertSame($e, $this->types($w), implode(' ', $w));
    }

    public function test_leaves_ordinary_text_alone_and_each_kind_has_its_own_tick(): void
    {
        foreach ([['Rs', '1,25,000.00'], ['20-10-2026'], ['XXXX', 'XXXX', '1234'], ['Qty', '12', 'Price', '450']] as $w) $this->assertSame([], $this->types($w));
        $this->assertSame([], $this->types(['Mobile:', '9876543210'], ['account']));
        $this->assertSame([], $this->types(['Card:', '4111111111111111'], ['account']));
    }
}
