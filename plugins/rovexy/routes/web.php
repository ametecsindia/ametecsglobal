<?php

use App\Http\Controllers\Site\SiteController;
use Illuminate\Support\Facades\Route;

// The admin SPA (React build output copied to public/admin). Served from the company's own origin so cookies,
// CSRF and CSP stay same-origin. The Electron desktop app loads this URL.
// AMETECSGLOBAL (19 Nov 2026): inside AmetecsGlobal the server lives in a sub-folder (/AmetecsGlobal/plugins/rovexy/public),
// so the console page is given its <base> and the folder (window.__ROOT__); the console prefixes every address with it.
$agConsole = static function () {
    $index = public_path('admin/index.html');
    abort_unless(is_file($index), 404, 'Admin UI not built. Run: cd console && npm run build');
    $root = rtrim(request()->getBasePath(), '/');
    // No inline script and no <base>: the Content-Security-Policy (SecurityHeaders) forbids both. A <meta> carries the folder.
    $html = str_replace(['<head>', '"./assets/', '"../brand/', '"../favicon.ico"'],
        ['<head><meta name="ag-root" content="' . e($root) . '">' . (\App\Services\AgBridge::managed() ? '<meta name="ag-managed" content="1"><link rel="stylesheet" href="' . e($root) . '/ag-brand.css?v=4"><script src="' . e($root) . '/ag-theme.js?v=1"></script>' : ''), '"' . $root . '/admin/assets/', '"' . $root . '/brand/', '"' . $root . '/favicon.ico"'], (string) file_get_contents($index));
    if (\App\Services\AgBridge::managed()) {   // one brand: the AmetecsGlobal button, back to the panel
        $html = preg_replace('/<body([^>]*)>/', '<body$1><a class="ag-home" href="' . e(preg_replace('#/plugins/rovexy/public$#', '', $root) . '/core/') . '" title="Back to AmetecsGlobal" target="_top">AG</a><script src="' . e(preg_replace('#/plugins/rovexy/public$#', '', $root) . '/core/assets/ag-topbar.js?v=3') . '" defer></script>', $html, 1);
    }
    return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store']);
};
Route::get('/admin/{path?}', $agConsole)->where('path', '.*'); // real files (admin/assets/*) are served by the web server before Laravel

// AMETECSGLOBAL one sign-in: AmetecsGlobal posts a signed hand-over (core/open.php); see AgSsoController.
Route::match(['get', 'post'], '/ag/sso', [\App\Http\Controllers\AgSsoController::class, 'enter'])->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class]);

// A company's console by path: /w/<slug>/admin (the only console address on the shared server).
Route::get('/w/{slug}/admin/{path?}', $agConsole)->where(['slug' => '[a-z0-9][a-z0-9-]{1,38}[a-z0-9]', 'path' => '.*']);

// G1: the full Android app (all-day location while signed in), downloaded from this company's own server.
// The file is public/downloads/rovexy.apk + rovexy-apk.json, put there by the build (build-client.bat) — not in git.
Route::get('/get-app', function () {
    $apk = public_path('downloads/rovexy.apk');
    $meta = json_decode((string) @file_get_contents(public_path('downloads/rovexy-apk.json')), true) ?: [];
    $company = rescue(fn () => \App\Models\Company::query()->orderBy('id')->value('name'), null, false);
    return response()->view('site.app-download', ['apk' => is_file($apk), 'version' => $meta['version'] ?? '—', 'server' => rtrim(config('app.url'), '/'),
        'size' => is_file($apk) ? number_format(filesize($apk) / 1048576, 1) : 0, 'company' => \App\Services\Licensing\LicenceClient::isClient() && $company ? $company : 'your company'],
        200, ['Cache-Control' => 'no-cache']);
});
Route::get('/get-app/download', function () {
    $apk = public_path('downloads/rovexy.apk');
    abort_unless(is_file($apk), 404);
    return response()->download($apk, 'ROVEXY.apk', ['Content-Type' => 'application/vnd.android.package-archive', 'Cache-Control' => 'no-cache']);
})->middleware('throttle:30,1');

