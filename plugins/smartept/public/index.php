<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Branded-path API (Ejaz, 13-Aug-2026): agents are configured with the branded
// URL (admin.smartept.com/<slug>), so their calls arrive as /<slug>/api/…
// The old fix 308-redirected these to /api/… — but the Windows agent's HTTP
// stack follows redirects as GET, so every POST (auth/login, activation, sync)
// hit a POST-only route as GET and got 405 ("licence not active (http_405)").
// Serve them directly instead: strip the slug prefix BEFORE Laravel routes.
// Same method, same body, no redirect — works for every HTTP client.
// Ametecs Global (19-Nov-2026): the app may sit in a sub-folder (/AmetecsGlobal/plugins/smartept/public): the slug
// is the first segment AFTER that folder, which stays in place.
$agBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
$agUri = $_SERVER['REQUEST_URI'] ?? '';
if ($agBase !== '' && str_starts_with($agUri, $agBase . '/')) {
    $agRest = substr($agUri, strlen($agBase));
    if (preg_match('#^/[A-Za-z0-9][A-Za-z0-9-]*/api/#', $agRest)) {
        $_SERVER['REQUEST_URI'] = $agBase . preg_replace('#^/[^/]+#', '', $agRest);
    }
} elseif (preg_match('#^/[A-Za-z0-9][A-Za-z0-9-]*/api/#', $agUri)) {
    $_SERVER['REQUEST_URI'] = preg_replace('#^/[^/]+#', '', $agUri);
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
(require_once __DIR__.'/../bootstrap/app.php')
    ->handleRequest(Request::capture());
