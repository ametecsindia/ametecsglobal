<?php

namespace App\Services;

use App\Http\Controllers\Api\ScreenRecordingController;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ScreenRecording;
use App\Models\ScreenRecordingEvidence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * 20-Oct-2026 (Ejaz) — Screen Recording:
 *  P2  evidence pack: every minute's fingerprint re-checked, a watermarked MP4, the original minutes
 *      (when customer data may be shown), manifest.json, EVIDENCE.md and VERIFY.txt in one ZIP whose own
 *      SHA-256 is printed on the audit-ready PDF the console makes from the same data.
 *  P3  masking: card / Aadhaar / PAN / account numbers are found on each uploaded minute (OCR on this
 *      server) and a blurred copy is made. Viewers without "recording.unmask" only ever get that copy;
 *      the original stays sealed (fingerprinted, never altered).
 */
class RecordingEvidence
{
    public const MAX_MINUTES = 480; // ponytail: one pack ≤ 8 hours; split longer periods into several packs

    // ------------------------------------------------------------------ fingerprints

    /** Minutes recorded before 20-Oct-2026 get their fingerprint once (sealed_at shows when). */
    public static function fingerprintBacklog(int $limit = 200): int
    {
        $n = 0;
        foreach (ScreenRecording::withoutGlobalScopes()->whereNull('sha256')->orderByDesc('id')->limit($limit)->get() as $r) {
            try {
                $disk = Storage::disk($r->storage_driver);
                if (! $disk->exists($r->storage_key)) continue;
                $r->fingerprint(hash('sha256', $disk->get($r->storage_key)));
                $n++;
            } catch (\Throwable $e) { /* next run */ }
        }

        return $n;
    }

    // ------------------------------------------------------------------ masking

    /** Check newest-first the minutes of companies that hide customer data, until $seconds are used. */
    public static function processMasks(int $seconds = 50): array
    {
        $done = ['clean' => 0, 'masked' => 0, 'failed' => 0];
        $companies = Company::where('mask_sensitive', true)->get()->keyBy('id');
        if ($companies->isEmpty()) return $done;
        // 20-Oct-2026: the employee's PC normally does this (agent ≥ 20-Oct build sends mask_state). Only
        // minutes it did not check (older agent, or recorded before the setting was on) are left. A server
        // that happens to have ffmpeg + Tesseract checks them here; otherwise they stay hidden ('failed' =
        // not checked) and only people allowed to show hidden data can watch them. Nothing to install.
        if (! RecordingTools::ffmpeg() || ! RecordingTools::tesseract()) {
            $done['failed'] = ScreenRecording::withoutGlobalScopes()->whereIn('company_id', $companies->keys())->whereNull('mask_status')
                ->where('created_at', '<', now()->subMinutes(5))->update(['mask_status' => 'failed', 'masked_at' => now()]);
            return $done;
        }
        $until = microtime(true) + $seconds;
        while (microtime(true) < $until) {
            $r = ScreenRecording::withoutGlobalScopes()->whereIn('company_id', $companies->keys())->whereNull('mask_status')
                ->orderByDesc('started_at')->first();
            if (! $r) break;
            $st = self::maskOne($r, $companies[$r->company_id]->maskTypes() ?: SensitiveData::TYPES);
            $done[$st]++;
        }

        return $done;
    }

