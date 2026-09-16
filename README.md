# prycegas/laravel-aub-pay

A Laravel client for AUB's payment APIs.

AUB resells Wallyt's gateway (`wepayez.com`) under its own brand, and what arrives as "the AUB
integration" is really **three unrelated APIs** that share a vendor and nothing else. This package
puts one facade in front of all three and keeps their differences where they belong — inside it.

| Rail | Use for | Host | Body | Signing | PCI |
|---|---|---|---|---|---|
| **`cashier()`** | Cards, via AUB's hosted page | `paymentapi.wepayez.com` | JSON | detached JWS | SAQ-A |
| **`card()`** | Cards, collected by you | `paymentapi.wepayez.com` | JSON | JWS + JWE | **SAQ-D** |
| **`wallet()`** | GCash, GrabPay, QRPh, Instapay, Alipay+, WeChat | `gateway.wepayez.com` | XML | hashed parameter string | n/a |

Start with `cashier()`. It is the rail with production mileage, and it does the same job as
`card()` without putting card numbers on your servers.

## Install

```jsonc
// composer.json
"repositories": [
    { "type": "vcs", "url": "git@github.com:prycegas/laravel-aub-pay.git" }
]
```

```bash
composer require prycegas/laravel-aub-pay
php artisan vendor:publish --tag=aub-pay-config
```

```dotenv
AUB_MERCHANT_ID=800580000004
AUB_PRIVATE_KEY="MIIEvQIBADANBgkq…"        # yours; the public half goes in AUB's portal
AUB_JWS_PUBLIC_KEY="MIIBIjANBgkqhkiG…"     # AUB's "Countersign_pubkey"
AUB_CALLBACK_URL=https://shop.example/thank-you
AUB_NOTIFY_URL=https://shop.example/aub/cashier/notify
```

Keys go in as either a PEM block or the bare base64 the merchant portal hands out — the whitespace
step the integration guide tells you to do by hand (§2.1.3.7) is handled for you.

Check it works before writing any code:

```bash
php artisan aub-pay:ping           # opens a ₱1 order and prints the cashier URL
```

## Taking a payment

```php
use Prycegas\AubPay\Facades\AubPay;
use Prycegas\AubPay\Requests\CashierOrder;

$session = AubPay::cashier()->createOrder(new CashierOrder(
    orderId: $order->reference,   // yours, ≤64 chars, unique — see below
    amount: 9200,                 // MINOR UNITS. ₱92.00, not 92.
    description: 'Pryce Gas order',
));

return redirect($session->cashierUrl);
```

Then listen for the result:

```php
// EventServiceProvider
protected $listen = [
    \Prycegas\AubPay\Events\PaymentSucceeded::class => [MarkOrderPaid::class],
    \Prycegas\AubPay\Events\PaymentFailed::class    => [ReleaseReservedStock::class],
];
```

```php
class MarkOrderPaid
{
    public function handle(PaymentSucceeded $event): void
    {
        $order = Order::where('reference', $event->orderId())->first();

        // Fires more than once for the same order — see "Notifications" below.
        if (! $order || $order->paid_at) {
            return;
        }

        // Always check the amount yourself. A confirmed payment proves money moved,
        // not that it was the amount you asked for.
        abort_unless($event->transaction->amount === $order->total_centavos, 409);

        $order->update(['paid_at' => now(), 'gateway_reference' => $event->transaction->referencedId]);
    }
}
```

That is the whole integration. The webhook endpoint is registered for you at
`POST /aub/cashier/notify` (give that URL to AUB as your `notifyUrl`), and it confirms the payment
with the gateway before any event fires.

### Four things about this gateway that will bite you otherwise

**Amounts are always in minor units.** Every rail, every field. `9200` is ₱92.00. The gateway will
cheerfully charge ₱0.92 if you pass `92`.

