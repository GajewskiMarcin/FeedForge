<?php

declare(strict_types=1);

namespace FeedForge\Service;

/**
 * AES-256-CBC encryption for OAuth tokens.
 * Key is derived from PrestaShop's _COOKIE_KEY_ to avoid storing a separate secret.
 */
class TokenEncryption
{
    private const CIPHER = 'aes-256-cbc';
    private string $key;

    public function __construct()
    {
        $this->key = hash('sha256', _COOKIE_KEY_ . '|feedforge_token_v1', true);
    }

    /**
     * Encrypt plaintext and return base64-encoded ciphertext + IV.
     *
     * @return array{ciphertext: string, iv: string}
     */
    public function encrypt(string $plaintext): array
    {
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = openssl_random_pseudo_bytes($ivLength);

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
        ];
    }

    /**
     * Decrypt base64-encoded ciphertext using base64-encoded IV.
     */
    public function decrypt(string $ciphertext, string $iv): string
    {
        $rawCiphertext = base64_decode($ciphertext, true);
        $rawIv = base64_decode($iv, true);

        if ($rawCiphertext === false || $rawIv === false) {
            throw new \RuntimeException('Invalid base64 input for decryption');
        }

        $plaintext = openssl_decrypt(
            $rawCiphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $rawIv
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed - token may be corrupted');
        }

        return $plaintext;
    }
}
