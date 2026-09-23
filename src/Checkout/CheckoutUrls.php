<?php

namespace Prycegas\AubPay\Checkout;

use Illuminate\Support\Facades\Route;
use Prycegas\AubPay\AubPayManager;
use Prycegas\AubPay\Exceptions\ConfigurationException;

/**
 * Every address the checkout hands out — to the customer, and to AUB.
 *
 * With `aub-pay.checkout.url` set, links are built on that origin rather than on whichever host the
 * current request came in on, because the two are routinely different: a session is created from
 * the merchant's API (or a queue worker, with no request at all) and must still point at
 * checkout.prycegas.com.
 */
class CheckoutUrls
{
    public function __construct(private readonly AubPayManager $manager)
    {
    }

    /**
     * @param  string  $page  `show`, `pay`, `qr`, `download`, `status` or `return`
     */
    public function session(CheckoutSession|string $session, string $page = 'show'): string
    {
        $name = "aub-pay.checkout.{$page}";
        $parameters = ['checkoutSession' => $session instanceof CheckoutSession ? $session->getKey() : $session];

        $url = $this->manager->config('checkout.url');

        return blank($url)
            ? route($name, $parameters)
            : self::parse($url)['origin'] . route($name, $parameters, false);
    }

    /**
     * Where AUB posts a rail's notifications: the configured URL, or else the endpoint this package
     * registers for it. Null only when there is neither, which AUB rejects as a missing field.
     */
    public function notify(string $rail): ?string
    {
        $configured = $rail === 'wallet'
            ? $this->manager->config('wallet.notify_url')
            : $this->manager->config('notify_url');

        if (filled($configured)) {
            return $configured;
        }

        return Route::has("aub-pay.{$rail}.notify") ? route("aub-pay.{$rail}.notify") : null;
    }

    /**
     * Split `aub-pay.checkout.url` into what routing needs (a host and a path prefix) and what link
     * building needs (the origin).
     *
     * @return array{origin: string, host: string, prefix: string}
     */
    public static function parse(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || blank($parts['host'] ?? null)) {
            throw new ConfigurationException(
                "aub-pay.checkout.url must be an absolute URL such as https://checkout.example.com; got `{$url}`."
            );
        }

        return [
            'origin' => strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''),
            'host' => $parts['host'],
            'prefix' => trim($parts['path'] ?? '', '/'),
        ];
    }
}