    /** Scan one minute; make the blurred copy when something was found. Returns the new mask_status. */
    public static function maskOne(ScreenRecording $r, array $types): string
    {
        $tmp = self::tmp('m');
        try {
            $disk = Storage::disk($r->storage_driver);
            if (! $disk->exists($r->storage_key)) throw new \RuntimeException('file missing');
            file_put_contents("$tmp/in.webm", $disk->get($r->storage_key));
            $scan = RecordingTools::scan("$tmp/in.webm", $types);
            if (! $scan['boxes']) {
                $r->forceFill(['mask_status' => 'clean', 'mask_boxes' => ['w' => $scan['w'], 'h' => $scan['h'], 'boxes' => []], 'masked_at' => now()])->save();
                return 'clean';
            }
            RecordingTools::render("$tmp/in.webm", "$tmp/out.webm", $scan, null, 'webm');
            $key = preg_replace('/\.webm$/', '', $r->storage_key) . '.masked.webm';
            $disk->put($key, file_get_contents("$tmp/out.webm"));
            $r->forceFill(['mask_status' => 'masked', 'mask_boxes' => $scan, 'masked_key' => $key, 'masked_at' => now()])->save();

            return 'masked';
        } catch (\Throwable $e) {
            report($e);
            $r->forceFill(['mask_status' => 'failed', 'masked_at' => now()])->save();

            return 'failed';
        } finally {
            self::rm($tmp);
        }
    }

    // ------------------------------------------------------------------ evidence pack

