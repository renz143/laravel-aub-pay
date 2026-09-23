# Changelog

## Unreleased

First release. Extracted from the AUB Card Cashier integration in `prycegas-api`
(`app/Support/Aub/Signer.php`, `app/Http/Clients/AubPaymentClient.php`, `config/aub.php`,
`app/Http/Controllers/API/AubWebhookController.php`) and widened to cover all three of AUB's APIs.

### Added

- **Cashier rail** — hosted payment page: `createOrder()`, `inquire()`, `refund()`. Ported from the
  production implementation, including the response-code-before-signature ordering and the
  `00`/`S0000` success handling.
- **Wallet rail** — GCash, GrabPay, QRPh, Instapay, Alipay+, WeChat, UnionPay over the XML gateway:
  `charge()`, `query()`, `refund()`, `refundQuery()`, `close()`. New.
- **Card rail** — the direct `/online/v1/*` API with 3-D Secure, pre-authorization, capture, reverse
  and unfreeze. New, and off by default: it carries a PCI-DSS SAQ-D consequence.
- **JWE** (RSA-OAEP-256 + A256CBC-HS512) for PAN/CVV, which the previous implementation deliberately
  omitted because the Cashier rail never needs it.
- Webhook endpoints for both notification formats, each answering in the exact form its gateway
  expects (`SUCCESS` for cashier, `success` for wallet), with `PaymentSucceeded` / `PaymentFailed` /
  `PaymentPending` / `NotificationReceived` events.
- `ResponseCode` enum over the §6.1 table, sorting codes into configuration faults, request faults,
  declines and indeterminate outcomes.
- **QR Ph (`InstapayQrV2`) support to the spec (§5.4)**:
  - `WalletCharge` gains `expirationDate`. Its time fields accept a `DateTimeInterface` and convert it
    to the gateway's GMT+8.
  - `WalletChargeResult` gains `invoiceId`, `expiresAt` and `uuid`, and fills in the `out_trade_no`
    the QR Ph reply leaves out.
  - `query(invoiceId: …)` goes to `pay.instapay.query` (`WalletService::InstapayQuery`).
- `Testing\AubPayFake` for correctly-signed fake responses.
- `aub-pay:ping` connectivity check.

### Fixed

- **`guzzlehttp/guzzle` is now a declared dependency.** The package has always driven the HTTP
  client through `Illuminate\Support\Facades\Http`, which cannot send a request without Guzzle, but
  never required it. Laravel 12's `laravel/framework` requires Guzzle itself, so on Laravel 12 it
  was always present and the omission was invisible; Laravel 10 and 11 only *suggest* it, so the
  package could install against them and then fail at the first call. This is what actually stood
  between the package and the Laravel 10 support its constraints already advertised.
- **Removed the `config.platform.php` pin of `8.3.0`.** It resolved the tree as though PHP were
  always 8.3 regardless of the declared `^8.1` floor, which let an 8.2-only dependency
  (`web-token/jwt-library` v4) be locked for a package claiming to run on 8.1. Resolution now
  follows the PHP actually in use.
- The JWE interoperability test builds its decrypter from whichever `JWEDecrypter` constructor is
  installed. web-token v3 takes the key- and content-encryption managers separately and v4 merged
  them into one, and the v3 line is what PHP 8.1 resolves to.

### Added

- `scripts/test.sh`, which runs the suite against PHP 8.1/Laravel 10, 8.2/Laravel 11 and
  8.3/Laravel 12. `Dockerfile.test` takes a `PHP_VERSION` build argument and carries Composer, so
  each leg resolves its own tree — the PHP version is what selects the Laravel and web-token
  majors, so a single shared resolution would have tested one combination three times.

### Changed from the `prycegas-api` original

Application-specific behaviour was deliberately left behind, because it is what made the original
unreusable: the PayMongo-shaped return objects, the `reference_number` session id, the `o`/`g`/`p`
PGCM `attach` encoding, and the `OrderService`/Salesforce callbacks. An application wanting the
PayMongo shape writes a thin adapter over `CashierClient`.
