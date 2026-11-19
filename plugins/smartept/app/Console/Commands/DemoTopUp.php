<?php

namespace App\Console\Commands;

use App\Demo\DemoAccount;
use App\Demo\DemoSeeder;
use Illuminate\Console\Command;

/** 10-Nov-2026 (Ejaz): Explore Demo — moves the sample employees' day forward (scheduled every 15 minutes by DemoServiceProvider). */
class DemoTopUp extends Command
{
    protected $signature = 'smartept:demo-topup {--live : only the once-a-minute live heartbeats of the sample PCs}';
    protected $description = 'Demo companies: simulate the sample employees\' activity up to now';

    public function handle(): int
    {
        if ($this->option('live')) {
            foreach (DemoAccount::where('status', 'ready')->get() as $acc) {
                try {
                    DemoSeeder::live($acc);
                } catch (\Throwable $e) {
                    report($e);
                }
            }

            return self::SUCCESS;
        }

        // 11-Nov-2026: finish any demo whose first build stopped (still "building" 15+ minutes after it began).
        foreach (DemoAccount::where('status', 'building')->where('updated_at', '<', now()->subMinutes(15))->get() as $acc) {
            try {
                DemoSeeder::finish($acc);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        foreach (DemoAccount::where('status', 'ready')->get() as $acc) {
            try {
                DemoSeeder::topUp($acc);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