    /** Build a queued pack. Public static so it runs after the response, from the scheduler, or in a test. */
    public static function build(int $id): void
    {
        $ev = ScreenRecordingEvidence::withoutGlobalScopes()->find($id);
        if (! $ev || $ev->status !== 'queued') return;
        $ev->update(['status' => 'running']);
        $tmp = self::tmp('ev');
        try {
            @set_time_limit(0);
            $company = Company::find($ev->company_id);
            $emp = Employee::withoutGlobalScopes()->find($ev->employee_id);
            $user = User::find($ev->created_by_user_id);
            $tz = $company?->timezone ?: config('app.timezone');
            $segs = ScreenRecording::withoutGlobalScopes()->where('company_id', $ev->company_id)->where('employee_id', $ev->employee_id)
                ->where('monitor_index', $ev->monitor_index)->whereBetween('started_at', [$ev->from_at, $ev->to_at])
                ->orderBy('started_at')->limit(self::MAX_MINUTES + 1)->get();
            if ($segs->isEmpty()) throw new \RuntimeException('No recordings in that period.');
            if ($segs->count() > self::MAX_MINUTES) throw new \RuntimeException('More than 8 hours of recording — choose a shorter period.');

            // 1. Every minute must still match the fingerprint taken when it arrived.
            $bad = []; $files = [];
            foreach ($segs as $s) {
                $disk = Storage::disk($s->storage_driver);
                $bytes = $disk->exists($s->storage_key) ? $disk->get($s->storage_key) : null;
                $why = $s->verify($bytes);
                if ($why === 'no fingerprint' && $bytes !== null) { $s->fingerprint(hash('sha256', $bytes)); $why = null; }
                if ($why) { $bad[] = self::t($s->started_at) . ' (' . $why . ')'; continue; }
                file_put_contents($files[$s->id] = "$tmp/o{$s->id}.webm", $bytes);
            }
            if ($bad) throw new \RuntimeException('Fingerprint check failed — the recording may have been altered. Nothing was exported. Minutes: ' . implode(', ', array_slice($bad, 0, 10)));

            // 2. Masked pack: each minute goes out as viewers see it — the blurred copy where customer data was
            //    found, the original where the PC (or this server) found none. A minute nobody could check stops it.
            $show = $files; // the file used for the video, per minute
            if ($ev->masked) {
                foreach ($segs as $s) {
                    if ($s->mask_status === null && RecordingTools::ffmpeg() && RecordingTools::tesseract()) {
                        self::maskOne($s, $company?->maskTypes() ?: SensitiveData::TYPES);
                    }
                }
                $segs = $segs->map->fresh();
                foreach ($segs as $s) {
                    if ($s->mask_status === 'clean') continue;
                    $disk = Storage::disk($s->storage_driver);
                    if ($s->mask_status !== 'masked' || ! $s->masked_key || ! $disk->exists($s->masked_key)) {
                        throw new \RuntimeException('The minute at ' . self::t($s->started_at) . ' was not checked for customer data, so a pack with customer data hidden cannot include it. Someone allowed to show hidden data can create the pack with the original.');
                    }
                    file_put_contents($show[$s->id] = "$tmp/m{$s->id}.webm", $disk->get($s->masked_key));
                }
            }

            // 3. The video. Every recorded frame already carries the PC's watermark (company · employee · PC ·
            //    date and time — agent build of 20-Oct-2026 or later). The minutes are joined in plain PHP
            //    (WebmJoiner, no tools); a server that happens to have ffmpeg also adds an MP4 with the
            //    evidence reference and "exported by" burnt in.
            $code = $ev->code;
            $videos = [];
            $note = null;
            $groups = [];
            foreach ($segs as $s) {
                $meta = WebmJoiner::parse((string) file_get_contents($show[$s->id]));
                $k = count($groups) - 1;
                if ($k < 0 || ! $meta || ! $groups[$k]['meta'] || ! WebmJoiner::compatible($groups[$k]['meta'], $meta)) {
                    $groups[] = ['meta' => $meta ? ['key' => $meta['key'], 'scale' => $meta['scale']] : null, 'segs' => []];
                }
                $groups[count($groups) - 1]['segs'][] = $s;
            }
            foreach ($groups as $i => $g) {
                $name = $code . (count($groups) > 1 ? '-part' . ($i + 1) : '') . '.webm';
                if (count($g['segs']) === 1 || ! $g['meta']) { copy($show[$g['segs'][0]->id], "$tmp/$name"); }
                else {
                    WebmJoiner::write(array_map(fn ($s) => fn () => WebmJoiner::parse((string) file_get_contents($show[$s->id])), $g['segs']),
                        array_map(fn ($s) => (int) $s->duration_seconds * 1000, $g['segs']), "$tmp/$name");
                }
                $videos[$name] = "$tmp/$name";
            }
            if (RecordingTools::ffmpeg()) {
                $groups = [];
                foreach ($segs as $s) {
                    $wm = ($company?->name ?: 'SmartEPT') . ' · ' . self::name($emp) . ($emp?->employee_code ? ' (' . $emp->employee_code . ')' : '') . ' · Screen ' . ($s->monitor_index + 1) . "\n"
                        . 'Recorded ' . $s->started_at->copy()->format('j-M-Y g:i:s A') . ' – ' . ($s->ended_at ?? $s->started_at)->format('g:i:s A')
                        . ' · Evidence ' . $code . ' · Exported by ' . ($user?->name ?: '—') . ' on ' . now($tz)->format('j-M-Y g:i A')
                        . ($ev->masked ? ' · Customer data hidden' : '');
                    $out = "$tmp/w{$s->id}.mp4";
                    RecordingTools::render($show[$s->id], $out, [], $wm, 'mp4');
                    $size = (string) (WebmJoiner::parse((string) file_get_contents($show[$s->id]))['key'] ?? '');
                    $k = count($groups) - 1;
                    if ($k < 0 || $groups[$k]['size'] !== $size) $groups[] = ['size' => $size, 'parts' => []];
                    $groups[count($groups) - 1]['parts'][] = $out;
                }
                foreach ($groups as $i => $g) {
                    $name = $code . (count($groups) > 1 ? '-part' . ($i + 1) : '') . '.mp4';
                    RecordingTools::concatMp4($g['parts'], "$tmp/$name");
                    $videos[$name] = "$tmp/$name";
                }
            }

            // 4. Manifest, report, verification note, ZIP.
            $minutes = $segs->map(fn ($s) => [
                'id' => $s->id, 'start' => $s->started_at->format('Y-m-d H:i:s'), 'end' => ($s->ended_at ?? $s->started_at)->format('Y-m-d H:i:s'),
                'seconds' => (int) $s->duration_seconds, 'trigger' => $s->trigger, 'file' => $ev->masked ? null : 'originals/' . self::file($s),
                'sha256' => $s->sha256, 'fingerprinted' => $s->sealed_at?->format('Y-m-d H:i:s'),
                'fingerprinted_at_upload' => $s->sealed_at && $s->created_at && $s->sealed_at->diffInMinutes($s->created_at, true) <= 10,
                'customer_data_found' => $ev->masked ? $s->mask_status === 'masked' : null,
            ])->values()->all();
            $videoHashes = array_map(fn ($p) => hash_file('sha256', $p), $videos);
            $activity = array_values(array_filter(ScreenRecordingController::markers((int) $ev->employee_id, $ev->from_at, $ev->to_at),
                fn ($m) => in_array($m['type'], ['app', 'site', 'violation'], true)));
            $manifest = [
                'evidence' => $code, 'organisation' => $company?->name, 'employee' => self::name($emp), 'employee_code' => $emp?->employee_code,
                'screen' => $ev->monitor_index + 1, 'from' => $ev->from_at->format('Y-m-d H:i:s'), 'to' => $ev->to_at->format('Y-m-d H:i:s'),
                'time_zone' => $tz, 'customer_data_hidden' => (bool) $ev->masked, 'reason' => $ev->reason,
                'created_by' => $user?->name, 'created_at' => now($tz)->format('Y-m-d H:i:s'),
                'minutes' => $minutes, 'videos' => $videoHashes, 'note' => $note,
                'activity' => array_slice($activity, 0, 2000),
                'custody' => self::custody($ev),
                'server' => ['app_url' => config('app.url'), 'key_fingerprint' => substr(hash('sha256', (string) config('app.key')), 0, 16)],
            ];
            file_put_contents("$tmp/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            file_put_contents("$tmp/EVIDENCE.md", self::markdown($manifest));
            file_put_contents("$tmp/VERIFY.txt", self::verifyText($manifest));

            $rel = 'evidence/' . $ev->company_id . '/' . $code . '.zip';
            @mkdir(dirname(storage_path('app/' . $rel)), 0775, true);
            $zipPath = storage_path('app/' . $rel);
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) throw new \RuntimeException('Could not create the evidence file.');
            foreach ($videos as $name => $p) { $zip->addFile($p, $name); $zip->setCompressionName($name, \ZipArchive::CM_STORE); }
            if (! $ev->masked) {
                foreach ($segs as $s) { $n = 'originals/' . self::file($s); $zip->addFile($files[$s->id], $n); $zip->setCompressionName($n, \ZipArchive::CM_STORE); }
            }
            foreach (['manifest.json', 'EVIDENCE.md', 'VERIFY.txt'] as $n) $zip->addFile("$tmp/$n", $n);
            $zip->close();

            $ev->update(['status' => 'done', 'minutes' => $segs->count(), 'zip_path' => $rel, 'zip_sha256' => hash_file('sha256', $zipPath),
                'zip_bytes' => filesize($zipPath), 'manifest' => array_diff_key($manifest, ['activity' => 1]), 'finished_at' => now()]);
            AuditLog::create(['company_id' => $ev->company_id, 'user_id' => $ev->created_by_user_id, 'action' => 'recording.evidence_built',
                'subject_type' => Employee::class, 'subject_id' => $ev->employee_id, 'changes' => ['evidence' => $code, 'minutes' => $segs->count(), 'masked' => (bool) $ev->masked]]);
        } catch (\Throwable $e) {
            report($e);
            $ev->update(['status' => 'failed', 'error' => mb_substr($e->getMessage() ?: 'Failed', 0, 495), 'finished_at' => now()]);
        } finally {
            self::rm($tmp);
        }
    }

