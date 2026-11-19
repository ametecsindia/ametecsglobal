<?php

namespace App\Console\Commands;

use App\Models\EmployeeAttendanceLog;
use App\Models\EmployeeComplianceEvent;
use App\Models\EmployeeDevice;
use App\Models\MailLog;
use App\Services\MailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * R2-2: operational alert emails — the server tells admins about problems
 * instead of waiting for someone to notice a blind spot.
 *
 * 1. Device-offline sweep: an agent that stopped heartbeating is flipped to
 *    OFFLINE/STALE and the company admins get ONE email per sweep listing the
 *    devices that just went silent (flip-once = natural dedupe).
 * 2. Violation spike: unusually many compliance events in the last hour →
 *    one email per company per hour (deduped via mail_logs).
 *
 * Scheduled every 30 minutes; safe to run manually any time.
 */
class SendOpsAlerts extends Command
{
    protected $signature = 'smartept:alerts {--offline-minutes=} {--spike-threshold=}';

    protected $description = 'Sweep for silent agents and violation spikes, email company admins.';

    public function handle(): int
    {
        $this->sweepOfflineDevices();
        $this->checkViolationSpikes();
        $this->sendLateLogins();

        return self::SUCCESS;
    }

    private function sweepOfflineDevices(): void
    {
        // 1) Status: unchanged — a PC silent for the server's offline limit is marked OFFLINE.
        $minutes = (int) ($this->option('offline-minutes') ?: config('smartept.offline_alert_minutes', 30));

        $gone = EmployeeDevice::query()
            ->where('current_status', '!=', 'OFFLINE')
            ->where('last_heartbeat_at', '<', now()->subMinutes($minutes))
            ->get(['id', 'company_id']);

        foreach ($gone->groupBy('company_id') as $companyId => $devices) {
            EmployeeDevice::whereIn('id', $devices->pluck('id'))
                ->update(['current_status' => 'OFFLINE', 'agent_health' => 'STOPPED']);
            $this->warn("Offline sweep: {$devices->count()} device(s) flagged for company {$companyId}.");
        }
        if ($gone->isEmpty()) {
            $this->info('Offline sweep: all agents healthy.');
        }

        // 2) Email (23-Sep-2026): each company chooses AFTER how many minutes of silence it is
        //    told (Audit & Ops → Notifications). One email per silence per PC: the heartbeat
        //    time it went quiet at is remembered, so the same outage is never re-reported.
        $silent = EmployeeDevice::query()
            ->with('employee:id,first_name,last_name,employee_code')
            ->where('current_status', 'OFFLINE')
            ->where('last_heartbeat_at', '>', now()->subDays(2)) // ignore long-retired PCs
            ->get();

        foreach ($silent->groupBy('company_id') as $companyId => $devices) {
            $p = MailService::prefs((int) $companyId)['device_offline'];
            if (empty($p['on'])) {
                continue;
            }
            $alertAfter = max(1, (int) ($this->option('offline-minutes') ?: $p['minutes']));
            $due = $devices->filter(fn ($d) => $d->last_heartbeat_at->lt(now()->subMinutes($alertAfter))
                && ! MailService::excluded('device_offline', (int) $companyId, $d->employee_id) // 13-Oct-2026
                && Cache::get('smartept:offline_alerted:' . $d->id) !== $d->last_heartbeat_at->toIso8601String());
            if ($due->isEmpty()) {
                continue;
            }

            $lines = $due->map(fn ($d) => sprintf(
                '- %s (%s) — last heartbeat %s',
                $d->computer_name ?: $d->device_uuid,
                $d->employee?->fullName() ?? 'unassigned',
                optional($d->last_heartbeat_at)->format('d M Y g:i A') ?? 'never'
            ))->implode("\n");
            $vars = ['count' => $due->count(), 'minutes' => $alertAfter, 'devices' => $lines];
            $t = MailService::TEMPLATES['device_offline'];

            // 07-Oct-2026: Send Email and/or Popup Alert, as ticked in Notifications.
            MailService::notify('device_offline', (int) $companyId, MailService::render($t['subject'], $vars), MailService::render($t['body'], $vars), $vars);
            foreach ($due as $d) {
                Cache::put('smartept:offline_alerted:' . $d->id, $d->last_heartbeat_at->toIso8601String(), now()->addDays(3));
            }
            $this->warn("Offline alert: {$due->count()} device(s) emailed for company {$companyId}.");
        }
    }

