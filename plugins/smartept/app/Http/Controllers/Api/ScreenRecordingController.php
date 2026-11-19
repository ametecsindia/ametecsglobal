<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAppUsageLog;
use App\Models\EmployeeBreakLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeIdleLog;
use App\Models\EmployeeLoginSession;
use App\Models\EmployeeWebsiteUsageLog;
use App\Models\InstallationLicense;
use App\Models\ScreenRecording;
use App\Models\ScreenRecordingRequest;
use App\Models\ScreenRecordingSeat;
use App\Models\ScreenRecordingSetting;
use App\Models\AuditLog;
use App\Models\ScreenRecordingEvidence;
use App\Services\RecordingEvidence;
use App\Services\RecordingTools;
use App\Services\StorageService;
use App\Services\WebmJoiner;
use App\Support\ResolvesAgentContext;
use App\Support\ScopesVisibleEmployees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 15-Oct-2026 (Ejaz): Screen Recording — Commander only (same plan gate as LiveView:
 * feature:live_view), licensed per employee like LiveView (bundle features.recording_max_users,
 * set in Central next to the LiveView box).
 *
 * Permissions reuse the LiveView slugs: liveview.view = watch recordings,
 * liveview.start = manage settings / seats / Record now. No new RBAC slugs.
 *
 * The agent pulls its own config (GET agent/recording/config) every minute and uploads
 * finished segments (POST agent/recording/upload); nothing here touches the heartbeat.
 */
class ScreenRecordingController extends Controller
{
    use ResolvesAgentContext;
    use ScopesVisibleEmployees;

    /** Length of one recorded file. Short, so the dashcam can keep "the last 2 minutes" exactly. */
    public const SEGMENT_SECONDS = 60;

    private const BITRATE = ['economy' => 60, 'standard' => 150, 'high' => 400]; // kbps
    private const FPS = ['economy' => 1, 'standard' => 2, 'high' => 5];
    private const HEIGHT = ['economy' => 720, 'standard' => 900, 'high' => 1080];

    // ------------------------------------------------------------------ admin

