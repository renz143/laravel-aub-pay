<?php

namespace Prycegas\AubPay\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Prycegas\AubPay\AubPayServiceProvider;
use Prycegas\AubPay\Crypto\JwsSigner;

abstract class TestCase extends Orchestra
{
    /**
     * The vendor's own published sample keypair, taken from the Java reference implementation
     * shipped with the API documentation (card-demo, `JwtUtilis::main`). It is used here purely as
     * a fixture: it is printed in the vendor's sample source, it is not AUB's key, and it is not
     * ours. A real merchant key is generated at onboarding and lives only in the environment.
     *
     * Using it for both halves lets a faked response be signed the way AUB would sign one.
     */
    public const PRIVATE_KEY = 'MIIEvQIBADANBgkqhkiG9w0BAQEFAASCBKcwggSjAgEAAoIBAQC07M5NEVm1qQnB0ENtK6F4xbEqFXYSiRdo4zqvrqEAM/UE/asbqRcSuuIv09xHUy3hqC19wad1edpLdfYfEWR7O/2811RqmjwNh0DOSdaFnev2us1d4Tm/4q5L96yhwl8GQq8yBjzbHii3M8ShxTWb8s0kZh5O4pC7RuMcK+tZ+4GtDbJ4AVqy7kCwuPzCTVZfJUjCoHp96epVN/BfxpW22vIsOzcFSXAFcyCh6FgKib7b51S5ke2Ow3MLZBb7HIV786NuC4iyFqmQ1q1vo5c1ARuPQLWlywweme0GDPmlwKi3kbg4Q/u8/cKe1Vji4KyIw0VinMHA2yn4PaUA61WjAgMBAAECggEAAqn9+6qGvKMJjr4HUCM1VHbsvebk/y7fll7KLW4P1tXtBK7LhzO9MDxqOjQPaUlzQoBccx6X3aX2refFwP1bkmj2uoIdLxioS7azIJZ7vyntIIvtnDVFyWqIEHxMIXGgPpJazAzFdqCCDviHK66gtHQlyyRpy9WQNgG8NFz9MSz5i1Jvg/LleigqWKsehxHMYrcKXTkfBtVWkoxS6pJlynH8HEFImm/1aDLKo05SqdxSiNuGayqLsROlRp9onPllH6SQlFi9nfhQEnK+boKAv0nM5TbS+jugtCwighG3+OJaub874Q5JLCqqN6swfD3bons7Rx962eQvUMxi/ZTVuQKBgQDe9Nl8T1tHh7LRd3sealpwpTdDWLgYi7w5FwsbyrAar/Axa3lRhKDLfe3ka98BI54hnw5e71ILw3sA8WA0flpf0N+0Rs+i8Fmcz9jPj2YzDt5IkJg6WaQESj163vV8sxMLCZNXab2jzCEkVv0xNTFc4H6zfTorrNiVWD4SUYoIpQKBgQDPvT/tXmRvvTvU3y/BpIeZ+99o01zGxC/45mSurJ1qFyiupmPCB0ZDZLcm9mDkQA9SpRaywT1EUKHA0fD8piQC5+kqEe0eEu8tcoQh/KSE1ZwCvgDFS/3tno0T7CNqN9S3YqnZ9xHSpdRK5IUUdq4EfduUuFnWtY8gb0POpeBKpwKBgFeLIp5p9nhmsvMGjCRMNEjIxqM+AcM5kuWDw8vc0TsZXCG7hn5Yql5civ1G0eB7oMqozpa+N6QA1JpxLIpQFqJKvJvntf3PjBBDmGkfcEyaCPPLOsqmif1ZPTyysQeOtOp/jwgir+DR9S10rqQUs9Y5G+bUQ/QEQWKarHy64Y01AoGBALELy2XToqmQj2N2606PmHnlrZu7N0C3h2MLiBdOScJXBncCm9aLOJjLR0TPifg9mFGJHXUvN7X3OkQJKOdJ+Tr4x0DxkjKlVG5ZQL9ugBAttQ6pPCLqBvnyvK2T/QLTnljEn5mB9hCe//TsGXc9RkXRtchj7T0N83NjIFkICcXVAoGAHd4v4j2qwTl400GWwRk9NsVRLxmaTacsS1KneNMk6j2HnryMQbvTeQp5TOSrpDIvozxuNVFIbngRdgJ+kXIPrS02ZL6mDMkGEB7FHSL09DMf/TmPWOlbt3EJ/BA+4/VSdc7czJQ4ajIy/bXDOu2CGLKPQ89756vZKW0GTmVede4=';

    public const PUBLIC_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAtOzOTRFZtakJwdBDbSuheMWxKhV2EokXaOM6r66hADP1BP2rG6kXErriL9PcR1Mt4agtfcGndXnaS3X2HxFkezv9vNdUapo8DYdAzknWhZ3r9rrNXeE5v+KuS/esocJfBkKvMgY82x4otzPEocU1m/LNJGYeTuKQu0bjHCvrWfuBrQ2yeAFasu5AsLj8wk1WXyVIwqB6fenqVTfwX8aVttryLDs3BUlwBXMgoehYCom+2+dUuZHtjsNzC2QW+xyFe/OjbguIshapkNatb6OXNQEbj0C1pcsMHpntBgz5pcCot5G4OEP7vP3CntVY4uCsiMNFYpzBwNsp+D2lAOtVowIDAQAB';

    protected function getPackageProviders($app): array
    {
        return [AubPayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('aub-pay', array_merge(
            require __DIR__ . '/../config/aub-pay.php',
            [
                'base_url' => 'https://paymentapi-uat.wepayez.com/gateway/payment',
                'merchant_id' => '800580000004',
                'private_key' => self::PRIVATE_KEY,
                'jws_public_key' => self::PUBLIC_KEY,
                'notify_url' => 'https://shop.test/aub/cashier/notify',
                'callback_url' => 'https://shop.test/thank-you',
                'validity_period' => 30,
                'timeout' => 5,
                'verify_responses' => true,
                'card' => ['enabled' => false, 'jwe_public_key' => null, 'redirect_url' => null],
                'wallet' => [
                    'enabled' => true,
                    'base_url' => 'https://gateway.wepayez.com/pay/gateway',
                    'mch_id' => '127500000158',
                    'api_key' => '06af0776878e8fbdd45f2ac8a916573e',
                    'sign_type' => 'SHA256',
                    'notify_url' => 'https://shop.test/aub/wallet/notify',
                    'timeout' => 5,
                ],
                'webhooks' => ['register_routes' => true, 'prefix' => 'aub', 'middleware' => []],
            ],
        ));
    }

    protected function signer(): JwsSigner
    {
        return new JwsSigner(self::PRIVATE_KEY, self::PUBLIC_KEY);
    }
}
