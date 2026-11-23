<?php

declare(strict_types=1);

namespace App\Modules\Payment\Providers\Telebirr;

use App\Helpers\TelebirrHelper;
use phpseclib3\Crypt\RSA;
use RuntimeException;
use Throwable;

final class TelebirrSignatureVerifier
{
    private string $privateKeyPem;

    public function __construct()
    {
        $this->privateKeyPem = trim(
            (string) TelebirrHelper::loadPrivateKey()
        );

        if ($this->privateKeyPem === '') {
            throw new RuntimeException(
                'Telebirr merchant private key is not configured.'
            );
        }
    }

    /**
     * Verify a callback only when the documented callback protocol
     * establishes that verification with this key is supported.
     *
     * A merchant private key cannot verify a signature created by
     * Telebirr. Do not treat this method as callback authentication.
     */
    public function verify(array $callbackData): bool
    {
        // No valid provider-signature verification can be performed
        // with the merchant private key alone.
        return false;
    }

    /**
     * Sign data using the merchant private key.
     *
     * This is for merchant-side signing, not verification of
     * Telebirr-generated callback signatures.
     */
    public function sign(string $payload): string
    {
        if ($payload === '') {
            throw new RuntimeException(
                'Cannot sign an empty Telebirr payload.'
            );
        }

        try {
            $privateKey = RSA::loadPrivateKey(
                $this->privateKeyPem
            )
                ->withHash('sha256')
                ->withPadding(RSA::SIGNATURE_PKCS1);

            return base64_encode(
                $privateKey->sign($payload)
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Unable to sign the Telebirr payload.',
                previous: $exception,
            );
        }
    }
}
