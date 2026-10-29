<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * Encrypts the list's secrets (the passwords, keys, webhooks) with libsodium.
 *
 * The key is the list's own: PROJECTS_SECRET_KEY – a constant or the environment (Bedrock's .env), "base64:" and 32
 * random bytes (`wp projects-secrets key` makes one). It is kept apart from WordPress's salts on purpose: salts get
 * rotated (after an incident, by some plugins) and a key taken from them would lose every secret with them.
 * Without the key – and for what was saved before it – the old key from the AUTH salt still reads; `wp projects-secrets
 * rekey` moves everything onto the new one.
 */
final class Secrets
{
    public const ENV = 'PROJECTS_SECRET_KEY';

    /** "nonce + box", base64 – what goes into the database */
    public function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key() ?? $this->legacyKey()));
    }

    /** The plain text; null – nothing stored, or no key opens it */
    public function decrypt(string $stored): ?string
    {
        $raw = base64_decode($stored, true);

        if (!$raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        foreach (array_filter([$this->key(), $this->legacyKey()]) as $key) {
            $plain = sodium_crypto_secretbox_open($box, $nonce, $key);

            if ($plain !== false) {
                return $plain;
            }
        }

        return null;
    }

    /** Whether the list's own key is set – else the secrets still hang on the AUTH salt */
    public function configured(): bool
    {
        return $this->key() !== null;
    }

    /** Whether this value is encrypted with the old key – rekey() moves it */
    public function legacy(string $stored): bool
    {
        $key = $this->key();
        $raw = base64_decode($stored, true);

        if ($key === null || !$raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return false;
        }

        return sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key) === false
            && $this->decrypt($stored) !== null;
    }

    /** A new key for the .env */
    public static function generate(): string
    {
        return 'base64:' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    private function key(): ?string
    {
        $value = defined(self::ENV) ? (string) constant(self::ENV) : (string) ($_ENV[self::ENV] ?? $_SERVER[self::ENV] ?? getenv(self::ENV) ?: '');
        $key = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : false;

        return is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $key : null;
    }

    /** the key before PROJECTS_SECRET_KEY – from the site's AUTH salt */
    private function legacyKey(): string
    {
        return sodium_crypto_generichash(wp_salt('auth') . '|wp-plugin-projects|password', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
