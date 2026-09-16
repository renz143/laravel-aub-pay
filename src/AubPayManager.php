<?php

namespace Prycegas\AubPay;

use Prycegas\AubPay\Card\CardClient;
use Prycegas\AubPay\Cashier\CashierClient;
use Prycegas\AubPay\Crypto\JweEncrypter;
use Prycegas\AubPay\Crypto\JwsSigner;
use Prycegas\AubPay\Crypto\ParameterSignature;
use Prycegas\AubPay\Crypto\PhpseclibJweEncrypter;
use Prycegas\AubPay\Exceptions\ConfigurationException;
use Prycegas\AubPay\Http\JsonTransport;
use Prycegas\AubPay\Http\XmlTransport;
use Prycegas\AubPay\Wallet\WalletClient;

/**
 * Entry point for all three rails: `AubPay::cashier()`, `AubPay::card()`, `AubPay::wallet()`.
 *
 * Each rail is built lazily and memoised, so a project that only uses the Cashier rail never
 * constructs a wallet signer or touches the optional JWE dependency. The two rails that carry a
 * consequence — card (PCI scope) and wallet (separate AUB onboarding) — refuse to build unless
 * explicitly enabled in config, so neither can be reached by accident.
 */
class AubPayManager
{
    private array $clients = [];

    public function __construct(private readonly array $config)
    {
    }

    public function cashier(): CashierClient
    {
        return $this->clients['cashier'] ??= new CashierClient(
            $this->jsonTransport(),
            [
                'callback_url' => $this->config['callback_url'] ?? null,
                'notify_url' => $this->config['notify_url'] ?? null,
                'validity_period' => $this->config['validity_period'] ?? null,
            ],
        );
    }

    /**
     * The direct card API. Disabled by default — enabling it means raw PANs pass through your
     * servers, which is a PCI-DSS scope decision, not a configuration detail.
     */
    public function card(): CardClient
    {
        if (! ($this->config['card']['enabled'] ?? false)) {
            throw ConfigurationException::railDisabled('direct card', 'aub-pay.card.enabled');
        }

        return $this->clients['card'] ??= new CardClient(
            $this->jsonTransport(),
            $this->jweEncrypter(),
            ['redirect_url' => $this->config['card']['redirect_url'] ?? null],
        );
    }

    public function wallet(): WalletClient
    {
        if (! ($this->config['wallet']['enabled'] ?? false)) {
            throw ConfigurationException::railDisabled('wallet/QR', 'aub-pay.wallet.enabled');
        }

        $wallet = $this->config['wallet'];

        return $this->clients['wallet'] ??= new WalletClient(
            new XmlTransport(
                $this->parameterSignature(),
                $wallet['base_url'] ?? '',
                (int) ($wallet['timeout'] ?? 30),
                $this->config['log_channel'] ?? null,
            ),
            [
                'mch_id' => $wallet['mch_id'] ?? null,
                'notify_url' => $wallet['notify_url'] ?? null,
                'callback_url' => $wallet['callback_url'] ?? null,
                'sign_type' => $wallet['sign_type'] ?? ParameterSignature::SHA256,
            ],
        );
    }

    /**
     * The JWS signer, exposed because verifying a signature is occasionally something an
     * application needs to do for itself — a bespoke webhook route, or a diagnostic command.
     */
    public function signer(): JwsSigner
    {
        return $this->clients['signer'] ??= new JwsSigner(
            $this->config['private_key'] ?? null,
            $this->config['jws_public_key'] ?? null,
        );
    }

    public function parameterSignature(): ParameterSignature
    {
        $wallet = $this->config['wallet'] ?? [];

        return $this->clients['parameter_signature'] ??= new ParameterSignature(
            $wallet['sign_type'] ?? ParameterSignature::SHA256,
            $wallet['api_key'] ?? null,
            $wallet['private_key'] ?? ($this->config['private_key'] ?? null),
            $wallet['public_key'] ?? null,
        );
    }

    public function config(?string $key = null): mixed
    {
        return $key === null ? $this->config : data_get($this->config, $key);
    }

    private function jsonTransport(): JsonTransport
    {
        return $this->clients['json'] ??= new JsonTransport(
            $this->signer(),
            $this->config['base_url'] ?? '',
            $this->config['merchant_id'] ?? null,
            (int) ($this->config['timeout'] ?? 30),
            (bool) ($this->config['verify_responses'] ?? true),
            $this->config['log_channel'] ?? null,
        );
    }

    private function jweEncrypter(): JweEncrypter
    {
        return $this->clients['jwe'] ??= new PhpseclibJweEncrypter(
            $this->config['card']['jwe_public_key'] ?? null
        );
    }
}
