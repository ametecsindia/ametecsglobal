<?php

namespace App\Demo;

use Ametecs\Qronex\Models\Device as QxDevice;
use Ametecs\Qronex\Models\Employee as QxEmployee;
use Ametecs\Qronex\Models\Office;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 10-Nov-2026 (Ejaz): Explore Demo — everything the demo needs, in one place, without touching any existing module.
 * Registered by one line in bootstrap/providers.php. Every rule below applies to demo companies ONLY; for every other
 * company nothing here does anything.
 *
 *  - routes: POST /api/provision/demo (Central, secret-signed) and GET /api/demo/status (console notice)
 *  - QR scans work anywhere: every demo office skips the location check (new offices too)
 *  - phones are approved automatically, also a second phone for the same employee (no admin step)
 *  - every demo employee accepts the demo activation code in the EPT QR Attendance app, again after each use
 *  - the console shows a demo notice, incl. "no agent online" when nobody is signed in to a real agent
 *  - every 15 minutes the sample employees' day moves forward (smartept:demo-topup)
 */
class DemoServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::prefix('api')->middleware('api')->group(function () {
            Route::post('provision/demo', [DemoController::class, 'provision'])->middleware('throttle:10,1');
            Route::get('demo/status', [DemoController::class, 'status'])->middleware(['auth:sanctum', 'throttle:60,1']);
        });

        Office::saving(function (Office $o) {
            if (DemoAccount::isDemo((int) $o->tenant_id)) {
                $o->skip_geofence = true;
            }
        });

        QxDevice::saving(function (QxDevice $d) {
            if ($d->status === 'pending' && DemoAccount::isDemo((int) $d->tenant_id)) {
                $d->forceFill(['status' => 'approved', 'approved_at' => now(), 'approval_code' => null]);
            }
        });
        QxDevice::saved(function (QxDevice $d) {
            if ($d->wasChanged('status') || $d->wasRecentlyCreated) {
                if ($d->status === 'approved' && $d->replaces_device_id && DemoAccount::isDemo((int) $d->tenant_id)) {
                    QxDevice::withoutGlobalScopes()->whereKey($d->replaces_device_id)->where('status', 'approved')
                        ->update(['status' => 'revoked', 'token_hash' => null]);
                }
            }
        });

        QxEmployee::saving(function (QxEmployee $e) {
            if (! $e->activation_code_hash && $e->status === 'active' && DemoAccount::isDemo((int) $e->tenant_id)) {
                $e->activation_code_hash = Hash::make(DemoSeeder::activationCode((int) $e->tenant_id));
                $e->activation_expires_at = now()->addYears(5);
            }
        });

        $this->app->make(Router::class)->pushMiddlewareToGroup('web', DemoNotice::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $s) {
            $s->command('smartept:demo-topup')->everyFifteenMinutes()->withoutOverlapping(30)->runInBackground();
            $s->command('smartept:demo-topup --live')->everyMinute()->withoutOverlapping(5); // 16-Nov-2026: live heartbeats
        });
    }
}
