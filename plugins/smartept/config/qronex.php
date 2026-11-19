<?php

// QR attendance module (packages/qronex-module). Admin screens at /qronex (opened from the console via a one-time
// sign-in link, GET /api/qronex/sso-link), phone API at /api/qronex/v1. Licence: Central plan feature `qr_attendance`.
return [
    'host' => App\Qronex\EptHost::class,
    'admin_prefix' => 'qronex',
    'admin_middleware' => ['web'],
    'activation_hours' => 72,
];