    /** Chain of custody: every view, download, unmask and evidence action on this employee's recordings in the pack's days. */
    public static function custody(ScreenRecordingEvidence $ev): array
    {
        $days = [];
        for ($d = $ev->from_at->copy()->subDay()->startOfDay(); $d->lte($ev->to_at); $d->addDay()) $days[] = $d->toDateString();
        $users = [];

        return AuditLog::where('company_id', $ev->company_id)->where('subject_type', Employee::class)->where('subject_id', $ev->employee_id)
            ->whereIn('action', ['recording.view_day', 'recording.download', 'recording.unmask', 'recording.evidence_created', 'recording.evidence_built', 'recording.evidence_download'])
            // 20-Oct-2026 (Ejaz: "logs from start till end for all dates"): only what could have touched THIS
            // footage — done after it was recorded, and a download only when its From–To overlaps the period.
            ->where('created_at', '>=', $ev->from_at)->orderBy('id')->limit(2000)->get()
            ->filter(function ($a) use ($ev, $days) {
                $c = $a->changes ?? [];
                if (str_starts_with($a->action, 'recording.evidence')) return ($c['evidence'] ?? null) === $ev->code;
                if (! in_array($c['date'] ?? '', $days, true)) return false;
                if ($a->action !== 'recording.download' || empty($c['from']) || empty($c['to'])) return true;
                // the download's range on its work day (an overnight time may belong to the next date)
                foreach ([0, 1] as $plus) {
                    $f = Carbon::parse($c['date'] . ' ' . $c['from'])->addDays($plus);
                    $t = Carbon::parse($c['date'] . ' ' . $c['to'])->addDays($plus)->addSeconds(59);
                    if ($t->lt($f)) $t->addDay();
                    if ($f->lte($ev->to_at) && $t->gte($ev->from_at)) return true;
                }

                return false;
            })
            ->map(function ($a) use (&$users) {
                $users[$a->user_id] ??= User::find($a->user_id)?->name;
                $c = $a->changes ?? [];
                $what = ['recording.view_day' => 'Watched the recording', 'recording.download' => 'Downloaded video', 'recording.unmask' => 'Showed hidden customer data',
                    'recording.evidence_created' => 'Requested this evidence pack', 'recording.evidence_built' => 'Evidence pack built', 'recording.evidence_download' => 'Downloaded this evidence pack'][$a->action];
                $detail = trim(implode(' · ', array_filter([
                    isset($c['date']) ? 'Day: ' . $c['date'] : null, isset($c['from']) ? 'From: ' . self::t12($c['from']) . ' To: ' . self::t12($c['to'] ?? '') : null,
                    isset($c['reason']) ? 'Reason: ' . $c['reason'] : null,
                ])));

                return ['at' => Carbon::parse($a->created_at)->format('Y-m-d H:i:s'), 'who' => $users[$a->user_id] ?: '—', 'action' => $what, 'detail' => $detail, 'ip' => $a->ip];
            })->values()->all();
    }

