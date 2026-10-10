<?php

declare(strict_types=1);

use App\Modules\FrontOffice\Presentation\Http\Controllers\DoorLockController;
use App\Shared\Infrastructure\Integration\WebhookController;
use Illuminate\Support\Facades\Route;

// Stateless provider callbacks (TASK-FND-020): no session, cookies or CSRF; authenticity is the signature on the raw body.
Route::post('/webhooks/{provider}', WebhookController::class)
    ->where('provider', '[a-z][a-z0-9_-]{1,39}')
    ->name('webhooks.receive');

// The program beside a hotel's door lock system fetches the key jobs and reports what it did (docs/OPERATIONS/DOOR-LOCKS.md). Stateless; the token of the property is the only credential.
Route::get('/bridge/door-lock/jobs', [DoorLockController::class, 'pull'])->middleware('throttle:door-lock-bridge')->name('bridge.door-lock.jobs');
Route::post('/bridge/door-lock/jobs/{id}/result', [DoorLockController::class, 'report'])->where('id', '[0-9a-zA-Z]{26}')->middleware('throttle:door-lock-bridge')->name('bridge.door-lock.result');
