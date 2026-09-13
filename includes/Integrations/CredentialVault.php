<?php
declare(strict_types=1);
namespace DigiForge\Integrations;

/** Encrypts integration secrets at rest using a dedicated stable DigiForge master key. */
final class CredentialVault {
    private const MANAGED_KEY_OPTION = 'digiforge_credential_key_envelope';
    private const MANAGED_KEY_CONTEXT = 'digiforge:credential-master:v1';

    private static function masterKey(): string {
        if (defined('DIGIFORGE_CREDENTIAL_KEY') && is_string(DIGIFORGE_CREDENTIAL_KEY) && strlen(DIGIFORGE_CREDENTIAL_KEY) >= 32) {
            return DIGIFORGE_CREDENTIAL_KEY;
        }

        return self::managedMasterKey();
    }

    private static function managedMasterKey(): string {
        $stored = get_option(self::MANAGED_KEY_OPTION, '');
        if (is_string($stored) && $stored !== '') {
            return self::unwrapManagedKey($stored);
        }

        $master = random_bytes(32);
        $envelope = self::wrapManagedKey($master);

        if (! add_option(self::MANAGED_KEY_OPTION, $envelope, '', false)) {
            $stored = get_option(self::MANAGED_KEY_OPTION, '');
            if (! is_string($stored) || $stored === '') {
                throw new \RuntimeException('Unable to initialize the managed DigiForge credential key.');
            }
            return self::unwrapManagedKey($stored);
        }

        return $master;
    }

    private static function wrappingKey(): string {
        if (! function_exists('wp_salt')) {
            throw new \RuntimeException('WordPress salt infrastructure is unavailable.');
        }
        $material = wp_salt('auth') . "\0" . wp_salt('secure_auth');
        if ($material === "\0") {
            throw new \RuntimeException('WordPress salts are unavailable.');
        }
        if (function_exists('hash_hkdf')) {
            return hash_hkdf('sha256', $material, 32, 'digiforge:credential-wrap', 'digiforge-managed-key-v1');
        }
        return hash_hmac('sha256', 'digiforge:credential-wrap', $material, true);
    }

    private static function wrapManagedKey(string $master): string {
        $key = self::wrappingKey();
        $aad = self::MANAGED_KEY_CONTEXT;
        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($master, $aad, $nonce, $key);
            return 'sodium:v1:' . base64_encode($nonce . $cipher);
        }
        if (! function_exists('openssl_encrypt')) {
            throw new \RuntimeException('No supported encryption backend is available.');
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($master, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($cipher === false) {
            throw new \RuntimeException('Managed credential key encryption failed.');
        }
        return 'openssl:v1:' . base64_encode($iv . $tag . $cipher);
    }

    private static function unwrapManagedKey(string $payload): string {
        [$driver, $version, $encoded] = array_pad(explode(':', $payload, 3), 3, '');
        if ($version !== 'v1' || $encoded === '') {
            throw new \RuntimeException('Unsupported managed credential key payload.');
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false) {
            throw new \RuntimeException('Invalid managed credential key payload.');
        }
        $key = self::wrappingKey();
        $aad = self::MANAGED_KEY_CONTEXT;
        if ($driver === 'sodium') {
            $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if (strlen($raw) <= $n) {
                throw new \RuntimeException('Invalid managed credential key payload.');
            }
            $master = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $n), $aad, substr($raw, 0, $n), $key);
        } elseif ($driver === 'openssl') {
            if (strlen($raw) <= 28) {
                throw new \RuntimeException('Invalid managed credential key payload.');
            }
            $master = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        } else {
            throw new \RuntimeException('Unsupported managed credential key encryption driver.');
        }
        if (! is_string($master) || strlen($master) !== 32) {
            throw new \RuntimeException('Managed credential key decryption failed.');
        }
        return $master;
    }

    private static function derive(string $purpose): string {
        $ikm = self::masterKey();
        if (function_exists('hash_hkdf')) {
            return hash_hkdf('sha256', $ikm, 32, 'digiforge:' . $purpose, 'digiforge-integration-v1');
        }
        return hash_hmac('sha256', 'digiforge:' . $purpose, $ikm, true);
    }

    private static function aad(string $context): string {
        if ($context === '') { throw new \InvalidArgumentException('Credential context is required.'); }
        return 'digiforge:integration:v1:' . $context;
    }

    public static function encrypt(string $plaintext, string $context): string {
        if ($plaintext === '') { throw new \InvalidArgumentException('Secret cannot be empty.'); }
        $key = self::derive('encryption');
        $aad = self::aad($context);
        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $aad, $nonce, $key);
            return 'sodium:v1:' . base64_encode($nonce . $cipher);
        }
        if (! function_exists('openssl_encrypt')) { throw new \RuntimeException('No supported encryption backend is available.'); }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad);
        if ($cipher === false) { throw new \RuntimeException('Credential encryption failed.'); }
        return 'openssl:v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload, string $context): string {
        [$driver, $version, $encoded] = array_pad(explode(':', $payload, 3), 3, '');
        if ($version !== 'v1' || $encoded === '') { throw new \RuntimeException('Unsupported credential payload.'); }
        $raw = base64_decode($encoded, true); if ($raw === false) { throw new \RuntimeException('Invalid credential payload.'); }
        $key = self::derive('encryption');
        $aad = self::aad($context);
        if ($driver === 'sodium') {
            $n = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
            if (strlen($raw) <= $n) { throw new \RuntimeException('Invalid credential payload.'); }
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw, $n), $aad, substr($raw, 0, $n), $key);
        } elseif ($driver === 'openssl') {
            if (strlen($raw) <= 28) { throw new \RuntimeException('Invalid credential payload.'); }
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        } else { throw new \RuntimeException('Unsupported credential encryption driver.'); }
        if (! is_string($plain)) { throw new \RuntimeException('Credential decryption failed.'); }
        return $plain;
    }

    public static function fingerprint(string $plaintext, string $context): string {
        return substr(hash_hmac('sha256', self::aad($context) . "\0" . $plaintext, self::derive('fingerprint')), 0, 16);
    }
}