    private function checkViolationSpikes(): void
    {
        $hourTag = now()->format('Y-m-d H:00');

        // 13-Oct-2026 (Ejaz): excluded employees' violations are not counted towards the spike.
        $counts = EmployeeComplianceEvent::query()
            ->where('created_at', '>=', now()->subHour())
            ->selectRaw('company_id, employee_id, count(*) as total')
            ->groupBy('company_id', 'employee_id')
            ->get()
            ->groupBy('company_id')
            ->map(fn ($g, $cid) => (int) $g->reject(fn ($r) => MailService::excluded('violation_spike', (int) $cid, $r->employee_id))->sum('total'));

        $spikes = 0;
        foreach ($counts as $companyId => $total) {
            // Each company sets its own limit in Audit & Ops → Notifications (23-Sep-2026).
            $threshold = (int) ($this->option('spike-threshold')
                ?: MailService::pref('violation_spike', 'threshold', 0, (int) $companyId)
                ?: config('smartept.violation_spike_threshold', 20));
            if ($total < $threshold) {
                continue;
            }
            $spikes++;
            $subject = "SmartEPT alert: violation spike — {$total} events in the last hour [{$hourTag}]";

            // One alert per company per hour — matched on the hour tag, because the count
            // in the subject changes as more violations arrive (23-Sep-2026).
            // One alert per company per clock hour (kind + company + hour — independent of the
            // subject, which the company may have reworded).
            // 07-Oct-2026: a popup-only alert writes no mail log — the cache claim dedupes both.
            if (MailLog::where('kind', 'violation_spike')->where('company_id', $companyId)
                ->where('created_at', '>=', now()->startOfHour())->exists()
                || ! Cache::add('smartept:alerted:violation_spike:' . $companyId . ':' . now()->format('YmdH'), 1, now()->addHours(2))) {
                continue;
            }

            $vars = ['total' => $total, 'threshold' => $threshold, 'hour' => $hourTag];
            $t = MailService::TEMPLATES['violation_spike'];
            $body = MailService::render($t['body'], $vars);

            MailService::notify('violation_spike', (int) $companyId, $subject, $body, $vars);

            $this->warn("Violation spike: {$total} events for company {$companyId}.");
        }

        if ($spikes === 0) {
            $this->info('Violation check: no spikes.');
        }
    }

    /**
     * Late logins (23-Sep-2026): ONE email per company per day, at the hour that company
     * chose, listing everyone who logged in later than their limit today. late_minutes is
     * written when the employee first logs in / punches (AttendanceDerivation) — read only.
     */
    private function sendLateLogins(): void
    {
        $today = now()->toDateString();

        $rows = self::lateRows(null, $today);

        foreach ($rows->groupBy('company_id') as $companyId => $late) {
            $p = MailService::prefs((int) $companyId)['late_login'];
            if (empty($p['on'])) {
                continue;
            }
            // 09-Oct-2026 (Ejaz): late_minutes is already past the shift grace — no second threshold here.
            // 13-Oct-2026 (Ejaz): excluded employees are left out of the list and get no notice.
            $late = $late->reject(fn ($r) => MailService::excluded('late_login', (int) $companyId, $r->employee_id))->sortByDesc('late_minutes');

            // 07-Oct-2026 (Ejaz): "The late employee" ticked → each late employee is told once a
            // day, on the first sweep after their late login (not held back to the list hour).
            if (! empty($p['employee'])) {
                foreach ($late as $r) {
                    if ($r->employee && Cache::add('smartept:alerted:late_emp:' . $r->employee_id . ':' . $today, 1, now()->addDays(2))) {
                        $in = \App\Models\EmployeeAttendanceLog::withoutGlobalScopes()->where('employee_id', $r->employee_id)
                            ->whereDate('work_date', $today)->whereNotNull('check_in_at')->min('check_in_at');
                        MailService::notifyEmployee($r->employee, ['name' => $r->employee->fullName(), 'date' => $today,
                            'late' => MailService::lateText((int) $r->late_minutes), 'check_in' => $in ? \Illuminate\Support\Carbon::parse($in)->format('g:i A') : '-']);
                    }
                }
            }

            if ((int) now()->format('G') < (int) $p['hour']) {
                continue;
            }
            if ($late->isEmpty() || MailLog::where('kind', 'late_login')->where('company_id', $companyId)
                ->where('created_at', '>=', now()->startOfDay())->exists()
                || ! Cache::add('smartept:alerted:late_login:' . $companyId . ':' . $today, 1, now()->addDays(2))) {
                continue; // one list per company per day
            }

            $vars = self::lateListVars($late, $p, $today);
            $t = MailService::TEMPLATES['late_login'];

            MailService::notify('late_login', (int) $companyId, MailService::render($t['subject'], $vars), MailService::render($t['body'], $vars), $vars);
            $this->warn("Late logins: {$late->count()} employee(s) for company {$companyId}.");
        }
    }

