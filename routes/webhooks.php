<?php
declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Gutian\Parakit\Http\Controllers\WebhookController;
use Gutian\Parakit\Http\Middleware\AssignCorrelationId;

Route::middleware(array_merge(
    (array) config('parakit.webhooks.middleware', ['api']),
    [AssignCorrelationId::class],
))->group(function () {
    Route::post(
        config('parakit.webhooks.route_prefix', 'payments/webhooks') . '/{gateway}',
        WebhookController::class,
    )->name('parakit.webhook');
});
