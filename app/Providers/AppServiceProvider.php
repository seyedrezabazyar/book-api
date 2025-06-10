<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use App\Services\ConfigService;
use App\Services\StatsService;
use App\Services\ExecutionService;
use App\Services\QueueManagerService;
use App\Services\ApiClient;
use App\Services\BookProcessor;
use App\Services\FieldExtractor;
use App\Services\DataValidator;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Service bindings
        $this->app->singleton(ConfigService::class);
        $this->app->singleton(StatsService::class);
        $this->app->singleton(ExecutionService::class);
        $this->app->singleton(QueueManagerService::class);

        // Services with dependencies
        $this->app->bind(ApiClient::class, function ($app) {
            // ApiClient needs a Config instance, so we'll create it when needed
            return $app->make(ApiClient::class);
        });

        $this->app->bind(BookProcessor::class, function ($app) {
            return new BookProcessor(
                $app->make(FieldExtractor::class),
                $app->make(DataValidator::class)
            );
        });

        $this->app->singleton(FieldExtractor::class);
        $this->app->singleton(DataValidator::class);
    }

    public function boot(): void
    {
        Paginator::useBootstrapFour();
    }
}
