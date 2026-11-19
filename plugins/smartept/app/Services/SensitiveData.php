<?php

namespace App\Services;

/**
 * 20-Oct-2026 (Ejaz): DPDP Act — find personal identifiers in text read off a screen, so they can be
 * blurred. Input is OCR words with boxes; output is the boxes to hide. The values themselves are
 * never returned or stored — only WHERE they were and WHAT kind.
 *
 * Rules (the agent's src/liveview/sensitive.js applies exactly the same ones — keep the two in step):
 *  one word (or two for a driving licence):
 *   email     name@domain.tld                    upi       name@bank (no dot), e.g. 98765…@ybl
 *   ssn       123-45-6789 (US)                   abha      12-3456-7890-1234 (health ID)
 *   pan       ABCDE1234F                         voter     ABC1234567 (EPIC)
 *   passport  A1234567                           dl        MH12 20110012345 (state, RTO, year, number)
 *  digit runs (may be split across words, "4111 1111 1111 1111", or carry a short label "A/c:1234…"):
 *   card      13–19 digits passing Luhn, or 16 written 4-4-4-4
 *   aadhaar   12 digits starting 2–9 passing Verhoeff, or written 4-4-4
 *   phone     Indian mobile / WhatsApp: 10 digits starting 6–9 (optionally 0 / 91 / +91 before it)
 *   account   any other run of 9–18 digits (bank accounts, landlines, customer numbers)
 * Common OCR slips (O→0, I/l/|→1) are corrected. A word matching a one-word rule never joins a digit run.
 * Not detectable by pattern (so not offered): names, addresses, dates of birth.
 */
class SensitiveData
{
    public const TYPES = ['card', 'account', 'upi', 'pan', 'aadhaar', 'passport', 'voter', 'dl', 'ssn', 'abha', 'phone', 'email'];

    /** One-word rules, in the order they are tried. */
    private const WORD = [
        'email'    => '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/',
        'upi'      => '/^[A-Za-z0-9._-]{2,}@[A-Za-z]{2,}$/',
        'ssn'      => '/^\d{3}-\d{2}-\d{4}$/',
        'abha'     => '/^\d{2}-\d{4}-\d{4}-\d{4}$/',
        'voter'    => '/^[A-Z]{3}[0-9]{7}$/',
        'passport' => '/^[A-Z][0-9]{7}$/',
        'dl'       => '/^[A-Z]{2}[0-9]{2}[- ]?(19|20)[0-9]{2}[0-9]{7}$/',
    ];

    /**
     * @param array $words [['text'=>, 'x'=>, 'y'=>, 'w'=>, 'h'=>, 'line'=>key], ...] in reading order
     * @return array [['x','y','w','h','type'], ...] — padded boxes, same units as the input
     */
    public static function find(array $words, array $types = self::TYPES): array
    {
        $types = array_flip($types ?: self::TYPES);
        $lines = [];
        foreach ($words as $w) {
            if (trim((string) ($w['text'] ?? '')) === '') continue;
            $lines[(string) ($w['line'] ?? 0)][] = $w;
        }
        $out = [];
        foreach ($lines as $ws) {
            usort($ws, fn ($a, $b) => $a['x'] <=> $b['x']);
            $ws = array_values($ws);
            $word = []; // index => true when a one-word rule matched (kept out of digit runs)
            foreach ($ws as $i => $w) {
                $t = self::clean($w['text']);
                $type = self::wordType($t);
                if (! $type && isset($ws[$i + 1]) && self::wordType($t . self::clean($ws[$i + 1]['text'])) === 'dl') { // "MH12 20110012345"
                    $word[$i] = $word[$i + 1] = true;
                    if (isset($types['dl'])) $out[] = self::box([$w, $ws[$i + 1]], 'dl');
                    continue;
                }
                if ($type) {
                    $word[$i] = true;
                    if (isset($types[$type])) $out[] = self::box([$w], $type);
                }
            }
            // Digit runs — consecutive digit-like words on the line
            $run = [];
            foreach ($ws as $i => $w) {
                $d = isset($word[$i]) ? null : self::digits($w['text']);
                $near = $run && ($w['x'] - (end($run)['x'] + end($run)['w'])) <= max(2, end($run)['h']) * 2.5;
                if ($d !== null && (! $run || $near)) { $run[] = $w + ['d' => $d]; continue; }
                if ($run) array_push($out, ...self::checkRun($run, $types));
                $run = $d !== null ? [$w + ['d' => $d]] : [];
            }
            if ($run) array_push($out, ...self::checkRun($run, $types));
        }

        return $out;
    }

