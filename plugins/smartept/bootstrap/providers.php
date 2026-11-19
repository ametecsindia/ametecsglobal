<?php

return [
    App\Providers\AppServiceProvider::class,
    Ametecs\Qronex\QronexServiceProvider::class, // QR attendance module (packages/qronex-module)
    App\Demo\DemoServiceProvider::class, // Explore Demo (smartept.com) - demo companies only
];
