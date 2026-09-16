<?php

namespace Prycegas\AubPay\Crypto;

/**
 * Encrypts one sensitive value — a PAN or a security code — into a JWE compact serialization for
 * the direct card rail.
 *
 * Encrypt-only by design. AUB encrypts nothing back to us: the fields that travel this way go one
 * direction only, and a decrypt method would exist solely to be misused.
 */
interface JweEncrypter
{
    public function encrypt(string $plaintext): string;
}
