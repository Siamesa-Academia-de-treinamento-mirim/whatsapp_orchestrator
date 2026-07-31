<?php

declare(strict_types=1);

namespace Chatwoot_plugin\Libraries;

use CodeIgniter\Encryption\EncrypterInterface;
use Config\Encryption;
use Config\Services;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Encrypts credentials with the same encrypter configuration used by Rise.
 *
 * Stored values are versioned so an unencrypted value is never accepted as a
 * valid credential by mistake.
 */
class Credential_cipher
{
    private const STORAGE_PREFIX = 'rise-encrypted:v1:';

    private EncrypterInterface $encrypter;

    public function __construct(?EncrypterInterface $encrypter = null)
    {
        $this->encrypter = $encrypter ?? $this->createRiseEncrypter();
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            throw new InvalidArgumentException('Credential value cannot be empty.');
        }

        try {
            $ciphertext = $this->encrypter->encrypt($plaintext);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to encrypt the credential.', 0, $exception);
        }

        if (!is_string($ciphertext) || $ciphertext === '') {
            throw new RuntimeException('The Rise encrypter returned an invalid credential payload.');
        }

        return self::STORAGE_PREFIX . base64_encode($ciphertext);
    }

    public function decrypt(string $storedValue): string
    {
        if (!$this->isEncrypted($storedValue)) {
            throw new RuntimeException('Credential payload is not encrypted with a supported format.');
        }

        $encodedPayload = substr($storedValue, strlen(self::STORAGE_PREFIX));
        $ciphertext = base64_decode($encodedPayload, true);
        if ($ciphertext === false || $ciphertext === '') {
            throw new RuntimeException('Credential payload is malformed.');
        }

        try {
            $plaintext = $this->encrypter->decrypt($ciphertext);
        } catch (Throwable $exception) {
            throw new RuntimeException('Unable to decrypt the credential.', 0, $exception);
        }

        if (!is_string($plaintext)) {
            throw new RuntimeException('The Rise encrypter returned an invalid plaintext value.');
        }

        return $plaintext;
    }

    public function isEncrypted(?string $storedValue): bool
    {
        return is_string($storedValue)
            && strncmp($storedValue, self::STORAGE_PREFIX, strlen(self::STORAGE_PREFIX)) === 0;
    }

    public static function generateSecret(int $bytes = 32): string
    {
        if ($bytes < 16) {
            throw new InvalidArgumentException('Secrets must contain at least 128 bits of entropy.');
        }

        return bin2hex(random_bytes($bytes));
    }

    private function createRiseEncrypter(): EncrypterInterface
    {
        $appConfig = config('App');
        $key = isset($appConfig->encryption_key) ? (string) $appConfig->encryption_key : '';
        if ($key === '') {
            throw new RuntimeException('Rise encryption key is not configured.');
        }

        $config = new Encryption();
        $config->key = $key;
        $config->driver = 'OpenSSL';

        return Services::encrypter($config, false);
    }
}
