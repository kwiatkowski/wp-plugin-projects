<?php

declare(strict_types=1);

namespace MKProjects\Domain;

use WP_Error;

/**
 * The list's one viewer account – not a WordPress user: a login and a password the owner sets (the password kept as a
 * hash). Logged in, one sees the columns the owner chose for viewers. The session is a signed cookie (30 days) –
 * signed with the password's hash too, so a new password ends every session.
 */
final class Viewer
{
    private const OPTION = 'wp_plugin_projects_viewer';
    private const COOKIE = 'mk_projects_viewer';
    private const DAYS = 30;
    /** failed logins from one address before it waits */
    private const TRIES = 5;
    private const WAIT = 15 * MINUTE_IN_SECONDS;

    private static ?bool $active = null;

    /** @return array{login: string, hash: string}|null */
    public static function account(): ?array
    {
        $account = get_option(self::OPTION);

        return is_array($account) && is_string($account['login'] ?? null) && $account['login'] !== '' && is_string($account['hash'] ?? null) && $account['hash'] !== ''
            ? ['login' => $account['login'], 'hash' => $account['hash']]
            : null;
    }

    /**
     * Sets the account: an empty login removes it; a password only when given (the old one stays otherwise – a new
     * account needs one). Returns the login kept, null – none.
     */
    public static function setAccount(string $login, ?string $password): string|null|WP_Error
    {
        $login = trim(sanitize_text_field($login));

        if ($login === '') {
            delete_option(self::OPTION);

            return null;
        }

        $hash = $password !== null && $password !== '' ? wp_hash_password($password) : (self::account()['hash'] ?? '');

        if ($hash === '') {
            return new WP_Error('projects_viewer_password', __('Podaj hasło dla konta.', 'wp-plugin-projects'), ['status' => 400]);
        }

        update_option(self::OPTION, ['login' => mb_substr($login, 0, 100), 'hash' => $hash], false);

        return self::account()['login'] ?? null;
    }

    /** Checks the login and the password, then sets the session cookie. */
    public static function login(string $login, string $password): true|WP_Error
    {
        $account = self::account();
        $key = 'wp_plugin_projects_viewer_fails_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $fails = (int) get_transient($key);

        if ($fails >= self::TRIES) {
            return new WP_Error('projects_viewer_wait', __('Za dużo prób – spróbuj za kilkanaście minut.', 'wp-plugin-projects'), ['status' => 429]);
        }

        if (!$account || !hash_equals($account['login'], trim($login)) || !wp_check_password($password, $account['hash'])) {
            set_transient($key, $fails + 1, self::WAIT);

            return new WP_Error('projects_viewer_login', __('Zły login lub hasło.', 'wp-plugin-projects'), ['status' => 401]);
        }

        delete_transient($key);
        $expires = time() + self::DAYS * DAY_IN_SECONDS;
        self::cookie($expires . '.' . self::sign($expires, $account['hash']), $expires);
        self::$active = true;

        return true;
    }

    public static function logout(): void
    {
        self::cookie('', time() - YEAR_IN_SECONDS);
        self::$active = false;
    }

    /** whether this request comes with a valid viewer session */
    public static function active(): bool
    {
        if (self::$active !== null) {
            return self::$active;
        }

        $account = self::account();
        [$expires, $signature] = array_pad(explode('.', (string) ($_COOKIE[self::COOKIE] ?? ''), 2), 2, '');

        return self::$active = $account !== null && ctype_digit($expires) && (int) $expires > time()
            && hash_equals(self::sign((int) $expires, $account['hash']), $signature);
    }

    private static function sign(int $expires, string $hash): string
    {
        return hash_hmac('sha256', $expires . '|' . $hash, wp_salt('auth'));
    }

    private static function cookie(string $value, int $expires): void
    {
        if (!headers_sent()) {
            // the page (/projects) and its REST (/wp-json) – the whole site
            setcookie(self::COOKIE, $value, ['expires' => $expires, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        }
    }
}