    /** "18:31" → "6:31 PM" (times without leading zero, app-wide). */
    private static function t12(string $hm): string
    {
        return preg_match('/^\d{1,2}:\d{2}$/', $hm) ? Carbon::createFromFormat('H:i', $hm)->format('g:i A') : $hm;
    }

    // ------------------------------------------------------------------ texts

    private static function markdown(array $m): string
    {
        $h = fn ($s) => str_replace('|', '\|', (string) $s);
        $o = "# Screen Recording Evidence Pack — {$m['evidence']}\n\nCONFIDENTIAL — {$m['organisation']}. Generated by SmartEPT.\n\n## 1. Details\n\n| Item | Value |\n|---|---|\n";
        foreach ([['Evidence reference', $m['evidence']], ['Organisation', $m['organisation']], ['Employee', $m['employee'] . ($m['employee_code'] ? ' (' . $m['employee_code'] . ')' : '')],
            ['Screen', $m['screen']], ['Period', $m['from'] . ' to ' . $m['to'] . ' (' . $m['time_zone'] . ')'], ['Recorded minutes', count($m['minutes'])],
            ['Customer data hidden', $m['customer_data_hidden'] ? 'Yes — personal data (cards, accounts, ID numbers, phone numbers, emails as set in Organisation → Privacy) is blurred' : 'No — original recording'],
            ['Reason', $m['reason'] ?: '—'], ['Created by', $m['created_by']], ['Created on', $m['created_at']]] as [$k, $v]) $o .= "| {$h($k)} | {$h($v)} |\n";
        $o .= "\n## 2. Files and fingerprints\n\n" . ($m['note'] ? "> {$m['note']}\n\n" : '') . "| File | SHA-256 |\n|---|---|\n";
        foreach ($m['videos'] as $n => $sha) $o .= "| {$n} (watermarked video) | `{$sha}` |\n";
        $o .= "\n### Recorded minutes\n\n| # | Start | End | Mode | SHA-256 (taken when the minute arrived) | Fingerprinted |\n|---|---|---|---|---|---|\n";
        foreach ($m['minutes'] as $i => $x) $o .= '| ' . ($i + 1) . " | {$x['start']} | {$x['end']} | {$x['trigger']} | `{$x['sha256']}` | " . ($x['fingerprinted_at_upload'] ? 'on arrival' : 'later: ' . $x['fingerprinted']) . " |\n";
        $o .= "\n## 3. Activity during the period\n\n| Time | Type | What | Detail |\n|---|---|---|---|\n";
        foreach (array_slice($m['activity'], 0, 500) as $a) $o .= '| ' . Carbon::parse($a['at'])->format('Y-m-d g:i:s A') . " | {$h($a['type'])} | {$h($a['label'])} | {$h($a['detail'] ?? '')} |\n";
        if (! $m['activity']) $o .= "| — | — | No app, site or violation records | — |\n";
        $o .= "\n## 4. Chain of custody\n\n| When | Who | Action | Detail | IP |\n|---|---|---|---|---|\n";
        foreach ($m['custody'] as $c) $o .= "| {$c['at']} | {$h($c['who'])} | {$h($c['action'])} | {$h($c['detail'])} | {$h($c['ip'])} |\n";
        $o .= "\n## 5. How to verify\n\n" . self::verifyText($m) . "\n## 6. Declaration\n\nThis pack was produced automatically by SmartEPT from screen recordings captured by the SmartEPT Agent on the employee's PC. Each minute was fingerprinted (SHA-256 and a server seal) when it reached the server and every fingerprint was re-checked before this pack was made. Nothing has been added, edited or removed"
            . ($m['customer_data_hidden'] ? ', except that customer identifiers are blurred in the video as required under the Digital Personal Data Protection Act, 2023; the original stays sealed on the server' : '') . ".\n";

        return $o;
    }

