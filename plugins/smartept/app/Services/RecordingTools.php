<?php

namespace App\Services;

/**
 * 20-Oct-2026 (Ejaz): the two free command-line tools Screen Recording's evidence packs and
 * sensitive-data masking use on the SERVER — nothing leaves the server:
 *   ffmpeg     reads frames, blurs areas, burns the watermark, makes the MP4
 *   tesseract  reads the text on a frame (OCR)
 * Neither is required for recording or playback. Without ffmpeg an evidence pack carries the
 * original minutes (fingerprint-checked) but no watermarked MP4; without both, masking cannot run.
 *
 * Found, never configured: <app>/tools/ffmpeg|tesseract/… first (on-prem Windows: unzip there),
 * then the usual install folders, then the PATH. Plain proc_open with an argument array — no shell,
 * so nothing in a file name or watermark can be run as a command.
 */
class RecordingTools
{
    private static array $found = [];

    public static function ffmpeg(): ?string
    {
        return self::find('ffmpeg', '-version', [
            self::base('tools/ffmpeg/bin/ffmpeg.exe'), self::base('tools/ffmpeg/ffmpeg.exe'), self::base('tools/ffmpeg/ffmpeg'),
            'C:\\ffmpeg\\bin\\ffmpeg.exe', 'C:\\Program Files\\ffmpeg\\bin\\ffmpeg.exe', 'C:\\laragon\\bin\\ffmpeg\\ffmpeg.exe',
            '/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', 'ffmpeg',
        ]);
    }

    public static function tesseract(): ?string
    {
        return self::find('tesseract', '--version', [
            self::base('tools/tesseract/tesseract.exe'), self::base('tools/tesseract/tesseract'),
            'C:\\Program Files\\Tesseract-OCR\\tesseract.exe', 'C:\\Program Files (x86)\\Tesseract-OCR\\tesseract.exe',
            '/usr/bin/tesseract', '/usr/local/bin/tesseract', 'tesseract',
        ]);
    }

    /** For the settings screen: what this server can do. */
    public static function status(): array
    {
        return ['ffmpeg' => self::ffmpeg() !== null, 'ocr' => self::tesseract() !== null];
    }

    /**
     * Read the text on a recorded minute and return the areas holding sensitive numbers.
     * One frame every SAMPLE seconds; a frame identical to the last one read reuses its result.
     * A box found on sample i covers the time from sample i-1 to sample i+1, so a number that is
     * on screen at any sample is hidden for the whole stretch around it.
     * ponytail: a number shown for less than SAMPLE seconds between two samples can be missed —
     * lower SAMPLE to tighten it (CPU grows in proportion: ~0.25 s per read frame at 720p).
     *
     * @return array ['w' => px, 'h' => px, 'boxes' => [['s','e','x','y','w','h','type']...], 'read' => frames OCR'd]
     */
    public const SAMPLE = 2;

    public static function scan(string $video, array $types): array
    {
        $ff = self::ffmpeg(); $ocr = self::tesseract();
        if (! $ff || ! $ocr) throw new \RuntimeException('ffmpeg / tesseract not installed on this server');
        $tmp = self::tmpDir();
        try {
            [$code, , $err] = self::run([$ff, '-hide_banner', '-nostats', '-i', $video, '-filter_complex',
                '[0:v]fps=' . (1 / self::SAMPLE) . ',split=2[a][b];[b]scale=320:180,format=gray[g]',
                '-map', '[a]', $tmp . '/f_%04d.png', '-map', '[g]', '-f', 'rawvideo', $tmp . '/g.raw'], null, 120);
            if ($code !== 0) throw new \RuntimeException('ffmpeg could not read the recording: ' . substr(trim($err), -200));
            [$w, $h] = self::size($err);
            $frames = glob($tmp . '/f_*.png') ?: [];
            sort($frames);
            $gray = (string) @file_get_contents($tmp . '/g.raw');
            $n = count($frames);
            // Stretches of samples showing the same screen. The browser encoder sharpens a still picture
            // over its first seconds, so each stretch is read at its FIRST and its LAST (sharpest) frame,
            // and whatever either shows is hidden for the whole stretch — from two samples before it (the blurry
            // first seconds of a new screen) to one sample after.
            $stretches = [];
            for ($i = 0; $i < $n; $i++) {
                $same = $i > 0 && self::same(substr($gray, ($i - 1) * 57600, 57600), substr($gray, $i * 57600, 57600));
                if ($same) $stretches[count($stretches) - 1][1] = $i; else $stretches[] = [$i, $i];
            }
            $hits = []; $read = 0;
            foreach ($stretches as [$a, $b]) {
                $boxes = [];
                foreach (array_unique([$a, $b]) as $i) {
                    array_push($boxes, ...SensitiveData::find(self::ocrWords($ocr, $frames[$i]), $types));
                    $read++;
                }
                foreach ($boxes as $box) {
                    $hits[] = ['s' => max(0, ($a - 2) * self::SAMPLE), 'e' => ($b + 1) * self::SAMPLE + 0.5] + $box;
                }
            }

            return ['w' => $w, 'h' => $h, 'boxes' => self::merge($hits), 'read' => $read, 'frames' => $n];
        } finally {
            self::rmDir($tmp);
        }
    }

