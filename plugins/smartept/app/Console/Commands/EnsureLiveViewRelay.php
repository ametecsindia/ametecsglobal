<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 09-Nov-2026 (Ejaz): "Relay connection closed" came back on admin.smartept.com after an update.
 * The relay's key file (relay/dist/relay-secret.txt) was set on the server by hand, and every update
 * zip shipped the build PC's copy over it; the relay process was also found not running (nginx 502).
 * Nothing manual on the server any more: every minute this keeps the key file equal to the key the
 * server signs LiveView tokens with, restarts a relay that is running on a stale key, and starts
 * the relay when nothing is listening on its port. A server without the relay binary is left alone.
 *
 * 09-Nov-2026, second pass: the first version launched with exec(), which the live web PHP does not
 * allow — the scheduler runs inside web requests there, so nothing started. Processes are now launched
 * exactly like UpdateClient::spawn() (proc_open on Linux, popen on Windows — proven on every server
 * since 1-Sep), every start attempt is logged, and the relay's own output goes to
 * storage/logs/liveview-relay.log so a relay that will not start says why.
 */
class EnsureLiveViewRelay extends Command
{
    protected $signature = 'smartept:relay-ensure {--dir= : relay dist folder (tests)} {--no-start : only sync the key}';

    protected $description = 'Keep the LiveView relay running with the same key the server signs with';

    public function handle(): int
    {
        $dir = $this->option('dir') ?: base_path('relay/dist');
        $bin = $dir . DIRECTORY_SEPARATOR . match (PHP_OS_FAMILY) {
            'Windows' => 'smartept-relay-win.exe',
            'Darwin' => 'smartept-relay-macos',
            default => 'smartept-relay-linux',
        };
        if (! is_file($bin)) {
            return self::SUCCESS; // LiveView relay not installed on this server
        }

        $secret = (string) config('liveview.relay_secret');
        if ($secret === '') {
            return self::SUCCESS;
        }

        $keyFile = $dir . DIRECTORY_SEPARATOR . 'relay-secret.txt';
        $current = is_file($keyFile) ? trim(strtok((string) file_get_contents($keyFile), "\n") ?: '') : '';
        // "changed" = the file held a different key and now holds the right one, so a relay that read it at start is stale.
        // A file this user may not write is no reason to stop: the relay is handed the key directly when started (below).
        $changed = $current !== $secret && @file_put_contents($keyFile, $secret . "\n") !== false;

        if ($this->option('no-start')) {
            return self::SUCCESS;
        }

        $port = (int) config('liveview.relay_port', 8098);
        $up = $this->listening($port);

        if ($up && $changed) {
            // Running on the old key — every token would be refused. Stop it; it is started again below.
            $this->stop($dir);
            sleep(1);
            $up = $this->listening($port);
        }
        if (! $up) {
            $this->start($bin, $dir, $secret);
        }

        return self::SUCCESS;
    }

    private function listening(int $port): bool
    {
        $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($s) {
            fclose($s);
            return true;
        }
        return false;
    }

    /** The relay writes its pid to relay-status.txt when it starts listening. */
    private function stop(string $dir): void
    {
        $status = json_decode((string) @file_get_contents($dir . DIRECTORY_SEPARATOR . 'relay-status.txt'), true);
        $pid = (int) ($status['pid'] ?? 0);
        if ($pid <= 1) {
            return;
        }
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_kill') && @posix_kill($pid, 15)) {
            return;
        }
        $this->launch(PHP_OS_FAMILY === 'Windows' ? 'taskkill /F /PID ' . $pid . ' > NUL 2>&1' : 'kill ' . $pid . ' > /dev/null 2>&1');
    }

    private function start(string $bin, string $dir, string $secret): void
    {
        $log = storage_path('logs/liveview-relay.log');
        if (PHP_OS_FAMILY === 'Windows') {
            // The installer's Scheduled Task runs it as SYSTEM at boot; ask it first, start directly as well (harmless if both).
            $ok = $this->launch('schtasks /Run /TN "SmartEPT LiveView Relay" > NUL 2>&1');
            if (! $this->listeningSoon()) {
                $ok = $this->launch('start "" /B /D ' . escapeshellarg($dir) . ' ' . escapeshellarg($bin) . ' > ' . escapeshellarg($log) . ' 2>&1');
            }
        } else {
            if (! is_executable($bin)) {
                @chmod($bin, 0755);
            }
            // Own session (setsid) so a php-fpm worker recycle or the end of the cron run never takes the relay down with it.
            // RELAY_SECRET in its environment wins over relay-secret.txt (relay/server.js loadSecret) — the key is never on a command line.
            // The whole group is redirected and backgrounded, so nothing stays attached to this request's output.
            // Before it starts, the relay closes every handle it inherited from this PHP process (under php-fpm that
            // can include the web request's own connection), so it never holds anything of the request open.
            $closeAndRun = 'for f in /proc/$$/fd/*; do n=${f##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n>&-"; done; exec ./'
                . basename($bin);
            $ok = $this->launch('(cd ' . escapeshellarg($dir) . ' && exec setsid nohup bash -c ' . escapeshellarg($closeAndRun)
                . ') > ' . escapeshellarg($log) . ' 2>&1 < /dev/null &', ['RELAY_SECRET' => $secret]);
        }
        if ($ok && ! $this->listeningSoon()) {
            // Started but not listening: put the relay's own last words in laravel.log (Help & Troubleshooting → Application log).
            $tail = trim(substr((string) @file_get_contents($log), -400));
            $this->note('relay was started but is not listening on port ' . config('liveview.relay_port', 8098)
                . ($tail !== '' ? ' — relay output: ' . $tail : ' — it printed nothing'));
            return;
        }
        $this->note($ok ? 'relay was not running — started it'
            : 'relay is not running and this server blocks starting programs (popen and proc_open disabled in php.ini)'
            . (is_executable($bin) ? '' : '; ' . basename($bin) . ' is not executable and could not be made so'));
    }

    private function listeningSoon(): bool
    {
        sleep(2);
        return $this->listening((int) config('liveview.relay_port', 8098));
    }

    /** Same launcher as UpdateClient::spawn(): popen on Windows, proc_open elsewhere. */
    private function launch(string $cmd, array $env = []): bool
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                if (in_array('popen', $disabled, true) || ! function_exists('popen')) {
                    return false;
                }
                $h = popen('cmd /C ' . $cmd, 'r');
                if ($h !== false) {
                    pclose($h);
                    return true;
                }
                return false;
            }
            if (! in_array('proc_open', $disabled, true) && function_exists('proc_open')) {
                $p = proc_open($cmd, [], $pipes, null, $env ? $env + getenv() : null);
                if (is_resource($p)) {
                    proc_close($p);
                    return true;
                }
            }
            if (! in_array('popen', $disabled, true) && function_exists('popen')) {
                $h = popen(($env ? 'export RELAY_SECRET=' . escapeshellarg($env['RELAY_SECRET']) . '; ' : '') . $cmd, 'r');
                if ($h !== false) {
                    pclose($h);
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->note('launch failed: ' . $e->getMessage());
        }
        return false;
    }

    /** One laravel.log line per 10 minutes at most — a relay that cannot start must not flood the log. */
    private function note(string $msg): void
    {
        $this->line('LiveView relay: ' . $msg);
        try {
            if (Cache::add('smartept:relay_ensure_logged', 1, 600)) {
                Log::warning('LiveView relay: ' . $msg);
            }
        } catch (\Throwable $e) {
            // logging must never stop the relay from being started
        }
    }
}