    private static function verifyText(array $m): string
    {
        return "1. Check this ZIP file's SHA-256 against the one printed on the Evidence PDF (reference {$m['evidence']}).\n"
            . "   Windows: certutil -hashfile <file> SHA256     Linux / Mac: sha256sum <file>\n"
            . ($m['customer_data_hidden']
                ? "2. The original minutes stay sealed on the SmartEPT server. Ask the organisation to re-check them: Screen Recording → Evidence packs → Verify. Each must match the SHA-256 listed in manifest.json.\n"
                : "2. Every file in originals/ must give exactly the SHA-256 listed for it in manifest.json / EVIDENCE.md. Those fingerprints were taken when each minute reached the server.\n")
            . "3. The video file(s) are made from those minutes (joined; an MP4, when present, is re-encoded), so their SHA-256 is their own, listed in manifest.json.\n";
    }

    // ------------------------------------------------------------------ helpers
    private static function t(?Carbon $c): string { return $c ? $c->format('j-M-Y g:i A') : '—'; }
    private static function name(?Employee $e): string { return $e ? trim($e->first_name . ' ' . $e->last_name) : '—'; }
    private static function file(ScreenRecording $s): string { return sprintf('%s_screen%d.webm', $s->started_at->format('Y-m-d_H-i-s'), $s->monitor_index + 1); }
    private static function tmp(string $p): string { $d = storage_path('app/tmp/' . $p . '-' . bin2hex(random_bytes(6))); @mkdir($d, 0775, true); return $d; }
    private static function rm(string $d): void { foreach (glob($d . '/*') ?: [] as $f) @unlink($f); @rmdir($d); }
}