    /**
     * Re-encode one minute with the given areas blurred and (optionally) a watermark burnt in.
     * $format 'webm' (VP8, for playback in the console) or 'mp4' (H.264, plays on any PC).
     */
    public static function render(string $in, string $out, array $mask, ?string $watermark, string $format = 'webm'): void
    {
        $ff = self::ffmpeg();
        if (! $ff) throw new \RuntimeException('ffmpeg not installed on this server');
        $tmp = self::tmpDir();
        try {
            $f = [];
            $W = (int) ($mask['w'] ?? 0); $H = (int) ($mask['h'] ?? 0);
            foreach (($mask['boxes'] ?? []) as $b) {
                if (! $W || ! $H) break;
                // delogo needs the box strictly inside the picture
                $x = max(1, min($W - 3, (int) $b['x'])); $y = max(1, min($H - 3, (int) $b['y']));
                $bw = max(2, min($W - $x - 2, (int) $b['w'])); $bh = max(2, min($H - $y - 2, (int) $b['h']));
                $f[] = sprintf("delogo=x=%d:y=%d:w=%d:h=%d:enable='between(t,%.2f,%.2f)'", $x, $y, $bw, $bh, $b['s'], $b['e']);
            }
            if ($watermark !== null && $watermark !== '') {
                file_put_contents($tmp . '/wm.txt', $watermark);
                $font = self::font();
                if ($font) copy($font, $tmp . '/font.ttf');
                // relative paths (cwd = $tmp): no drive-letter colons to escape on Windows
                $f[] = 'drawtext=textfile=wm.txt' . ($font ? ':fontfile=font.ttf' : '') . ':expansion=none'
                    . ':fontsize=h/40:fontcolor=white@0.85:box=1:boxcolor=black@0.55:boxborderw=6:x=10:y=h-th-12';
            }
            $args = [$ff, '-hide_banner', '-nostats', '-y', '-i', $in];
            if ($f) array_push($args, '-vf', implode(',', $f));
            if ($format === 'mp4') {
                array_push($args, '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '26', '-pix_fmt', 'yuv420p', '-an', '-movflags', '+faststart');
            } else {
                array_push($args, '-c:v', 'libvpx', '-deadline', 'realtime', '-cpu-used', '8', '-b:v', '1M', '-crf', '30', '-an');
            }
            $args[] = $out;
            [$code, , $err] = self::run($args, $tmp, 300);
            if ($code !== 0 || ! is_file($out) || ! filesize($out)) throw new \RuntimeException('ffmpeg could not write the video: ' . substr(trim($err), -300));
        } finally {
            self::rmDir($tmp);
        }
    }

    /** Join MP4 minutes (same size) into one file without re-encoding. */
    public static function concatMp4(array $parts, string $out): void
    {
        $tmp = self::tmpDir();
        try {
            $list = '';
            foreach ($parts as $i => $p) { copy($p, $tmp . "/p{$i}.mp4"); $list .= "file 'p{$i}.mp4'\n"; }
            file_put_contents($tmp . '/list.txt', $list);
            [$code, , $err] = self::run([self::ffmpeg(), '-hide_banner', '-nostats', '-y', '-f', 'concat', '-safe', '0', '-i', 'list.txt', '-c', 'copy', '-movflags', '+faststart', 'out.mp4'], $tmp, 300);
            if ($code !== 0) throw new \RuntimeException('ffmpeg could not join the minutes: ' . substr(trim($err), -300));
            rename($tmp . '/out.mp4', $out);
        } finally {
            self::rmDir($tmp);
        }
    }

    // ------------------------------------------------------------------ internals

    /** OCR one image → words with boxes (tesseract TSV, sparse-text mode — suits screens). */
    private static function ocrWords(string $ocr, string $png): array
    {
        [$code, $out] = self::run([$ocr, $png, 'stdout', '--psm', '11', 'tsv'], null, 60);
        if ($code !== 0) throw new \RuntimeException('tesseract failed');
        $words = [];
        foreach (preg_split('/\r?\n/', $out) as $k => $line) {
            $c = explode("\t", $line);
            if ($k === 0 || count($c) < 12 || trim($c[11]) === '' || (float) $c[10] < 0) continue;
            $words[] = ['text' => $c[11], 'x' => (int) $c[6], 'y' => (int) $c[7], 'w' => (int) $c[8], 'h' => (int) $c[9],
                'line' => $c[2] . '.' . $c[3] . '.' . $c[4]];
        }

        return $words;
    }

    /** Two 320x180 gray thumbnails show the same screen (every 10x10 block within 3 levels). */
    private static function same(string $a, string $b): bool
    {
        if (strlen($a) !== 57600 || strlen($b) !== 57600) return false;
        for ($by = 0; $by < 180; $by += 10) {
            for ($bx = 0; $bx < 320; $bx += 10) {
                $d = 0;
                for ($y = $by; $y < $by + 10; $y++) {
                    $o = $y * 320 + $bx;
                    for ($x = 0; $x < 10; $x++) $d += abs(ord($a[$o + $x]) - ord($b[$o + $x]));
                }
                if ($d > 150) return false; // mean > 1.5 levels in this block — a changed number counts as a change
            }
        }

        return true;
    }

    /** Join boxes of the same place whose time windows touch, so the filter list stays short. */
    private static function merge(array $hits): array
    {
        usort($hits, fn ($a, $b) => $a['s'] <=> $b['s']);
        $out = [];
        foreach ($hits as $h) {
            foreach ($out as &$o) {
                if ($h['s'] <= $o['e'] && abs($h['x'] - $o['x']) <= 6 && abs($h['y'] - $o['y']) <= 6 && abs($h['w'] - $o['w']) <= 12 && abs($h['h'] - $o['h']) <= 6) {
                    $o['e'] = max($o['e'], $h['e']);
                    $o['x'] = min($o['x'], $h['x']); $o['y'] = min($o['y'], $h['y']);
                    $o['w'] = max($o['w'], $h['w']) + 6; $o['h'] = max($o['h'], $h['h']);
                    continue 2;
                }
            }
            unset($o);
            $out[] = $h;
        }

        return array_values($out);
    }

    private static function size(string $ffmpegLog): array
    {
        return preg_match('/Video: .*?, (\d{2,5})x(\d{2,5})/', $ffmpegLog, $m) ? [(int) $m[1], (int) $m[2]] : [0, 0];
    }

    private static function font(): ?string
    {
        foreach (['C:\\Windows\\Fonts\\segoeui.ttf', 'C:\\Windows\\Fonts\\arial.ttf', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf'] as $f) {
            if (@is_file($f)) return $f;
        }

        return null;
    }

    public static function run(array $cmd, ?string $cwd, int $timeout): array
    {
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
        if (! is_resource($p)) return [127, '', 'could not start ' . $cmd[0]];
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out = ''; $err = ''; $until = microtime(true) + $timeout;
        while (true) {
            $r = [$pipes[1], $pipes[2]]; $w = null; $e = null;
            if (@stream_select($r, $w, $e, 1) !== false) {
                foreach ($r as $s) { $chunk = fread($s, 65536); if ($s === $pipes[1]) $out .= $chunk; else $err .= $chunk; }
            }
            $st = proc_get_status($p);
            if (! $st['running']) { $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]); break; }
            if (microtime(true) > $until) { proc_terminate($p); $err .= "\n(timed out)"; break; }
        }
        fclose($pipes[1]); fclose($pipes[2]);
        $code = proc_close($p);
        if (isset($st['exitcode']) && $st['exitcode'] !== -1 && ! $st['running']) $code = $st['exitcode'];

        return [$code, $out, $err];
    }

    private static function find(string $name, string $flag, array $candidates): ?string
    {
        if (array_key_exists($name, self::$found)) return self::$found[$name];
        foreach ($candidates as $c) {
            if (str_contains($c, '/') || str_contains($c, '\\')) { if (! @is_file($c)) continue; }
            try { [$code] = self::run([$c, $flag], null, 10); } catch (\Throwable $e) { $code = 1; }
            if ($code === 0) return self::$found[$name] = $c;
        }

        return self::$found[$name] = null;
    }

    private static function base(string $rel): string
    {
        return function_exists('base_path') ? base_path($rel) : $rel;
    }

    private static function tmpDir(): string
    {
        $root = function_exists('storage_path') ? storage_path('app/tmp') : sys_get_temp_dir();
        $d = $root . '/rt-' . bin2hex(random_bytes(6));
        @mkdir($d, 0775, true);

        return $d;
    }

    private static function rmDir(string $d): void
    {
        foreach (glob($d . '/*') ?: [] as $f) @unlink($f);
        @rmdir($d);
    }
}
