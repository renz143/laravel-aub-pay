<?php

namespace Prycegas\AubPay\Console;

use Illuminate\Console\Command;
use Prycegas\AubPay\AubPayManager;
use Prycegas\AubPay\Enums\WalletService;
use Prycegas\AubPay\Exceptions\AubApiException;
use Prycegas\AubPay\Exceptions\ConfigurationException;
use Prycegas\AubPay\Exceptions\SignatureException;
use Prycegas\AubPay\Requests\CashierOrder;
use Prycegas\AubPay\Requests\WalletCharge;
use Throwable;

/**
 * End-to-end smoke test against a live gateway — the fastest way to find out whether keys,
 * merchant id and network access are actually right.
 *
 * It opens a real order for the smallest chargeable amount and prints what comes back. Nobody is
 * charged unless someone then pays it, and a cashier order lapses on its own validityPeriod.
 *
 * Point it at UAT first: `AUB_BASE_URL=https://paymentapi-uat.wepayez.com/gateway/payment`.
 */
class PingCommand extends Command
{
    protected $signature = 'aub-pay:ping
        {--rail=cashier : Which rail to test — cashier or wallet}
        {--amount=100 : Amount in minor units (100 = ₱1.00)}';

    protected $description = 'Open a throwaway order against AUB to verify credentials and connectivity.';

    public function handle(AubPayManager $manager): int
    {
        $rail = (string) $this->option('rail');
        $amount = (int) $this->option('amount');

        $this->line("Rail:     {$rail}");
        $this->line('Base URL: ' . ($rail === 'wallet'
            ? $manager->config('wallet.base_url')
            : $manager->config('base_url')));

        try {
            return match ($rail) {
                'cashier' => $this->pingCashier($manager, $amount),
                'wallet' => $this->pingWallet($manager, $amount),
                default => $this->fail("Unknown rail `{$rail}`. Expected cashier or wallet."),
            };
        } catch (ConfigurationException|SignatureException $e) {
            // A local problem — never even reached the gateway.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (AubApiException $e) {
            $this->components->error($e->getMessage());

            if ($e->errorCode !== null) {
                $this->line("Code:       {$e->errorCode}" . ($e->code() ? '  (' . $e->code()->message() . ')' : ''));
            }

            if ($e->requestId !== null) {
                // This is what AUB support will ask for.
                $this->line("Request id: {$e->requestId}");
            }

            if ($e->isConfigurationFault()) {
                $this->newLine();
                $this->components->warn(
                    'That is a configuration fault, not a decline: check AUB_MERCHANT_ID, that your '
                    . 'public key is uploaded in the merchant portal, and that AUB_JWS_PUBLIC_KEY is '
                    . "AUB's signing key rather than its encryption key."
                );
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function pingCashier(AubPayManager $manager, int $amount): int
    {
        $orderId = 'PING-' . now()->format('YmdHis');

        $session = $manager->cashier()->createOrder(new CashierOrder(
            orderId: $orderId,
            amount: $amount,
            description: 'Connectivity check',
        ));

        $this->newLine();
        $this->components->info('AUB accepted the order and its response signature verified.');
        $this->line("Order id:    {$session->orderId}");
        $this->line("Cashier URL: {$session->cashierUrl}");
        $this->newLine();
        $this->line('Open that URL to complete a test payment, then run:');
        $this->line("  php artisan tinker --execute=\"dump(AubPay::cashier()->inquire('{$orderId}'))\"");

        return self::SUCCESS;
    }

    private function pingWallet(AubPayManager $manager, int $amount): int
    {
        // This rail allows only letters, digits and underscores, and 5-32 characters.
        $outTradeNo = 'PING_' . now()->format('YmdHis');

        $result = $manager->wallet()->charge(new WalletCharge(
            service: WalletService::GcashWeb,
            outTradeNo: $outTradeNo,
            totalFee: $amount,
            body: 'Connectivity check',
        ));

        $this->newLine();
        $this->components->info('The wallet gateway accepted the charge and its signature verified.');
        $this->line("Reference: {$result->outTradeNo}");
        $this->line('Target:    ' . ($result->target() ?? '(none returned)'));

        return self::SUCCESS;
    }
}
