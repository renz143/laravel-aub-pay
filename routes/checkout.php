<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Prycegas\AubPay\Checkout\CheckoutController;

/*
 * The hosted checkout, registered only while `aub-pay.checkout.enabled` is on. With
 * `aub-pay.checkout.url` set to https://checkout.prycegas.com these answer on that host:
 *
 *   GET       /{id}                the checkout page (or its paid / expired state)
 *   POST      /{id}/pay            open a payment with the chosen method
 *   GET       /{id}/qr             the QR Ph code, waiting on the customer's banking app
 *   GET       /{id}/qr/download    the same code as a file, for a customer paying from this phone
 *   GET       /{id}/status         polled by the page while a payment is open
 *   GET|POST  /{id}/return         where AUB's cashier page sends the customer back
 *
 * `{id}` is the session's 32-character hex id; nothing else matches, so these cannot shadow the
 * application's own routes under the same prefix.
 */

Route::get('{checkoutSession}', [CheckoutController::class, 'show'])->name('aub-pay.checkout.show');
Route::post('{checkoutSession}/pay', [CheckoutController::class, 'pay'])->name('aub-pay.checkout.pay');
Route::get('{checkoutSession}/qr', [CheckoutController::class, 'qr'])->name('aub-pay.checkout.qr');
Route::get('{checkoutSession}/qr/download', [CheckoutController::class, 'download'])->name('aub-pay.checkout.download');
Route::get('{checkoutSession}/status', [CheckoutController::class, 'status'])->name('aub-pay.checkout.status');

// AUB may come back with a POST from its own origin, which carries no CSRF token and never could.
// Nothing posted here is read: the route only asks AUB what happened. Excluding the framework's base
// class also excludes an application's subclass of it.
Route::match(['get', 'post'], '{checkoutSession}/return', [CheckoutController::class, 'return'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('aub-pay.checkout.return');