**You mint the order id, and it is the only handle you get.** AUB has no session id of its own at
checkout time. The `orderId` you send *is* what inquiry, refund and the notification are keyed on,
so it must be unique and storable. This package refuses to truncate it rather than hand you back an
order you cannot look up. Note the wallet rail is stricter still: 5–32 characters, alphanumeric and
underscore only.

**There is no "cancel this checkout" on the cashier rail.** Only `validityPeriod`, set once at
creation (`AUB_VALIDITY_PERIOD`, default 30 minutes). An abandoned order stays payable until it
lapses, so a customer who cancels and later finds the old tab can still pay. Keep that value at or
below whatever window your app prunes unpaid orders on. (The wallet rail *does* have `close()`, and
you should use it.)

**`PENDING` is not a failure.** AUB reports three outcomes where most gateways report two. Cancel on
`PaymentFailed`; on `PaymentPending`, wait.

## Notifications

The endpoints are registered automatically:

```
POST /aub/cashier/notify
POST /aub/wallet/notify
```

Set `aub-pay.webhooks.register_routes` to `false` to route them yourself; the controllers are
ordinary invokable classes.

**The cashier notification is treated as a hint, never as proof.** It carries no signature AUB will
vouch for, and the endpoint is necessarily public — AUB posts server-to-server with no credential
you could check. So the package reads one thing from it, *which order to look at*, and then asks the
gateway what actually happened. That answer **is** signed, and the signature is verified before any
event fires. A forged notification therefore buys an attacker a single inquiry call and nothing
else. There is a test for exactly this.

The wallet notification *is* signed, so it takes a faster path: signature verified, outcome read
directly, no second call. It falls back to asking the gateway if the signature does not verify.
(That rail also gives you a five-second budget before it counts the delivery as failed.)

**Notifications repeat.** AUB re-delivers anything it does not get a clean acknowledgement for —
the wallet rail retries at 0/15/15/30/180/1800/1800/1800/1800/3600 seconds, for up to three hours.
Make your listeners idempotent.

## Refunds

```php
$transaction = AubPay::cashier()->inquire($order->reference);

AubPay::cashier()->refund(
    orderId: $order->reference,
    referencedId: $transaction->referencedId,  // AUB's id, only exists after payment
    amount: 5000,                              // partial refunds are fine
);
```

## Wallets and QR

```php
use Prycegas\AubPay\Enums\WalletService;
use Prycegas\AubPay\Requests\WalletCharge;

$result = AubPay::wallet()->charge(new WalletCharge(
    service: WalletService::GcashWeb,   // ->GcashQr for a scannable code instead
    outTradeNo: 'ORDER_100001',
    totalFee: 9200,
    body: 'Pryce Gas order',
));

return redirect($result->target());     // pay_url for webpay, code_url for QR
```

Enable with `AUB_WALLET_ENABLED=true` plus `AUB_WALLET_MCH_ID` and `AUB_WALLET_API_KEY` — separate
credentials from the card rail, and separate onboarding with AUB.

## Direct card API

> ⚠ **This rail takes you from PCI-DSS SAQ-A to SAQ-D.** Raw PANs pass through your servers, which
> means quarterly scans, annual penetration testing and a much larger control set across every
> system the data touches. `cashier()` does the same job without any of it. It is also the
> least-proven rail here — built from the specification and the vendor's Java reference
> implementation, not from production traffic.

Off by default. `AUB_CARD_ENABLED=true`, plus `AUB_JWE_PUBLIC_KEY` (AUB's *encryption* key, which is
a different key from the signing one — mixing them up is the usual cause of error 07) and
`composer require phpseclib/phpseclib`.

```php
$transaction = AubPay::card()->pay(new CardPayment(
    orderId: 'CARD-1',
    amount: 9200,
    card: new CardDetails($pan, '03', '2027', $cvv),
    redirectUrl: 'https://shop.example/3ds/return',   // present => routed through 3-D Secure
));
```

