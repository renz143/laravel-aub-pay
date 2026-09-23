<?php

namespace Prycegas\AubPay;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Prycegas\AubPay\Checkout\CheckoutService;
use Prycegas\AubPay\Checkout\SettleCheckoutAttempt;
use Prycegas\AubPay\Console\PingCommand;
use Prycegas\AubPay\Events\PaymentFailed;
use Prycegas\AubPay\Events\PaymentSucceeded;

class AubPayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/aub-pay.php', 'aub-pay');

        $this->app->singleton(AubPayManager::class, fn ($app) => new AubPayManager(
            $app['config']->get('aub-pay', []),
            $app,
        ));

        // Through the manager, so a disabled checkout refuses in one place however it is reached.
        $this->app->bind(CheckoutService::class, fn ($app) => $app->make(AubPayManager::class)->checkout());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/aub-pay.php' => $this->app->configPath('aub-pay.php'),
            ], 'aub-pay-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'aub-pay-migrations');

            $this->commands([PingCommand::class]);
        }

        $this->registerWebhookRoutes();
        $this->registerCheckout();
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

    /**
     * The hosted checkout is opt-in: unlike the webhooks it keeps state and acts on payment events,
     * so nothing of it exists — no tables, no listener — until it is switched on.
     */
    private function registerCheckout(): void
    {
        $checkout = $this->app['config']->get('aub-pay.checkout', []);

        if (! ($checkout['enabled'] ?? false)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Settled from the same events the webhooks fire, so a checkout is paid by exactly the
        // confirmations the rest of the package trusts. PaymentPending changes nothing to settle.
        Event::listen([PaymentSucceeded::class, PaymentFailed::class], SettleCheckoutAttempt::class);
    }
}
