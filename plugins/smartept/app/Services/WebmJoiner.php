<?php

namespace App\Services;

/**
 * 20-Oct-2026 (Ejaz: "1-minute videos create a lot of files"): joins the agent's 1-minute WebM
 * recordings (Chrome MediaRecorder, VP8, no audio) into ONE playable, seekable WebM — in plain PHP,
 * because client servers have no ffmpeg. Nothing is re-encoded: the frames are copied as they are and
 * only their timestamps are shifted so the minutes play one after another (gaps between recordings are
 * skipped, like the player's seek bar). A Duration, SeekHead and Cues are written so players show the
 * length and can seek.
 *
 * compatible() says whether two files can share one output (same codec + picture size); the caller
 * starts a new file when they cannot (e.g. the quality setting changed during the day).
 */
class WebmJoiner
{
    private const EBML = "\x1A\x45\xDF\xA3", SEGMENT = "\x18\x53\x80\x67", INFO = "\x15\x49\xA9\x66",
        TRACKS = "\x16\x54\xAE\x6B", CLUSTER = "\x1F\x43\xB6\x75", CUES = "\x1C\x53\xBB\x6B",
        SEEKHEAD = "\x11\x4D\x9B\x74", TIMECODE = "\xE7", SIMPLEBLOCK = "\xA3", BLOCKGROUP = "\xA0";

    /** Top-level ids that end an unknown-size Cluster. */
    private const LEVEL1 = ["\x1F\x43\xB6\x75", "\x1C\x53\xBB\x6B", "\x12\x54\xC3\x67", "\x10\x43\xA7\x70",
        "\x19\x41\xA4\x69", "\x11\x4D\x9B\x74", "\x15\x49\xA9\x66", "\x16\x54\xAE\x6B"];

    /**
     * Parse one recording: ['ebml' => header bytes, 'tracks' => Tracks element bytes, 'key' => codec|w|h,
     * 'scale' => TimecodeScale, 'clusters' => [[timecode, [child element bytes...], keyframe-first], ...],
     * 'end' => last block time (ms, relative to the file start)].
     */
    public static function parse(string $b): ?array
    {
        $p = 0; $n = strlen($b);
        [$id, $len, $p] = self::head($b, $p);
        if ($id !== self::EBML) return null;
        $ebml = substr($b, 0, $p + $len); $p += $len;
        [$id, $len, $p] = self::head($b, $p);
        if ($id !== self::SEGMENT) return null;
        $segEnd = $len === null ? $n : min($n, $p + $len);
        $out = ['ebml' => $ebml, 'tracks' => null, 'key' => '', 'scale' => 1000000, 'clusters' => [], 'end' => 0];
        while ($p < $segEnd) {
            $start = $p;
            [$id, $len, $p] = self::head($b, $p);
            if ($id === null) break;
            if ($id === self::CLUSTER) {
                $end = $len === null ? self::unknownEnd($b, $p, $segEnd) : min($segEnd, $p + $len);
                $tc = 0; $kids = []; $first = null;
                while ($p < $end) {
                    $cs = $p;
                    [$cid, $clen, $p] = self::head($b, $p);
                    if ($cid === null || $clen === null) { $p = $end; break; }
                    $raw = substr($b, $cs, $p - $cs + $clen);
                    if ($cid === self::TIMECODE) {
                        $tc = self::uint(substr($b, $p, $clen));
                    } else {
                        $kids[] = $raw;
                        if ($cid === self::SIMPLEBLOCK) {
                            [, , $q] = self::vint($b, $p); // track number
                            $rel = unpack('n', substr($b, $q, 2))[1]; if ($rel >= 0x8000) $rel -= 0x10000;
                            $first ??= (ord($b[$q + 2]) & 0x80) !== 0;
                            $out['end'] = max($out['end'], $tc + $rel);
                        } elseif ($cid === self::BLOCKGROUP) {
                            // Block (A1) + optional BlockDuration (9B); a ReferenceBlock (FB) = not a keyframe.
                            $g = $p; $rel = 0; $dur = 0; $ref = false;
                            while ($g < $p + $clen) {
                                [$gid, $glen, $g] = self::head($b, $g);
                                if ($gid === null || $glen === null) break;
                                if ($gid === "\xA1") {
                                    [, , $q] = self::vint($b, $g);
                                    $rel = unpack('n', substr($b, $q, 2))[1]; if ($rel >= 0x8000) $rel -= 0x10000;
                                } elseif ($gid === "\x9B") $dur = self::uint(substr($b, $g, $glen));
                                elseif ($gid === "\xFB") $ref = true;
                                $g += $glen;
                            }
                            $first ??= ! $ref;
                            $out['end'] = max($out['end'], $tc + $rel + $dur);
                        }
                    }
                    $p += $clen;
                }
                $out['clusters'][] = [$tc, $kids, (bool) $first];
                $p = $end;
                continue;
            }
            if ($len === null) break; // only Segment / Cluster may be unknown-size
            if ($id === self::TRACKS) {
                $out['tracks'] = substr($b, $start, $p - $start + $len);
                $out['key'] = self::trackKey(substr($b, $p, $len));
            } elseif ($id === self::INFO) {
                $q = $p;
                while ($q < $p + $len) {
                    [$iid, $ilen, $q] = self::head($b, $q);
                    if ($iid === "\x2A\xD7\xB1") $out['scale'] = self::uint(substr($b, $q, $ilen)) ?: 1000000;
                    $q += $ilen;
                }
            }
            $p += $len;
        }

        return $out['tracks'] ? $out : null;
    }