3-D Secure is asynchronous: an approval can come back as code `01`, "result unknown, wait for the
notification and inquire again". Treating that as a failure charges customers and then cancels their
orders. Confirm with `inquire()`.

## Errors

Everything the gateway rejects throws `AubApiException`, which sorts the response codes (§6.1) into
groups you can act on differently:

```php
try {
    AubPay::cashier()->createOrder($order);
} catch (AubApiException $e) {
    if ($e->isConfigurationFault()) { /* our key/merchant id is wrong — page someone */ }
    if ($e->isIndeterminate())      { /* DO NOT mark failed; the customer may be charged */ }
    if ($e->code()?->isDeclined())  { /* normal business — show $e->getMessage() */ }

    Log::warning($e->getMessage(), ['code' => $e->errorCode, 'request_id' => $e->requestId]);
}
```

`$e->requestId` is the `Customer-Request-Id`, and it is the first thing AUB support will ask for.

## Testing

`Prycegas\AubPay\Testing\AubPayFake` builds correctly-signed fake responses, which you need because
a *successful* response is only believed once its signature verifies — a plain `Http::fake()` returns
something the client correctly refuses.

```php
Http::fake(['*' => AubPayFake::success(
    ['cashierUrl' => 'https://cashier.test/x'],
    new JwsSigner($privateKey, $publicKey),
)]);

Http::fake(['*' => AubPayFake::error('30', 'INSUFFICIENT CARD LIMIT')]);   // deliberately unsigned
```

Run the package's own suite with the bundled image (its `gmp` is purely to keep the JWE
interoperability test fast):

```bash
docker build -f Dockerfile.test -t aub-pay-test .
docker run --rm -v "$PWD":/pkg -w /pkg aub-pay-test vendor/bin/phpunit
```

## Where the vendor documentation is wrong

Found the hard way; recorded so nobody re-derives them.

- **AUB does not sign its error replies.** A correctly signed request with a bad `Merchant-Id`
  returns `{"code":"99","message":"System error"}` with no `Authorization` header at all. The
  response code is therefore checked *before* the signature — otherwise every gateway error reads as
  "the signature could not be verified" and hides the only actionable message. A *success* is still
  refused unless AUB's key vouches for the exact bytes.
- **The success code is ambiguous.** §5.1.4 and §6.1 say `00`; the refund sample in §5.3.4 shows
  `S0000`. Both are accepted — reading a success as a failure strands a customer who has been
  charged.
- **The JWS is not a JWS any library will produce.** Detached, serialized
  `base64url(header) . ".." . base64url(sig)` — doubled dot, empty payload segment — with a
  non-standard `timestamp` claim in the protected header. Hence `Crypto\JwsSigner` rather than a
  JWT package.
- **The wallet rail's worked signature example does not add up.** §4.2 prints a `string1`, a key and
  an expected hash; hashing its own inputs with its own key does not produce its own answer, under
  any of 84 constructions tried. Its `string1` *is* correct and is pinned as a test, so the assembly
  is verified; the hash step follows the stated formula and is confirmed by the first live call.
- **Codes 20–47 are not all declines.** `21 INVALID MERCHANT` is a setup fault and `24`/`25` are
  malformed requests, sitting among genuine card declines. `isDeclined()` excludes them, so
  "declines are normal" filtering never hides the one that is not.
- **The card rail's expiry month must be zero-padded.** A bare `3` for March has been rejected as
  malformed.

## Open questions with AUB

- Whether the **cashier** notification carries a signature. Until answered in writing, it is
  confirmed by inquiry rather than trusted.
- Whether the live gateway returns `00` or `S0000` on refund.
- Whether the wallet rail's `RSA_1_256` mode appends `&key=…` before signing. The spec's prose says
  so, but that reads as copy-paste from the hash section, and an asymmetric signature has no use for
  a shared secret. Signed without it; `SHA256` mode is unaffected.
- The UAT host (`paymentapi-uat.wepayez.com`) appears only in the vendor's demo code, not in the
  guide.
