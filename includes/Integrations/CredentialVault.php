<?php
declare(strict_types=1);
namespace DigiForge\Integrations;

/** Encrypts integration secrets at rest and never exposes plaintext through persistence APIs. */
final class CredentialVault {
    private static function key(): string {
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . '|' . (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '') . '|' . (defined('LOGGED_IN_SALT') ? LOGGED_IN_SALT : '');
        if ($material === '||') { throw new \RuntimeException('WordPress authentication keys are required for DigiForge credential encryption.'); }
        return hash('sha256', $material . '|digiforge-integration-v1', true);
    }

    public static function encrypt(string $plaintext): string {
        if ($plaintext === '') { throw new \InvalidArgumentException('Secret cannot be empty.'); }
        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, 'digiforge', $nonce, self::key());
            return 'sodium:v1:' . base64_encode($nonce . $cipher);
        }
        if (! function_exists('openssl_encrypt')) { throw new \RuntimeException('No supported encryption backend is available.'); }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'digiforge');
        if ($cipher === false) { throw new \RuntimeException('Credential encryption failed.'); }
        return 'openssl:v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): string {
        [$driver, $version, $encoded] = array_pad(explode(':', $payload, 3), 3, '');
        if ($version !== 'v1' || $encoded === '') { throw new \RuntimeException('Unsupported credential payload.'); }
        $raw = base64_decode($encoded, true);
        if ($raw === false) { throw new \RuntimeException('Invalid credential payload.'); }
        if ($driver === 'sodium') {
            $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $n), 'digiforge', substr($raw, 0, $n), self::key());
        } elseif ($driver === 'openssl') {
            $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
            $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, 'digiforge');
        } else { throw new \RuntimeException('Unsupported credential encryption driver.'); }
        if (! is_string($plain)) { throw new \RuntimeException('Credential decryption failed.'); }
        return $plain;
    }

    public static function fingerprint(string $plaintext): string { return substr(hash('sha256', $plaintext), 0, 16); }
}
