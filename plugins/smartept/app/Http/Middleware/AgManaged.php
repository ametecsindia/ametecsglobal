<?php

namespace App\Http\Middleware;

use App\Services\AgBridge;
use Closure;
use Illuminate\Http\Request;

/**
 * AMETECSGLOBAL (19 Nov 2026): inside Ametecs Global the licence is set only in Ametecs Global Super Admin, and employees,
 * branches, departments and teams are added and removed only in Ametecs Global Organization Setup. These SmartEPT actions
 * answer 403 there (monitoring settings on an employee stay editable here).
 */
class AgManaged
{
    public function handle(Request $request, Closure $next)
    {
        if (AgBridge::managed()) {
            $lic = str_contains($request->path(), 'license');
            return response()->json(['error' => ['code' => 'MANAGED_CENTRALLY', 'message' => $lic
                ? 'Your SmartEPT licence is managed by Ametecs in Ametecs Global (Licences & plans).'
                : 'Employees and the organisation are managed in Ametecs Global → Organization Setup. Changes there reach SmartEPT automatically.']], 403);
        }
        return $next($request);
    }
}
