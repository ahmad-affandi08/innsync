<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Observability\Health\HealthController;
use Illuminate\Support\Facades\Route;

// Stateless: no session, cookies, or CSRF. Liveness (`/up`) stays framework-provided.
Route::get('/health', [HealthController::class, 'summary'])->name('health');
Route::get('/health/details', [HealthController::class, 'details'])->name('health.details');