// Company users: set a password from the welcome e-mail, or reset a forgotten one.
Route::get('/password-forgot', [\App\Http\Controllers\Site\PasswordController::class, 'forgot']);
Route::post('/password-forgot', [\App\Http\Controllers\Site\PasswordController::class, 'sendReset'])->middleware('throttle:6,1');
Route::get('/password/{token}', [\App\Http\Controllers\Site\PasswordController::class, 'show'])->where('token', '[A-Za-z0-9]{48}');
Route::post('/password/{token}', [\App\Http\Controllers\Site\PasswordController::class, 'store'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1');

// Central server only (not on an on-prem install): Super Admin, Accounts, website, buying and paying.
Route::middleware('central')->group(function () {
    // Super Admin console (Ametecs staff): the same SPA build, which switches to the Super Admin app under /super.
    Route::get('/super/{path?}', function () {
        return response()->file(public_path('admin/index.html'), ['Cache-Control' => 'no-store']);
    })->where('path', '.*');

    // Accountant panel (Ametecs accounts staff): the same SPA build, which switches to the Accounts app under /accounts.
    Route::get('/accounts/{path?}', function () {
        return response()->file(public_path('admin/index.html'), ['Cache-Control' => 'no-store']);
    })->where('path', '.*');

    // Public website: marketing page, free trial, buy → Razorpay, invoices.
    Route::get('/', [SiteController::class, 'home']);
    Route::get('/buy', [SiteController::class, 'buy']);
    Route::get('/explore-demo', [SiteController::class, 'exploreDemo']);   // demo client sign-up (DemoWorkspace)
    Route::get('/play/{app}', [SiteController::class, 'appForm'])->whereIn('app', ['staff', 'admin']);   // Google Play buttons: details + e-mail code first
    // Live demo of the Android app (static page in public/live-demo; sample data, no server calls).
    Route::get('/demo', fn () => response()->file(public_path('live-demo/index.html'), ['Cache-Control' => 'no-cache']));
    Route::middleware('throttle:site')->group(function () {
        Route::post('/enquiry', [SiteController::class, 'enquiry']);
        Route::post('/trial', [SiteController::class, 'trial']);
        Route::post('/explore-demo', [SiteController::class, 'demo']);
        Route::post('/play/{app}', [SiteController::class, 'appLead'])->whereIn('app', ['staff', 'admin']);
        Route::post('/trial/verify', [SiteController::class, 'verifyTrial']);
        Route::post('/verify-email', [SiteController::class, 'verifyTrial']);
        Route::post('/verify-email/resend', [SiteController::class, 'resendOtp']);
        Route::post('/buy', [SiteController::class, 'placeOrder']);
    });
    Route::post('/price-check', [SiteController::class, 'priceCheck'])->middleware('throttle:site-pay');
    Route::get('/pay/{number}/{token}', [SiteController::class, 'pay']);
    Route::get('/pay/{number}/{token}/invoice', [SiteController::class, 'invoice']);
    Route::post('/pay/{number}/{token}/razorpay', [SiteController::class, 'razorpayOrder'])->middleware('throttle:site-pay');
    Route::post('/pay/{number}/{token}/verify', [SiteController::class, 'razorpayVerify'])->middleware('throttle:site-pay');
    Route::post('/pay/{number}/{token}/failed', [SiteController::class, 'razorpayFailed'])->middleware('throttle:site-pay');
    Route::get('/{page}', [SiteController::class, 'page'])->whereIn('page', ['thank-you', 'privacy', 'terms', 'refund', 'delivery', 'contact']);
    Route::get('/robots.txt', fn () => response("User-agent: *\nDisallow: /admin\nDisallow: /super\nDisallow: /accounts\nDisallow: /w/\nDisallow: /password\nDisallow: /pay/\nDisallow: /api/\nSitemap: " . url('/sitemap.xml') . "\n", 200, ['Content-Type' => 'text/plain']));
    Route::get('/sitemap.xml', fn () => response('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . collect(['/', '/demo', '/explore-demo', '/buy', '/contact', '/privacy', '/terms', '/refund', '/delivery'])->map(fn ($p) => '<url><loc>' . e(url($p)) . '</loc></url>')->implode('')
        . '</urlset>', 200, ['Content-Type' => 'application/xml']));
});