    private static function wordType(string $t): ?string
    {
        if ($t === '') return null;
        $u = strtoupper($t);
        // PAN: digit slots allow OCR slips (O/I/L/|), but at least two real digits
        if (preg_match('/^[A-Z]{5}([0-9OIL|]{4})[A-Z]$/', $u, $m) && preg_match_all('/\d/', $m[1]) >= 2) return 'pan';
        foreach (self::WORD as $type => $re) {
            if (preg_match($re, in_array($type, ['email', 'upi'], true) ? $t : $u)) return $type;
        }

        return null;
    }

    /** Every sub-run of consecutive words whose digits form a sensitive number. */
    private static function checkRun(array $run, array $types): array
    {
        $out = [];
        $n = count($run);
        $taken = array_fill(0, $n, false);
        for ($len = $n; $len >= 1; $len--) {           // longest first, so 4111 1111 1111 1111 is one card, not four
            for ($i = 0; $i + $len <= $n; $i++) {
                if (in_array(true, array_slice($taken, $i, $len), true)) continue;
                $parts = array_column(array_slice($run, $i, $len), 'd');
                $type = self::classify(implode('', $parts), $parts, $types);
                if ($type) {
                    $out[] = self::box(array_slice($run, $i, $len), $type);
                    for ($k = $i; $k < $i + $len; $k++) $taken[$k] = true;
                }
            }
        }

        return $out;
    }

    public static function classify(string $d, array $parts, array $types): ?string
    {
        $len = strlen($d);
        if ($len < 9 || $len > 19) return null;
        $grouped = fn (int $g, int $c) => count($parts) === $c && count(array_filter($parts, fn ($p) => strlen($p) === $g)) === $c;
        if ($len >= 13 && (self::luhn($d) || ($len === 16 && $grouped(4, 4)))) return isset($types['card']) ? 'card' : null;
        if ($len === 12 && $d[0] >= '2' && (self::verhoeff($d) || $grouped(4, 3))) return isset($types['aadhaar']) ? 'aadhaar' : null;
        // Indian mobile / WhatsApp number, optionally with 0 / 91 in front
        if (preg_match('/^(0|91)?[6-9]\d{9}$/', $d)) return isset($types['phone']) ? 'phone' : null;
        if ($len <= 18) return isset($types['account']) ? 'account' : null;

        return null;
    }

    /** Digits of a digit-like word ("1234", "1234-5678", "A/c:12345678"), else null. */
    private static function digits(string $t): ?string
    {
        $t = self::clean($t);
        if (! preg_match('/^([A-Za-z\/.:#+]{0,6})([0-9OIl|\-\/.]{3,})([.,;:)]?)$/', $t, $m)) return null;
        $core = strtr($m[2], ['O' => '0', 'I' => '1', 'l' => '1', '|' => '1']);
        $d = preg_replace('/\D/', '', $core);
        if (strlen($d) < 3 || strlen($d) < 0.6 * strlen($core)) return null;

        return $d;
    }

    private static function clean(string $t): string
    {
        return trim($t, " \t\"'()[]{}");
    }

    private static function box(array $ws, string $type): array
    {
        $x1 = min(array_column($ws, 'x'));
        $y1 = min(array_column($ws, 'y'));
        $x2 = max(array_map(fn ($w) => $w['x'] + $w['w'], $ws));
        $y2 = max(array_map(fn ($w) => $w['y'] + $w['h'], $ws));
        $p = max(2, (int) round(($y2 - $y1) * 0.35)); // ponytail: padding covers OCR boxes that hug the glyphs

        return ['x' => max(0, $x1 - $p), 'y' => max(0, $y1 - $p), 'w' => $x2 - $x1 + 2 * $p, 'h' => $y2 - $y1 + 2 * $p, 'type' => $type];
    }

    public static function luhn(string $d): bool
    {
        $sum = 0; $alt = false;
        for ($i = strlen($d) - 1; $i >= 0; $i--) {
            $n = (int) $d[$i];
            if ($alt) { $n *= 2; if ($n > 9) $n -= 9; }
            $sum += $n; $alt = ! $alt;
        }

        return $sum % 10 === 0;
    }

    public static function verhoeff(string $d): bool
    {
        static $m = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],
            [5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
        static $p = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],
            [9,4,5,3,1,2,6,8,7,0],[4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
        $c = 0;
        foreach (array_reverse(str_split($d)) as $i => $ch) $c = $m[$c][$p[$i % 8][(int) $ch]];

        return $c === 0;
    }
}
