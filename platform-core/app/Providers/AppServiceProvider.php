<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind services as singletons so they're reused within a request
        $this->app->singleton(\App\Services\NlpService::class);
        $this->app->singleton(\App\Services\MatcherService::class);
        $this->app->singleton(\App\Services\MpesaService::class);
        $this->app->singleton(\App\Services\TextExtractorService::class);
    }

    public function boot(): void
    {
        //
    }
}
