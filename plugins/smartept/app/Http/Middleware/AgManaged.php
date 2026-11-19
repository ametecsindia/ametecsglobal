<?php

namespace App\Http\Middleware;

use App\Services\AgBridge;
use Closure;
use Illuminate\Http\Request;

/**
 * AMETECSGLOBAL (19 Nov 2026): inside AmetecsGlobal the licence is set only in AmetecsGlobal Super Admin, and employees,
 * branches, departments and teams are added and removed only in AmetecsGlobal Organization Setup. These SmartEPT actions
 * answer 403 there (monitoring settings on an employee stay editable here).
 */
class AgManaged
{
    public function handle(Request $request, Closure $next)
    {
        if (AgBridge::managed()) {
            $lic = str_contains($request->path(), 'license');
            return response()->json(['error' => ['code' => 'MANAGED_CENTRALLY', 'message' => $lic
                ? 'Your SmartEPT licence is managed by Ametecs in AmetecsGlobal (Licences & plans).'
                : 'Employees and the organisation are managed in AmetecsGlobal → Organization Setup. Changes there reach SmartEPT automatically.']], 403);
        }
        return $next($request);
    }
}
