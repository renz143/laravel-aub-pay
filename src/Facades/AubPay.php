<?php

namespace Prycegas\AubPay\Facades;

use Illuminate\Support\Facades\Facade;
use Prycegas\AubPay\AubPayManager;

/**
 * @method static \Prycegas\AubPay\Cashier\CashierClient cashier()
 * @method static \Prycegas\AubPay\Card\CardClient card()
 * @method static \Prycegas\AubPay\Wallet\WalletClient wallet()
 * @method static \Prycegas\AubPay\Checkout\CheckoutService checkout()
 * @method static \Prycegas\AubPay\Crypto\JwsSigner signer()
 * @method static \Prycegas\AubPay\Crypto\ParameterSignature parameterSignature()
 * @method static mixed config(?string $key = null)
 *
 * @see \Prycegas\AubPay\AubPayManager
 */
class AubPay extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return AubPayManager::class;
    }
}
