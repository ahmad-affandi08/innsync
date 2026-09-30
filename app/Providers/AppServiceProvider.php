<?php

declare(strict_types=1);

namespace App\Providers;

use App\Shared\Application\Tenancy\PropertyContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(PropertyContext::class, static fn (): PropertyContext => new PropertyContext);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