    /** Today's late rows (one per employee, worst late figure), for one company or — null — every company. */
    public static function lateRows(?int $companyId, string $today): \Illuminate\Support\Collection
    {
        return EmployeeAttendanceLog::withoutGlobalScopes()
            // 04-Nov-2026 (Ejaz: list showed "Employee #18 (-)"): this sweep works across EVERY company, so the
            // employee, their login and shift are read without the company scope — whoever's web request the
            // scheduler happens to run at the end of can never blank out another company's names and codes.
            ->with([
                'employee' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'company_id', 'first_name', 'last_name', 'employee_code', 'email', 'user_id', 'shift_id'),
                'employee.user' => fn ($q) => $q->withoutGlobalScopes()->select('id', 'email'),
                'employee.shift' => fn ($q) => $q->withoutGlobalScopes(),
            ])
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->whereDate('work_date', $today)
            ->where('late_minutes', '>', 0)
            ->selectRaw('company_id, employee_id, MAX(late_minutes) as late_minutes')
            ->groupBy('company_id', 'employee_id')
            ->get();
    }

    /**
     * The late-login list as template variables ({list}, {list_html}, {count} …) — shared by the daily
     * sweep and Email Configuration → "Send a test now" (04-Nov-2026), so the test shows exactly what
     * the real alert will send.
     */
    public static function lateListVars(\Illuminate\Support\Collection $late, array $p, string $today): array
    {
        // 03-Nov-2026 (Ejaz): the list carries BOTH figures — late from the shift start and late
        // post grace — whichever the "Late from" setting counts by. late_minutes is stored by that
        // setting, so the other figure is the shift's grace added / taken away.
        $fromStart = ($p['count_from'] ?? 'GRACE') === 'START';
        $lines = $late->map(function ($r) use ($fromStart, $today) {
            $grace = (int) ($r->employee?->shiftOn($today)?->grace_minutes ?? 0);
            $m = (int) $r->late_minutes;

            return ['code' => $r->employee?->employee_code ?? '-', 'name' => $r->employee?->fullName() ?? 'Employee #' . $r->employee_id,
                'start' => $fromStart ? $m : $m + $grace, 'grace' => $fromStart ? max(0, $m - $grace) : $m];
        });
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $td = 'padding:6px 12px;border:1px solid #d5dbe3';
        $vars = ['date' => $today, 'minutes' => (int) $p['minutes'], 'count' => $late->count(),
            'list' => $lines->map(fn ($l) => sprintf('- %s (%s) — Late from Start Shift: %s · Post Grace: %s',
                $l['name'], $l['code'], MailService::lateText($l['start']), MailService::lateText($l['grace'])))->implode("\n"),
            // Same rows as a table for the email itself (popups keep the text list above).
            'list_html' => '<table style="border-collapse:collapse;font-family:Arial,sans-serif;font-size:13px">'
                . '<tr style="background:#1F3A5F;color:#fff"><th style="' . $td . ';text-align:left">Emp. Code</th><th style="' . $td . ';text-align:left">Employee</th>'
                . '<th style="' . $td . ';text-align:right">Late from Start Shift</th><th style="' . $td . ';text-align:right">Late Post Grace</th></tr>'
                . $lines->values()->map(fn ($l, $i) => '<tr style="background:' . ($i % 2 ? '#f3f6fa' : '#fff') . '"><td style="' . $td . '">' . $e($l['code']) . '</td><td style="' . $td . '">' . $e($l['name']) . '</td>'
                    . '<td style="' . $td . ';text-align:right">' . MailService::lateText($l['start']) . '</td><td style="' . $td . ';text-align:right">' . MailService::lateText($l['grace']) . '</td></tr>')->implode('')
                . '</table>'];

        return $vars;
    }
}