    public static function compatible(array $a, array $b): bool
    {
        return $a['key'] === $b['key'] && $a['scale'] === $b['scale'];
    }

    /**
     * Write $parts (closures returning a parse() result — loaded one at a time so a long day never sits
     * in memory at once; all compatible, in play order) to $path as one WebM.
     * $lengthsMs[i] = how long part i plays (its recorded length); the next part starts right after.
     */
    public static function write(array $parts, array $lengthsMs, string $path): void
    {
        $f = fopen($path, 'wb');
        $first = $parts[0]();
        $scale = $first['scale'];
        fwrite($f, $first['ebml']);
        fwrite($f, self::SEGMENT . "\x01" . str_repeat("\x00", 7)); // 8-byte size, patched at the end
        $seg0 = ftell($f);

        // SeekHead with fixed-width positions (patched at the end) → Info, Tracks, Cues.
        $seek = fn ($id) => self::el("\x4D\xBB", self::el("\x53\xAB", $id) . "\x53\xAC\x88" . str_repeat("\x00", 8));
        fwrite($f, self::el(self::SEEKHEAD, $seek(self::INFO) . $seek(self::TRACKS) . $seek(self::CUES)));
        $infoAt = ftell($f) - $seg0;
        $info = self::el("\x2A\xD7\xB1", self::uintBytes($scale))
            . self::el("\x4D\x80", 'SmartEPT') . self::el("\x57\x41", 'SmartEPT')
            . "\x44\x89\x88" . str_repeat("\x00", 8); // Duration (float64), patched at the end
        fwrite($f, self::el(self::INFO, $info));
        $durAt = ftell($f) - 8;
        $tracksAt = ftell($f) - $seg0;
        fwrite($f, $first['tracks']);

        $cues = ''; $offset = 0; $total = 0; $msPerTick = $scale / 1000000;
        foreach ($parts as $i => $load) {
            $pt = $i === 0 ? $first : $load();
            if (! $pt) continue; // unreadable minute — skipped
            $shift = (int) round($offset / $msPerTick);
            foreach ($pt['clusters'] as [$tc, $kids, $key]) {
                $abs = $tc + $shift;
                if ($key) {
                    $cues .= self::el("\xBB", self::el("\xB3", self::uintBytes($abs))
                        . self::el("\xB7", self::el("\xF7", "\x01") . self::el("\xF1", self::uintBytes(ftell($f) - $seg0))));
                }
                fwrite($f, self::el(self::CLUSTER, self::el(self::TIMECODE, self::uintBytes($abs)) . implode('', $kids)));
            }
            $len = max((int) $lengthsMs[$i], (int) ceil($pt['end'] * $msPerTick) + 1);
            $offset += $len;
            $total = $offset;
        }
        $cuesAt = ftell($f) - $seg0;
        if ($cues !== '') fwrite($f, self::el(self::CUES, $cues));
        $segLen = ftell($f) - $seg0;

        // Patch: Segment size, Duration, SeekHead positions.
        fseek($f, $seg0 - 8); fwrite($f, "\x01" . substr(pack('J', $segLen), 1));
        fseek($f, $durAt); fwrite($f, pack('E', $total / $msPerTick));
        $pos = $seg0 + strlen(self::SEEKHEAD) + 1; // SeekHead body starts after its id + 1-byte size
        foreach ([$infoAt, $tracksAt, $cuesAt] as $k => $v) {
            fseek($f, $pos + ($k + 1) * 21 - 8); fwrite($f, pack('J', $v)); // each Seek entry is 21 bytes, position last
        }
        fclose($f);
    }

