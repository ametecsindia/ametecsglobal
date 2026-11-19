<?php

namespace App\Console\Commands;

use App\Models\ScreenRecordingEvidence;
use App\Services\RecordingEvidence;
use Illuminate\Console\Command;

/**
 * 20-Oct-2026 (Ejaz): Screen Recording background work, every minute (in its own process):
 *  1. fingerprint minutes recorded before fingerprints existed (once each)
 *  2. build evidence packs whose after-response build never ran (e.g. IIS) or died
 *  3. hide customer data: OCR + blurred copy for companies with "Hide customer data" on
 */
class ProcessScreenRecordings extends Command
{
    protected $signature = 'smartept:recordings-process {--seconds=50 : Time budget for masking}';

    protected $description = 'Screen Recording: fingerprints, evidence packs and customer-data masking';

    public function handle(): int
    {
        $fp = RecordingEvidence::fingerprintBacklog();

        ScreenRecordingEvidence::withoutGlobalScopes()->where('status', 'running')->where('updated_at', '<', now()->subHours(2))
            ->update(['status' => 'failed', 'error' => 'Stopped before finishing — create it again.']);
        $built = 0;
        foreach (ScreenRecordingEvidence::withoutGlobalScopes()->where('status', 'queued')->where('created_at', '<', now()->subMinute())->orderBy('id')->limit(3)->get() as $ev) {
            RecordingEvidence::build($ev->id);
            $built++;
        }

        $m = RecordingEvidence::processMasks(max(5, (int) $this->option('seconds')));
        $this->line("fingerprinted {$fp}, evidence packs built {$built}, masking: {$m['masked']} hidden, {$m['clean']} clean, {$m['failed']} failed");

        return self::SUCCESS;
    }
}
