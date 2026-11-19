<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ScreenRecording;
use App\Models\ScreenRecordingRequest;
use App\Models\ScreenRecordingSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * 15-Oct-2026 (Ejaz): Screen Recording cleanup. Each company chooses WHEN it runs
 * (daily at a time / every N days / weekly on a day / monthly on a date — on the
 * company's own clock, Organisation tab) and HOW LONG recordings are kept
 * (retention_days). The scheduler calls this every 5 minutes; a company is only
 * cleaned when its slot is due (ScreenRecordingSetting::cleanupDue). --force runs now.
 */
class CleanupScreenRecordings extends Command
{
    protected $signature = 'smartept:recordings-cleanup {--company= : Only this company id} {--force : Run now, ignoring the schedule}';

    protected $description = 'Delete screen recordings older than each company\'s retention, on each company\'s schedule';

    public function handle(): int
    {
        $q = ScreenRecordingSetting::withoutGlobalScopes();
        if ($this->option('company')) {
            $q->where('company_id', (int) $this->option('company'));
        }
        $settings = $q->get();
        // A company that never saved settings still gets the defaults when forced from the console.
        if ($this->option('force') && $this->option('company') && $settings->isEmpty()) {
            $settings = collect([ScreenRecordingSetting::forCompany((int) $this->option('company'))]);
        }

        foreach ($settings as $s) {
            $tz = Company::withoutGlobalScopes()->whereKey($s->company_id)->value('timezone');
            $tz = $tz && in_array($tz, timezone_identifiers_list(), true) ? $tz : config('app.timezone');
            if (! $this->option('force') && ! $s->cleanupDue(now($tz))) {
                continue;
            }

            $cutoff = now()->subDays(max(1, (int) ($s->retention_days ?: 30)));
            $files = 0;
            $bytes = 0;
            ScreenRecording::withoutGlobalScopes()->where('company_id', $s->company_id)
                ->where('started_at', '<', $cutoff)
                ->chunkById(500, function ($rows) use (&$files, &$bytes) {
                    foreach ($rows as $r) {
                        try { Storage::disk($r->storage_driver)->delete($r->storage_key); } catch (\Throwable $e) { /* already gone */ }
                        // 20-Oct-2026: and its blurred copy. Evidence packs are NOT touched — they are kept on purpose.
                        if ($r->masked_key) { try { Storage::disk($r->storage_driver)->delete($r->masked_key); } catch (\Throwable $e) {} }
                        $bytes += (int) $r->size_bytes;
                        $files++;
                    }
                    ScreenRecording::withoutGlobalScopes()->whereIn('id', $rows->pluck('id'))->delete();
                });
            ScreenRecordingRequest::withoutGlobalScopes()->where('company_id', $s->company_id)->where('until_at', '<', now()->subDay())->delete();

            $s->forceFill(['last_cleanup_at' => now()])->save();
            $this->line("company {$s->company_id}: removed {$files} recording file(s), " . round($bytes / 1048576, 1) . ' MB older than ' . $cutoff->toDateString());
        }

        return self::SUCCESS;
    }
}
