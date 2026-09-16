<?php

namespace Prycegas\AubPay;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Prycegas\AubPay\Console\PingCommand;

class AubPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/aub-pay.php', 'aub-pay');

        $this->app->singleton(AubPayManager::class, fn ($app) => new AubPayManager(
            $app['config']->get('aub-pay', [])
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/aub-pay.php' => $this->app->configPath('aub-pay.php'),
            ], 'aub-pay-config');

            $this->commands([PingCommand::class]);
        }

        $this->registerWebhookRoutes();
    }

    /**
     * The webhook endpoints are opt-out rather than opt-in: a notification URL that 404s looks to
     * AUB like a failed delivery and gets retried for hours, which is a confusing first experience
     * for something the package can just set up correctly.
     */
    private function registerWebhookRoutes(): void
    {
        $webhooks = $this->app['config']->get('aub-pay.webhooks', []);

        if (! ($webhooks['register_routes'] ?? true)) {
            return;
        }

        Route::group([
            'prefix' => $webhooks['prefix'] ?? 'aub',
            'middleware' => $webhooks['middleware'] ?? [],
        ], function () {
            $this->loadRoutesFrom(__DIR__ . '/../routes/webhooks.php');
        });
    }
}