    // ---------------------------------------------------------------- EBML helpers

    /** [id bytes, size (null = unknown), data offset] */
    private static function head(string $b, int $p): array
    {
        if ($p >= strlen($b)) return [null, null, $p];
        $c = ord($b[$p]); $w = 1;
        while ($w <= 4 && ! ($c & (0x80 >> ($w - 1)))) $w++;
        if ($w > 4) return [null, null, $p];
        $id = substr($b, $p, $w);
        [$size, $unknown, $q] = self::vint($b, $p + $w);
        return [$id, $unknown ? null : $size, $q];
    }

    /** [value, all-ones (unknown), next offset] */
    private static function vint(string $b, int $p): array
    {
        $c = ord($b[$p]); $w = 1;
        while ($w <= 8 && ! ($c & (0x80 >> ($w - 1)))) $w++;
        $v = $c & (0xFF >> $w); $ones = $v === (0xFF >> $w);
        for ($i = 1; $i < $w; $i++) { $o = ord($b[$p + $i]); $v = ($v << 8) | $o; $ones = $ones && $o === 0xFF; }
        return [$v, $ones, $p + $w];
    }

    private static function unknownEnd(string $b, int $p, int $limit): int
    {
        while ($p < $limit) {
            if (in_array(substr($b, $p, 4), self::LEVEL1, true)) return $p;
            [$id, $len, $q] = self::head($b, $p);
            if ($id === null || $len === null) return $limit;
            $p = $q + $len;
        }
        return $limit;
    }

    private static function trackKey(string $tracks): string
    {
        $key = [];
        $walk = function (string $b) use (&$walk, &$key) {
            $p = 0;
            while ($p < strlen($b)) {
                [$id, $len, $q] = self::head($b, $p);
                if ($id === null || $len === null) return;
                $d = substr($b, $q, $len);
                if ($id === "\xAE" || $id === "\xE0") $walk($d);              // TrackEntry, Video
                elseif ($id === "\x86") $key[] = $d;                          // CodecID
                elseif ($id === "\xB0" || $id === "\xBA") $key[] = self::uint($d); // PixelWidth / Height
                $p = $q + $len;
            }
        };
        $walk($tracks);
        return implode('|', $key);
    }

    private static function uint(string $d): int
    {
        $v = 0;
        foreach (str_split($d) as $c) $v = ($v << 8) | ord($c);
        return $v;
    }

    private static function uintBytes(int $v): string
    {
        $s = '';
        do { $s = chr($v & 0xFF) . $s; $v >>= 8; } while ($v > 0);
        return $s;
    }

    private static function el(string $id, string $data): string
    {
        $n = strlen($data);
        for ($w = 1; $w < 8 && $n >= (1 << (7 * $w)) - 1; $w++);
        $size = '';
        for ($i = 0; $i < $w; $i++) $size = chr(($n >> (8 * $i)) & 0xFF) . $size;
        $size[0] = chr(ord($size[0]) | (0x80 >> ($w - 1)));
        return $id . $size . $data;
    }
}