    /** GET /api/screen-recording/access — drives the menu item (plan + permission). */
    public function access(Request $request): JsonResponse
    {
        $u = $request->user();
        $plan = $this->planAllows($u->company);
        $canView = $plan && $u->hasPermission('liveview.view');

        // The player's employee picker: everyone who has been recorded or may be, within the viewer's scope.
        $employees = [];
        if ($canView) {
            $ids = ScreenRecordingSeat::pluck('employee_id')
                ->merge(ScreenRecording::where('started_at', '>=', now()->subDays(400))->distinct()->pluck('employee_id'))
                ->unique()->values()->all();
            $visible = $this->visibleEmployeeIds($u);
            if ($visible !== null) {
                $ids = array_values(array_intersect($ids, $visible));
            }
            $employees = Employee::whereIn('id', $ids)->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'employee_code'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => trim($e->first_name . ' ' . $e->last_name), 'code' => $e->employee_code])->values();
        }

        return response()->json(['data' => [
            'plan'       => $plan,
            'can_view'   => $canView,
            'can_manage' => $plan && $u->hasPermission('liveview.start'),
            'employees'  => $employees,
            // 20-Oct-2026: evidence packs + hiding customer data (DPDP Act)
            'can_evidence' => $plan && $u->hasPermission('recording.evidence'),
            'can_unmask'   => $plan && $u->hasPermission('recording.unmask'),
            'masking'      => ['on' => (bool) $u->company?->maskTypes(), 'types' => $u->company?->maskTypes() ?? [], 'tools' => RecordingTools::status()],
        ]]);
    }

    /** GET /api/screen-recording/settings — settings, seats and storage in one call. */
    public function settings(Request $request): JsonResponse
    {
        $companyId = (int) $request->user()->company_id;
        $s = ScreenRecordingSetting::forCompany($companyId);

        return response()->json(['data' => [
            'settings' => $this->settingsPayload($s),
            'seats'    => $this->seatsPayload($request),
            'storage'  => [
                'files'  => ScreenRecording::count(),
                'bytes'  => (int) ScreenRecording::sum('size_bytes'),
                'oldest' => optional(ScreenRecording::min('started_at'), fn ($d) => Carbon::parse($d)->toIso8601String()),
            ],
            'last_cleanup_at' => $s->last_cleanup_at?->toIso8601String(),
        ]]);
    }

    /** PUT /api/screen-recording/settings */
    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate([
            'modes'              => ['required', 'array', 'min:1'],
            'modes.*'            => ['in:' . implode(',', ScreenRecordingSetting::MODES)],
            'quality'            => ['required', 'in:' . implode(',', ScreenRecordingSetting::QUALITIES)],
            'watched_apps'       => ['nullable', 'array', 'max:200'],
            'watched_apps.*'     => ['nullable', 'string', 'max:120'],
            'excluded_apps'      => ['nullable', 'array', 'max:200'],
            'excluded_apps.*'    => ['nullable', 'string', 'max:120'],
            'pre_seconds'        => ['required', 'integer', 'min:0', 'max:600'],
            'post_seconds'       => ['required', 'integer', 'min:0', 'max:1800'],
            'pause_on_idle'      => ['required', 'boolean'],
            'pause_on_break'     => ['required', 'boolean'],
            'monitors'           => ['required', 'in:primary,all'],
            'show_indicator'     => ['required', 'boolean'],
            'upload_kbps'        => ['required', 'integer', 'min:0', 'max:100000'],
            'local_cap_mb'       => ['required', 'integer', 'min:256', 'max:51200'],
            'retention_days'     => ['required', 'integer', 'min:1', 'max:3650'],
            'cleanup_frequency'  => ['required', 'in:' . implode(',', ScreenRecordingSetting::FREQUENCIES)],
            'cleanup_time'       => ['required', 'date_format:H:i'],
            'cleanup_every_days' => ['required', 'integer', 'min:1', 'max:365'],
            'cleanup_weekday'    => ['required', 'integer', 'min:0', 'max:6'],
            'cleanup_month_day'  => ['required', 'integer', 'min:1', 'max:31'],
            'download_split_minutes' => ['sometimes', 'integer', 'in:' . implode(',', ScreenRecordingSetting::DOWNLOAD_SPLITS)],
        ]);
        $clean = fn ($list) => array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), $list ?? []), 'strlen')));
        $d['modes'] = array_values(array_unique($d['modes']));
        $d['watched_apps'] = $clean($d['watched_apps'] ?? []);
        $d['excluded_apps'] = $clean($d['excluded_apps'] ?? []);

        $s = ScreenRecordingSetting::forCompany((int) $request->user()->company_id);
        $s->fill($d)->save();
        $this->audit($request, 'recording.settings_saved', ScreenRecordingSetting::class, $s->id, $d);

        return response()->json(['ok' => true, 'data' => $this->settingsPayload($s)]);
    }

    /** POST /api/screen-recording/seat {employee_id, enabled} — capped like LiveView. */
    public function setSeat(Request $request): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'enabled' => ['required', 'boolean']]);
        $employee = Employee::findOrFail($d['employee_id']);
        $has = ScreenRecordingSeat::where('employee_id', $employee->id)->exists();

        if ($d['enabled'] && ! $has) {
            $limit = $this->seatLimit($request->user()->company);
            if (ScreenRecordingSeat::count() >= $limit) {
                return response()->json(['error' => ['code' => 'RECORDING_LIMIT_REACHED',
                    'message' => "Your licence allows Screen Recording for {$limit} " . str('user')->plural($limit) . '. Remove one before adding another, or buy more Screen Recording users.']], 409);
            }
            ScreenRecordingSeat::create(['company_id' => $employee->company_id, 'employee_id' => $employee->id, 'granted_by_user_id' => $request->user()->id]);
        } elseif (! $d['enabled'] && $has) {
            ScreenRecordingSeat::where('employee_id', $employee->id)->delete();
            ScreenRecordingRequest::where('employee_id', $employee->id)->delete();
        }
        $this->audit($request, 'recording.seat_' . ($d['enabled'] ? 'grant' : 'revoke'), Employee::class, $employee->id);

        return response()->json(['ok' => true, 'data' => $this->seatsPayload($request)]);
    }

    /** POST /api/screen-recording/record-now {employee_id, minutes} — minutes 0 stops it. */
    public function recordNow(Request $request): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'minutes' => ['required', 'integer', 'min:0', 'max:720']]);
        $employee = Employee::findOrFail($d['employee_id']);
        abort_unless(ScreenRecordingSeat::where('employee_id', $employee->id)->exists(), 422, 'Give this employee Screen Recording first.');

        ScreenRecordingRequest::where('employee_id', $employee->id)->delete();
        $until = null;
        if ($d['minutes'] > 0) {
            $until = now()->addMinutes($d['minutes']);
            ScreenRecordingRequest::create(['company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'requested_by_user_id' => $request->user()->id, 'until_at' => $until]);
        }
        $this->audit($request, $until ? 'recording.record_now' : 'recording.record_now_stop', Employee::class, $employee->id, ['minutes' => $d['minutes']]);

        return response()->json(['ok' => true, 'until' => $until?->toIso8601String()]);
    }

    /** GET /api/screen-recording/recordings?employee_id=&date=Y-m-d — segments + timeline markers. */
    public function index(Request $request): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d']]);
        $this->assertEmployeeVisible($request, (int) $d['employee_id']);
        $eid = (int) $d['employee_id'];
        // 20-Oct-2026 (Ejaz): a WORK day, not a calendar day — a shift that runs past midnight stays in one piece.
        [$from, $to] = $this->workDay($eid, $d['date']);
        $dayStart = Carbon::parse($d['date'])->startOfDay();

        $segs = ScreenRecording::where('employee_id', $eid)->whereBetween('started_at', [$from, $to])
            ->orderBy('started_at')->orderBy('monitor_index')
            ->get(['id', 'monitor_index', 'started_at', 'ended_at', 'duration_seconds', 'trigger', 'trigger_detail', 'size_bytes', 'mask_status']);
        $masking = (bool) $request->user()->company?->maskTypes();

        $this->audit($request, 'recording.view_day', Employee::class, $eid, ['date' => $d['date'], 'segments' => $segs->count()]);

        // Days in the surrounding 60 days that have recordings — for the date picker hints.
        $days = ScreenRecording::where('employee_id', $eid)->where('started_at', '>=', $dayStart->copy()->subDays(60))
            ->where('started_at', '<=', $dayStart->copy()->addDays(2))->pluck('started_at')
            ->map(fn ($t) => Carbon::parse($t)->toDateString())->unique()->values();

        return response()->json(['data' => [
            'segments' => $segs->map(fn ($s) => [
                'id' => $s->id, 'monitor' => $s->monitor_index,
                'start' => $s->started_at->toIso8601String(), 'end' => ($s->ended_at ?? $s->started_at)->toIso8601String(),
                'seconds' => $s->duration_seconds, 'trigger' => $s->trigger, 'detail' => $s->trigger_detail, 'bytes' => $s->size_bytes,
                // 20-Oct-2026: null = nothing hidden (masking off) · clean · masked · pending · failed
                'mask' => $masking ? ($s->mask_status ?: 'pending') : null,
            ]),
            'markers' => $this->markers($eid, $from, $to),
            'days' => $days,
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'viewer' => $request->user()->name,
            'masking' => $masking,
            'unmasked' => $masking && $this->unmaskGranted($request, $eid),
        ]]);
    }

    /** GET /api/screen-recording/recordings/{recording}/file */
    public function file(Request $request, ScreenRecording $recording)
    {
        $this->assertEmployeeVisible($request, (int) $recording->employee_id);
        $key = $this->servedKey($request, $recording); // 20-Oct-2026: the blurred copy unless "Show original" was granted
        $disk = Storage::disk($recording->storage_driver);
        abort_unless($disk->exists($key), 404, 'Recording file is missing (cleaned up?).');

        return $disk->response($key, null, ['Content-Type' => $recording->mime_type, 'Cache-Control' => 'private, max-age=600']);
    }

    /**
     * 20-Oct-2026 (Ejaz, DPDP Act): which file this viewer may get for a minute. Hide customer data off →
     * the original. On → the blurred copy where numbers were found, the original where none were, and
     * nothing while a minute is still being checked (or could not be). The original is served only with
     * ?original=1 to someone holding recording.unmask who gave a reason in the last 4 hours (audited).
     */
    private function servedKey(Request $request, ScreenRecording $r): string
    {
        if (! $request->user()->company?->maskTypes()) return $r->storage_key;
        if ($request->boolean('original') && $this->unmaskGranted($request, (int) $r->employee_id)) return $r->storage_key;
        if ($r->mask_status === 'clean') return $r->storage_key;
        if ($r->mask_status === 'masked' && $r->masked_key) return $r->masked_key;
        abort(response()->json(['error' => ['code' => $r->mask_status === 'failed' ? 'RECORDING_MASK_FAILED' : 'RECORDING_MASK_PENDING',
            'message' => $r->mask_status === 'failed'
                ? 'This minute could not be checked for customer data, so it is kept hidden. Someone allowed to show hidden data can open the original.'
                : 'This minute is still being checked for customer data — it can be watched in a few minutes.']], 423));
    }

    private function unmaskGranted(Request $request, int $eid): bool
    {
        return $request->user()->hasPermission('recording.unmask') && AuditLog::where('user_id', $request->user()->id)
            ->where('action', 'recording.unmask')->where('subject_type', Employee::class)->where('subject_id', $eid)
            ->where('created_at', '>=', now()->subHours(4))->exists();
    }

    /** POST /api/screen-recording/unmask {employee_id, date, reason} — show the original, logged with the reason. */
    public function unmask(Request $request): JsonResponse
    {
        $d = $request->validate(['employee_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:5', 'max:200']]);
        $this->assertEmployeeVisible($request, (int) $d['employee_id']);
        $this->audit($request, 'recording.unmask', Employee::class, (int) $d['employee_id'], ['date' => $d['date'], 'reason' => trim($d['reason'])]);

        return response()->json(['ok' => true, 'until' => now()->addHours(4)->toIso8601String()]);
    }

    /**
     * GET /api/screen-recording/download?employee_id=&date=Y-m-d&from=HH:MM&to=HH:MM&monitor=
     * 19-Oct-2026 (Ejaz): download a From–To range, not just one minute. One ZIP of the minute files,
     * each named by its start time — or, per Download Criteria (20-Oct-2026), joined into longer files.
     */
    public function download(Request $request)
    {
        $d = $request->validate([
            'employee_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d'],
            'from' => ['required', 'date_format:H:i'], 'to' => ['required', 'date_format:H:i'],
            'monitor' => ['nullable', 'integer', 'min:0', 'max:15'],
            // 20-Oct-2026: From / To may fall on the next calendar day (a shift that runs past midnight).
            'from_date' => ['nullable', 'date_format:Y-m-d'], 'to_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $eid = (int) $d['employee_id'];
        $this->assertEmployeeVisible($request, $eid);
        $from = Carbon::parse(($d['from_date'] ?? $d['date']) . ' ' . $d['from']);
        $to = Carbon::parse(($d['to_date'] ?? $d['date']) . ' ' . $d['to'])->addSeconds(59);
        abort_if($to->lt($from), 422, '"To" must be after "From".');
        abort_unless(class_exists(\ZipArchive::class), 500, 'PHP zip extension is not enabled on this server.');

        $q = ScreenRecording::where('employee_id', $eid)->where('started_at', '<=', $to)
            ->where(fn ($w) => $w->where('ended_at', '>=', $from)->orWhere(fn ($x) => $x->whereNull('ended_at')->where('started_at', '>=', $from)))
            ->orderBy('started_at');
        if (isset($d['monitor'])) $q->where('monitor_index', (int) $d['monitor']);
        $segs = $q->limit(1440)->get();
        abort_if($segs->isEmpty(), 404, 'No recordings between ' . $from->format('g:i A') . ' and ' . $to->format('g:i A') . '.');
        // 20-Oct-2026: Hide customer data — every minute goes out as servedKey() decides (blurred copy unless
        // "Show original" was granted). A minute not yet checked stops the download with the reason.
        foreach ($segs as $s) $s->storage_key = $this->servedKey($request, $s);

        $dir = storage_path('app/tmp');
        if (! is_dir($dir)) mkdir($dir, 0775, true);

        // 20-Oct-2026 (Ejaz): Download Criteria — join the minutes into files of N minutes (or one file).
        $split = (int) (ScreenRecordingSetting::forCompany((int) $request->user()->company_id)->download_split_minutes ?? 1);
        $emp = Employee::find($eid);
        $base = sprintf('recording-%s-%s-%s-%s', Str::slug($emp?->employee_code ?: (string) $eid), $d['date'], str_replace(':', '', $d['from']), str_replace(':', '', $d['to']));
        if ($split !== 1) {
            return $this->joinedDownload($request, $segs, $split, $dir, $base, $eid, $d);
        }
        $path = $dir . '/rec-' . Str::uuid() . '.zip';
        $zip = new \ZipArchive();
        abort_unless($zip->open($path, \ZipArchive::CREATE) === true, 500, 'Could not create the download file.');
        $added = 0;
        foreach ($segs as $s) {
            $disk = Storage::disk($s->storage_driver);
            if (! $disk->exists($s->storage_key)) continue; // cleaned up
            $zip->addFromString(sprintf('%s_screen%d.webm', $s->started_at->format('Y-m-d_H-i-s'), $s->monitor_index + 1), $disk->get($s->storage_key));
            $zip->setCompressionName(sprintf('%s_screen%d.webm', $s->started_at->format('Y-m-d_H-i-s'), $s->monitor_index + 1), \ZipArchive::CM_STORE); // video is already compressed
            $added++;
        }
        $zip->close();
        if (! $added) { @unlink($path); abort(404, 'The recording files in that range were already cleaned up.'); }

        $this->audit($request, 'recording.download', Employee::class, $eid, ['date' => $d['date'], 'from' => $d['from'], 'to' => $d['to'], 'files' => $added]);

        return response()->download($path, $base . '.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    /**
     * 20-Oct-2026: the minutes joined into files of $split minutes (0 = one file per screen) by
     * WebmJoiner — no re-encoding, gaps between recordings skipped. A new file also starts where the
     * picture size changed (quality setting changed that day). One file → the .webm itself; more → a ZIP.
     */
    private function joinedDownload(Request $request, $segs, int $split, string $dir, string $base, int $eid, array $d)
    {
        @set_time_limit(0);
        // Group: per screen, in time order; a new group every $split minutes or when the files can't be joined.
        $groups = [];
        foreach ($segs->groupBy('monitor_index') as $mon => $list) {
            $cur = null;
            foreach ($list as $s) {
                $disk = Storage::disk($s->storage_driver);
                if (! $disk->exists($s->storage_key)) continue; // cleaned up
                $meta = WebmJoiner::parse($disk->get($s->storage_key));
                $new = ! $cur || ! $meta || ! $cur['meta']
                    || ! WebmJoiner::compatible($cur['meta'], $meta)
                    || ($split > 0 && $s->started_at->getTimestamp() - $cur['segs'][0]->started_at->getTimestamp() >= $split * 60);
                if ($new) {
                    if ($cur) $groups[] = $cur;
                    $cur = ['mon' => (int) $mon, 'meta' => $meta ? ['key' => $meta['key'], 'scale' => $meta['scale']] : null, 'segs' => []];
                }
                $cur['segs'][] = $s;
            }
            if ($cur) $groups[] = $cur;
        }
        abort_if(! $groups, 404, 'The recording files in that range were already cleaned up.');

        $files = [];
        foreach ($groups as $g) {
            $first = $g['segs'][0]; $last = end($g['segs']);
            $name = sprintf('%s_to_%s_screen%d.webm', $first->started_at->format('Y-m-d_H-i-s'),
                ($last->ended_at ?? $last->started_at)->format('H-i-s'), $g['mon'] + 1);
            $tmp = $dir . '/rec-' . Str::uuid() . '.webm';
            if (count($g['segs']) === 1 || ! $g['meta']) {
                file_put_contents($tmp, Storage::disk($first->storage_driver)->get($first->storage_key));
            } else {
                $parts = []; $lens = [];
                foreach ($g['segs'] as $s) {
                    $parts[] = fn () => WebmJoiner::parse(Storage::disk($s->storage_driver)->get($s->storage_key));
                    $lens[] = (int) $s->duration_seconds * 1000;
                }
                WebmJoiner::write($parts, $lens, $tmp);
            }
            $files[$name] = $tmp;
        }

        $this->audit($request, 'recording.download', Employee::class, $eid, ['date' => $d['date'], 'from' => $d['from'], 'to' => $d['to'],
            'files' => count($files), 'minutes_per_file' => $split ?: 'single']);

        if (count($files) === 1) {
            return response()->download(reset($files), $base . '.webm', ['Content-Type' => 'video/webm'])->deleteFileAfterSend(true);
        }
        $zipPath = $dir . '/rec-' . Str::uuid() . '.zip';
        $zip = new \ZipArchive();
        abort_unless($zip->open($zipPath, \ZipArchive::CREATE) === true, 500, 'Could not create the download file.');
        foreach ($files as $name => $tmp) { $zip->addFile($tmp, $name); $zip->setCompressionName($name, \ZipArchive::CM_STORE); }
        $zip->close();
        foreach ($files as $tmp) @unlink($tmp);

        return response()->download($zipPath, $base . '.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    // ------------------------------------------------------------------ evidence packs (20-Oct-2026)

    /**
     * POST /api/screen-recording/evidence {employee_id, date, from, to, from_date?, to_date?, monitor, reason, original?}
     * Queues one pack; it is built right after this response (and by the scheduler if that never ran).
     * With Hide customer data on, the pack is masked unless someone holding recording.unmask asks for the original.
     */
    public function evidenceStore(Request $request): JsonResponse
    {
        $d = $request->validate([
            'employee_id' => ['required', 'integer'], 'date' => ['required', 'date_format:Y-m-d'],
            'from' => ['required', 'date_format:H:i'], 'to' => ['required', 'date_format:H:i'],
            'from_date' => ['nullable', 'date_format:Y-m-d'], 'to_date' => ['nullable', 'date_format:Y-m-d'],
            'monitor' => ['nullable', 'integer', 'min:0', 'max:15'], 'reason' => ['required', 'string', 'min:5', 'max:255'],
            'original' => ['sometimes', 'boolean'],
        ]);
        $eid = (int) $d['employee_id'];
        $this->assertEmployeeVisible($request, $eid);
        $from = Carbon::parse(($d['from_date'] ?? $d['date']) . ' ' . $d['from']);
        $to = Carbon::parse(($d['to_date'] ?? $d['date']) . ' ' . $d['to'])->addSeconds(59);
        abort_if($to->lt($from), 422, '"To" must be after "From".');
        abort_if($from->diffInMinutes($to, true) > RecordingEvidence::MAX_MINUTES, 422, 'One evidence pack covers at most 8 hours — choose a shorter period.');
        abort_unless(class_exists(\ZipArchive::class), 500, 'PHP zip extension is not enabled on this server.');
        $u = $request->user();
        $masked = (bool) $u->company?->maskTypes() && ! ($request->boolean('original') && $u->hasPermission('recording.unmask'));
        $mon = (int) ($d['monitor'] ?? 0);
        abort_unless(ScreenRecording::where('employee_id', $eid)->where('monitor_index', $mon)->whereBetween('started_at', [$from, $to])->exists(), 404,
            'No recordings between ' . $from->format('g:i A') . ' and ' . $to->format('g:i A') . '.'); // 20-Oct-2026: 12-hour, as on the player

        $ev = ScreenRecordingEvidence::create([
            'company_id' => $u->company_id, 'employee_id' => $eid, 'monitor_index' => $mon,
            'code' => 'EV-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6)),
            'from_at' => $from, 'to_at' => $to, 'masked' => $masked, 'reason' => trim($d['reason']), 'status' => 'queued',
            'created_by_user_id' => $u->id,
        ]);
        $this->audit($request, 'recording.evidence_created', Employee::class, $eid, ['evidence' => $ev->code, 'date' => $d['date'],
            'from' => $d['from'], 'to' => $d['to'], 'masked' => $masked, 'reason' => $ev->reason]);
        dispatch(fn () => RecordingEvidence::build($ev->id))->afterResponse();

        return response()->json(['data' => $this->evidenceRow($ev)], 202);
    }

    /** GET /api/screen-recording/evidence?employee_id= — the packs made for this employee (newest first). */
    public function evidenceIndex(Request $request): JsonResponse
    {
        $eid = (int) $request->validate(['employee_id' => ['required', 'integer']])['employee_id'];
        $this->assertEmployeeVisible($request, $eid);

        return response()->json(['data' => ScreenRecordingEvidence::with('creator:id,name')->where('employee_id', $eid)->latest('id')->limit(30)->get()
            ->map(fn ($e) => $this->evidenceRow($e))]);
    }

    /** GET /api/screen-recording/evidence/{evidence} — everything the audit-ready PDF prints. */
    public function evidenceShow(Request $request, ScreenRecordingEvidence $evidence): JsonResponse
    {
        $this->assertEmployeeVisible($request, (int) $evidence->employee_id);
        abort_unless($evidence->status === 'done', 409, 'This evidence pack is not ready yet.');
        $m = $evidence->manifest ?? [];
        $m['activity'] = array_values(array_filter(self::markers((int) $evidence->employee_id, $evidence->from_at, $evidence->to_at),
            fn ($x) => in_array($x['type'], ['app', 'site', 'violation'], true)));
        $m['custody'] = RecordingEvidence::custody($evidence); // up to now, not just up to when it was built

        return response()->json(['data' => $this->evidenceRow($evidence) + ['manifest' => $m]]);
    }

    /** GET /api/screen-recording/evidence/{evidence}/download — the ZIP (logged in the chain of custody). */
    public function evidenceDownload(Request $request, ScreenRecordingEvidence $evidence)
    {
        $this->assertEmployeeVisible($request, (int) $evidence->employee_id);
        $path = $evidence->zip_path ? storage_path('app/' . $evidence->zip_path) : null;
        abort_unless($evidence->status === 'done' && $path && is_file($path), 404, 'Evidence file not found.');
        $this->audit($request, 'recording.evidence_download', Employee::class, $evidence->employee_id, ['evidence' => $evidence->code]);

        return response()->download($path, $evidence->code . '.zip', ['Content-Type' => 'application/zip']);
    }

    /** POST /api/screen-recording/evidence/{evidence}/verify — re-check the sealed originals and the ZIP now. */
    public function evidenceVerify(Request $request, ScreenRecordingEvidence $evidence): JsonResponse
    {
        $this->assertEmployeeVisible($request, (int) $evidence->employee_id);
        abort_unless($evidence->status === 'done', 409, 'This evidence pack is not ready yet.');
        $ids = array_column($evidence->manifest['minutes'] ?? [], 'sha256', 'id');
        $bad = [];
        foreach (ScreenRecording::whereIn('id', array_keys($ids))->get() as $r) {
            $disk = Storage::disk($r->storage_driver);
            $why = $r->sha256 === $ids[$r->id] ? $r->verify($disk->exists($r->storage_key) ? $disk->get($r->storage_key) : null) : 'fingerprint record changed';
            if ($why) $bad[] = $r->started_at->format('g:i A') . ': ' . $why;
        }
        $missing = count($ids) - ScreenRecording::whereIn('id', array_keys($ids))->count();
        $path = storage_path('app/' . $evidence->zip_path);
        $zipOk = is_file($path) && hash_equals((string) $evidence->zip_sha256, hash_file('sha256', $path));
        $this->audit($request, 'recording.evidence_verify', Employee::class, $evidence->employee_id, ['evidence' => $evidence->code, 'ok' => ! $bad && $zipOk]);

        return response()->json(['data' => ['ok' => ! $bad && $zipOk, 'zip_ok' => $zipOk, 'minutes' => count($ids),
            'missing' => $missing, 'problems' => $bad]]);
    }

    private function evidenceRow(ScreenRecordingEvidence $e): array
    {
        return ['id' => $e->id, 'code' => $e->code, 'status' => $e->status, 'error' => $e->error,
            'from' => $e->from_at->toIso8601String(), 'to' => $e->to_at->toIso8601String(), 'monitor' => $e->monitor_index,
            'masked' => $e->masked, 'reason' => $e->reason, 'minutes' => $e->minutes, 'zip_sha256' => $e->zip_sha256, 'zip_bytes' => $e->zip_bytes,
            'created_by' => $e->creator?->name, 'created_at' => $e->created_at?->toIso8601String(), 'finished_at' => $e->finished_at?->toIso8601String()];
    }

    /** POST /api/screen-recording/cleanup-now — runs this company's cleanup immediately. */
    public function cleanupNow(Request $request): JsonResponse
    {
        Artisan::call('smartept:recordings-cleanup', ['--company' => $request->user()->company_id, '--force' => true]);
        $this->audit($request, 'recording.cleanup_now', ScreenRecordingSetting::class, null);

        return response()->json(['ok' => true, 'output' => trim(Artisan::output())]);
    }

    // ------------------------------------------------------------------ agent

    /** GET /api/agent/recording/config — polled by the agent every minute. */
    public function agentConfig(Request $request): JsonResponse
    {
        $employee = $this->agentEmployee($request);
        $enabled = $this->planAllows($request->user()->company)
            && ScreenRecordingSeat::where('employee_id', $employee->id)->exists();
        if (! $enabled) {
            return response()->json(['enabled' => false, 'server_time' => now()->toIso8601String()]);
        }

        $s = ScreenRecordingSetting::forCompany((int) $employee->company_id);
        $until = ScreenRecordingRequest::where('employee_id', $employee->id)->where('until_at', '>', now())->max('until_at');
        $q = $s->quality ?: 'standard';

        return response()->json($this->settingsPayload($s) + [
            'enabled'         => true,
            'employee_id'     => $employee->id,
            'segment_seconds' => self::SEGMENT_SECONDS,
            'bitrate_kbps'    => self::BITRATE[$q] ?? 150,
            'fps'             => self::FPS[$q] ?? 2,
            'max_height'      => self::HEIGHT[$q] ?? 900,
            'on_demand_until' => $until ? Carbon::parse($until)->toIso8601String() : null,
            'server_time'     => now()->toIso8601String(),
            // 20-Oct-2026 (Ejaz): the PC burns this into every frame (plus its own name and the time), and with
            // Hide customer data on it reads the screen itself and sends a blurred copy — nothing to install anywhere.
            'watermark'       => ['company' => (string) $request->user()->company?->name,
                'employee' => trim($employee->first_name . ' ' . $employee->last_name) . ($employee->employee_code ? ' (' . $employee->employee_code . ')' : '')],
            'mask'            => $request->user()->company?->maskTypes(),
        ]);
    }

    /** POST /api/agent/recording/upload (multipart: video + metadata) */
    public function agentUpload(Request $request, StorageService $storage): JsonResponse
    {
        $employee = $this->agentEmployee($request);
        $d = $request->validate([
            'device_uuid'    => ['required', 'string', 'max:64'],
            'video'          => ['required', 'file', 'max:51200'],
            'started_at'     => ['required', 'date'],
            'ended_at'       => ['required', 'date'],
            'monitor_index'  => ['nullable', 'integer', 'min:0', 'max:15'],
            'trigger'        => ['required', 'in:' . implode(',', ScreenRecordingSetting::MODES)],
            'trigger_detail' => ['nullable', 'string', 'max:255'],
            // 20-Oct-2026: the PC's customer-data verdict for this minute, and the blurred copy when it found something
            'mask_state'     => ['nullable', 'in:clean,masked,unchecked'],
            'masked_video'   => ['nullable', 'file', 'max:51200'],
        ]);
        $this->agentDevice($request, $employee);

        if (! $this->planAllows($request->user()->company) || ! ScreenRecordingSeat::where('employee_id', $employee->id)->exists()) {
            // The agent drops the file on this code instead of retrying forever.
            return response()->json(['error' => ['code' => 'RECORDING_NOT_PERMITTED', 'message' => 'Screen Recording is not enabled for this employee.']], 403);
        }

        // 19-Oct-2026: the agent sends UTC ("...Z"); every other table holds the company's wall clock.
        // Convert, or a 14:02 IST minute is saved as 08:32 and sits 5h30m away from its apps/sites.
        $tz = $request->user()->company?->timezone ?: config('app.timezone');
        $start = Carbon::parse($d['started_at'])->setTimezone($tz);
        $end = Carbon::parse($d['ended_at'])->setTimezone($tz);
        $driver = $storage->disk();
        $dir = sprintf('smartept/%d/recording/%s', $employee->company_id, $start->format('Y-m-d'));
        $key = $request->file('video')->storeAs($dir, Str::uuid()->toString() . '.webm', ['disk' => $driver]);
        if ($key === false) {
            throw new \RuntimeException("Failed to store recording to disk [{$driver}].");
        }

        $rec = ScreenRecording::create([
            'company_id'       => $employee->company_id,
            'employee_id'      => $employee->id,
            'device_uuid'      => $d['device_uuid'],
            'monitor_index'    => $d['monitor_index'] ?? 0,
            'started_at'       => $start,
            'ended_at'         => $end,
            'duration_seconds' => max(0, $end->getTimestamp() - $start->getTimestamp()),
            'trigger'          => $d['trigger'],
            'trigger_detail'   => $d['trigger_detail'] ?? null,
            'storage_driver'   => $driver,
            'storage_key'      => $key,
            'mime_type'        => 'video/webm',
            'size_bytes'       => (int) $request->file('video')->getSize(),
        ]);
        // 20-Oct-2026: tamper-proof fingerprint of the minute, taken the moment it arrives.
        $rec->fingerprint(hash_file('sha256', $request->file('video')->getRealPath()));
        // 20-Oct-2026: Hide customer data was checked on the PC. 'unchecked' (or an old agent) stays null:
        // the background job decides (server tools if present, else kept hidden).
        $state = $d['mask_state'] ?? null;
        if ($state === 'clean') {
            $rec->forceFill(['mask_status' => 'clean', 'mask_boxes' => ['by' => 'pc'], 'masked_at' => now()])->save();
        } elseif ($state === 'masked' && $request->hasFile('masked_video')) {
            $mkey = $request->file('masked_video')->storeAs($dir, preg_replace('/\.webm$/', '', basename($key)) . '.masked.webm', ['disk' => $driver]);
            if ($mkey !== false) $rec->forceFill(['mask_status' => 'masked', 'mask_boxes' => ['by' => 'pc'], 'masked_key' => $mkey, 'masked_at' => now()])->save();
        }

        return response()->json(['ok' => true, 'id' => $rec->id], 201);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * 20-Oct-2026 (Ejaz): the player's WORK day for $date = [from, to]. It holds everything recorded during the
     * sign-in sessions that STARTED on $date — including the part after midnight — and leaves out the overnight
     * tail of the previous day's session (that belongs to the previous day). With no sign-in sessions it is
     * simply the calendar day. A session with no sign-out ends at the next sign-in (or now), never beyond 24 h.
     */
    private function workDay(int $eid, string $date): array
    {
        $dayStart = Carbon::parse($date)->startOfDay();
        $dayEnd = $dayStart->copy()->endOfDay();
        $sessions = EmployeeLoginSession::withoutGlobalScopes()->where('employee_id', $eid)
            ->whereBetween('login_at', [$dayStart->copy()->subDay(), $dayEnd->copy()->addDay()])
            ->orderBy('login_at')->get(['login_at', 'logout_at'])->values();
        $from = $dayStart->copy();
        $to = $dayEnd->copy();
        foreach ($sessions as $i => $s) {
            $in = Carbon::parse($s->login_at);
            $out = $s->logout_at ? Carbon::parse($s->logout_at)
                : (isset($sessions[$i + 1]) ? Carbon::parse($sessions[$i + 1]->login_at) : now());
            $cap = $in->copy()->addDay(); // ponytail: an unclosed session never runs past 24 h
            if ($out->gt($cap)) $out = $cap;
            if ($in->lt($dayStart) && $out->gt($from)) {
                $from = $out->gt($dayEnd) ? $dayEnd->copy() : $out->copy(); // yesterday's overnight tail
            } elseif ($in->gte($dayStart) && $in->lte($dayEnd) && $out->gt($to)) {
                $to = $out->copy();                                         // tonight's overnight part
            }
        }

        return [$from, $to];
    }

    private function planAllows($company): bool
    {
        try {
            // 19-Oct-2026 (Ejaz): Commander AND Screen Recording = Yes in Central (users > 0). With No the
            // feature is not part of this licence — no menu, no agent recording, no uploads.
            $lic = InstallationLicense::governing($company);
            return $lic->hasFeature('live_view') && (int) ($lic->bundle['features']['recording_max_users'] ?? 0) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function seatLimit($company): int
    {
        return (int) (InstallationLicense::governing($company)->bundle['features']['recording_max_users'] ?? 0);
    }

    private function seatsPayload(Request $request): array
    {
        $granted = ScreenRecordingSeat::pluck('employee_id')->flip();
        $live = ScreenRecordingRequest::where('until_at', '>', now())->get(['employee_id', 'until_at'])
            ->mapWithKeys(fn ($r) => [$r->employee_id => $r->until_at->toIso8601String()]);
        $employees = Employee::whereNull('deleted_at')->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code'])
            ->map(fn ($e) => [
                'id' => $e->id, 'name' => trim($e->first_name . ' ' . $e->last_name), 'code' => $e->employee_code,
                'granted' => $granted->has($e->id), 'record_now_until' => $live[$e->id] ?? null,
            ]);

        return ['limit' => $this->seatLimit($request->user()->company), 'used' => $granted->count(), 'employees' => $employees];
    }

    private function settingsPayload(ScreenRecordingSetting $s): array
    {
        return [
            'modes' => $s->modes ?? [], 'quality' => $s->quality ?? 'standard',
            'watched_apps' => $s->watched_apps ?? [], 'excluded_apps' => $s->excluded_apps ?? [],
            'pre_seconds' => (int) ($s->pre_seconds ?? 120), 'post_seconds' => (int) ($s->post_seconds ?? 120),
            'pause_on_idle' => (bool) ($s->pause_on_idle ?? true), 'pause_on_break' => (bool) ($s->pause_on_break ?? true),
            'monitors' => $s->monitors ?? 'primary', 'show_indicator' => (bool) ($s->show_indicator ?? true),
            'upload_kbps' => (int) ($s->upload_kbps ?? 0), 'local_cap_mb' => (int) ($s->local_cap_mb ?? 2048),
            'retention_days' => (int) ($s->retention_days ?? 30),
            'cleanup_frequency' => $s->cleanup_frequency ?? 'daily', 'cleanup_time' => $s->cleanup_time ?? '02:00',
            'cleanup_every_days' => (int) ($s->cleanup_every_days ?? 1), 'cleanup_weekday' => (int) ($s->cleanup_weekday ?? 0),
            'cleanup_month_day' => (int) ($s->cleanup_month_day ?? 1),
            'download_split_minutes' => (int) ($s->download_split_minutes ?? 1),
        ];
    }

    /** What happened on the PC that day — shown as clickable markers on the player's timeline. */
    public static function markers(int $eid, Carbon $from, Carbon $to): array // 20-Oct-2026: public static — the evidence pack uses it too
    {
        $m = [];
        $iso = fn ($t) => Carbon::parse($t)->toIso8601String();
        try {
            foreach (EmployeeAppUsageLog::where('employee_id', $eid)->whereBetween('start_at', [$from, $to])->orderBy('start_at')->limit(1500)->get(['start_at', 'app_name', 'window_title']) as $r) {
                $m[] = ['at' => $iso($r->start_at), 'type' => 'app', 'label' => $r->app_name ?: 'App', 'detail' => Str::limit((string) $r->window_title, 120)];
            }
        } catch (\Throwable $e) {}
        try {
            foreach (EmployeeWebsiteUsageLog::where('employee_id', $eid)->whereBetween('start_at', [$from, $to])->orderBy('start_at')->limit(1500)->get(['start_at', 'domain', 'page_title']) as $r) {
                $m[] = ['at' => $iso($r->start_at), 'type' => 'site', 'label' => $r->domain ?: 'Website', 'detail' => Str::limit((string) $r->page_title, 120)];
            }
        } catch (\Throwable $e) {}
        try {
            foreach (EmployeeComplianceEvent::where('employee_id', $eid)->whereBetween('started_at', [$from, $to])->orderBy('started_at')->limit(500)->get(['started_at', 'event_type', 'detected_value']) as $r) {
                $m[] = ['at' => $iso($r->started_at), 'type' => 'violation', 'label' => str_replace('_', ' ', (string) $r->event_type), 'detail' => (string) $r->detected_value];
            }
        } catch (\Throwable $e) {}
        try {
            foreach (EmployeeIdleLog::where('employee_id', $eid)->whereBetween('idle_start', [$from, $to])->orderBy('idle_start')->limit(500)->get(['idle_start', 'duration_seconds']) as $r) {
                $m[] = ['at' => $iso($r->idle_start), 'type' => 'idle', 'label' => 'Idle', 'detail' => gmdate('H:i:s', (int) $r->duration_seconds)];
            }
        } catch (\Throwable $e) {}
        try {
            foreach (EmployeeBreakLog::where('employee_id', $eid)->whereBetween('start_at', [$from, $to])->orderBy('start_at')->limit(200)->get(['start_at', 'break_type']) as $r) {
                $m[] = ['at' => $iso($r->start_at), 'type' => 'break', 'label' => 'Break', 'detail' => str_replace('_', ' ', (string) $r->break_type)];
            }
        } catch (\Throwable $e) {}
        usort($m, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $m;
    }
}
