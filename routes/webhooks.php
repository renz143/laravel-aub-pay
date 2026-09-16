<?php

use Illuminate\Support\Facades\Route;
use Prycegas\AubPay\Webhooks\CashierNotificationController;
use Prycegas\AubPay\Webhooks\WalletNotificationController;

/*
 * Published under the prefix in `aub-pay.webhooks.prefix` (default `aub`), giving:
 *
 *   POST /aub/cashier/notify
 *   POST /aub/wallet/notify
 *
 * These are the URLs to give AUB as your notifyUrl. They must be publicly reachable — AUB posts
 * server-to-server, so anything behind VPN or basic auth will silently fail delivery.
 *
 * Set `aub-pay.webhooks.register_routes` to false to point your own routes at these controllers
 * instead; they are ordinary invokable controllers.
 */

Route::post('cashier/notify', CashierNotificationController::class)->name('aub-pay.cashier.notify');
Route::post('wallet/notify', WalletNotificationController::class)->name('aub-pay.wallet.notify');
