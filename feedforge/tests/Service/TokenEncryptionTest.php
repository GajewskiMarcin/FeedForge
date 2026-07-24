<?php

declare(strict_types=1);

namespace FeedForge\Tests\Service;

use FeedForge\Service\TokenEncryption;
use PHPUnit\Framework\TestCase;

class TokenEncryptionTest extends TestCase
{
    private TokenEncryption $encryption;

    protected function setUp(): void
    {
        $this->encryption = new TokenEncryption();
    }

    public function testEncryptReturnsArrayWithCiphertextAndIv(): void
    {
        $result = $this->encryption->encrypt('test-token');

        $this->assertArrayHasKey('ciphertext', $result);
        $this->assertArrayHasKey('iv', $result);
        $this->assertNotEmpty($result['ciphertext']);
        $this->assertNotEmpty($result['iv']);
    }

    public function testEncryptProducesBase64Output(): void
    {
        $result = $this->encryption->encrypt('hello');

        $this->assertNotFalse(base64_decode($result['ciphertext'], true));
        $this->assertNotFalse(base64_decode($result['iv'], true));
    }

    public function testDecryptRoundTrip(): void
    {
        $plaintext = 'my-secret-oauth-token-1234567890';
        $encrypted = $this->encryption->encrypt($plaintext);
        $decrypted = $this->encryption->decrypt($encrypted['ciphertext'], $encrypted['iv']);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testDecryptRoundTripWithSpecialCharacters(): void
    {
        $plaintext = '{"access_token":"ya29.a0A...","refresh_token":"1//0gB...","expires_in":3600}';
        $encrypted = $this->encryption->encrypt($plaintext);
        $decrypted = $this->encryption->decrypt($encrypted['ciphertext'], $encrypted['iv']);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testDecryptRoundTripWithUnicode(): void
    {
        $plaintext = 'token-with-ąčęė-unicode';
        $encrypted = $this->encryption->encrypt($plaintext);
        $decrypted = $this->encryption->decrypt($encrypted['ciphertext'], $encrypted['iv']);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testDecryptRoundTripWithEmptyString(): void
    {
        $plaintext = '';
        $encrypted = $this->encryption->encrypt($plaintext);
        $decrypted = $this->encryption->decrypt($encrypted['ciphertext'], $encrypted['iv']);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testDifferentEncryptionsProduceDifferentIvs(): void
    {
        $plaintext = 'same-token';
        $result1 = $this->encryption->encrypt($plaintext);
        $result2 = $this->encryption->encrypt($plaintext);

        // IVs should be random each time
        $this->assertNotSame($result1['iv'], $result2['iv']);
        // Ciphertexts should differ due to different IVs
        $this->assertNotSame($result1['ciphertext'], $result2['ciphertext']);

        // Both should decrypt to the same value
        $this->assertSame($plaintext, $this->encryption->decrypt($result1['ciphertext'], $result1['iv']));
        $this->assertSame($plaintext, $this->encryption->decrypt($result2['ciphertext'], $result2['iv']));
    }

    public function testDecryptWithInvalidBase64ThrowsException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid base64 input for decryption');

        $this->encryption->decrypt('not-valid-base64!!!', 'also-invalid!!!');
    }

    public function testDecryptWithWrongIvThrowsException(): void
    {
        $encrypted = $this->encryption->encrypt('test');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed');

        // Use a different random IV
        $wrongIv = base64_encode(openssl_random_pseudo_bytes(16));
        $this->encryption->decrypt($encrypted['ciphertext'], $wrongIv);
    }

    public function testDecryptWithTamperedCiphertextThrowsException(): void
    {
        $encrypted = $this->encryption->encrypt('test-data');

        $this->expectException(\RuntimeException::class);

        // Tamper with ciphertext
        $raw = base64_decode($encrypted['ciphertext'], true);
        $tampered = base64_encode($raw . 'tampered');
        $this->encryption->decrypt($tampered, $encrypted['iv']);
    }

    public function testLargePayloadRoundTrip(): void
    {
        $plaintext = str_repeat('A', 10000);
        $encrypted = $this->encryption->encrypt($plaintext);
        $decrypted = $this->encryption->decrypt($encrypted['ciphertext'], $encrypted['iv']);

        $this->assertSame($plaintext, $decrypted);
    }
}
