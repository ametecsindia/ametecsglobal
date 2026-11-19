<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 19-Oct-2026 (Ejaz): "I do not want to show any incorrect timings."
 * Before the upload fix (19-Oct ~14:16 IST) recordings were saved with UTC times, so they sat
 * 5h30m early. This moves those rows to the company's clock. One-off and safe to re-run:
 * a row is only moved when the corrected time lies closer to when the row was actually saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('screen_recordings')) return;
        $tzOf = [];
        $rows = DB::table('screen_recordings')->where('created_at', '<=', '2026-10-19 14:30:00')
            ->get(['id', 'company_id', 'started_at', 'ended_at', 'created_at']);
        foreach ($rows as $r) {
            $tz = $tzOf[$r->company_id] ??= (function () use ($r) {
                $t = Schema::hasColumn('companies', 'timezone') ? DB::table('companies')->where('id', $r->company_id)->value('timezone') : null;
                return $t && in_array($t, timezone_identifiers_list(), true) ? $t : config('app.timezone');
            })();
            $saved = Carbon::parse($r->created_at, $tz);
            $stored = Carbon::parse($r->started_at, $tz);
            $fixed = Carbon::parse($r->started_at, 'UTC')->setTimezone($tz);
            $off = fn ($c) => abs($saved->getTimestamp() - $c->getTimestamp());
            if ($off($stored) <= 1800 || $off($fixed) >= $off($stored)) continue; // already right
            DB::table('screen_recordings')->where('id', $r->id)->update([
                'started_at' => $fixed->format('Y-m-d H:i:s'),
                'ended_at' => $r->ended_at ? Carbon::parse($r->ended_at, 'UTC')->setTimezone($tz)->format('Y-m-d H:i:s') : null,
            ]);
        }
    }

    public function down(): void
    {
        // Nothing to undo: the old times were wrong.
    }
};
