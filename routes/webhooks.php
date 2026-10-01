<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Integration\WebhookController;
use Illuminate\Support\Facades\Route;

// Stateless provider callbacks (TASK-FND-020): no session, cookies or CSRF; authenticity is the signature on the raw body.
Route::post('/webhooks/{provider}', WebhookController::class)
    ->where('provider', '[a-z][a-z0-9_-]{1,39}')
    ->name('webhooks.receive');
