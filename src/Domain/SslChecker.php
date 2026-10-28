<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * The site's TLS certificate as a visitor's browser meets it: who issued it, until when it is valid, whether it covers
 * the domain and is trusted (the chain against WordPress's CA bundle). The list checks every project with a domain at
 * each pull – the site needs nothing for it.
 */
final class SslChecker
{
    private const TIMEOUT = 8;

    /**
     * @return array{ok: bool, error: string|null, validTo: string|null, issuer: string|null, checkedAt: string}
     */
    public function check(string $host): array
    {
        $result = ['ok' => false, 'error' => null, 'validTo' => null, 'issuer' => null, 'checkedAt' => gmdate(DATE_ATOM)];

        // first without verifying – an expired or foreign certificate still shows what it is
        $certificate = $this->certificate($host, false);

        if ($certificate === null) {
            return ['error' => 'brak połączenia HTTPS'] + $result;
        }

        $info = openssl_x509_parse($certificate) ?: [];
        $validTo = (int) ($info['validTo_time_t'] ?? 0);
        $issuer = (array) ($info['issuer'] ?? []);
        $result['validTo'] = $validTo ? gmdate(DATE_ATOM, $validTo) : null;
        $result['issuer'] = (string) ($issuer['O'] ?? $issuer['CN'] ?? '') ?: null;

        $result['error'] = match (true) {
            $validTo < time() => 'certyfikat wygasł',
            !$this->covers($info, $host) => 'certyfikat nie obejmuje domeny',
            $this->certificate($host, true) === null => 'certyfikat niezaufany (łańcuch)',
            default => null,
        };
        $result['ok'] = $result['error'] === null;

        return $result;
    }

    /** @return \OpenSSLCertificate|null the peer's certificate – with `verify` only when the chain and the name check out */
    private function certificate(string $host, bool $verify): ?\OpenSSLCertificate
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'SNI_enabled' => true,
            'peer_name' => $host,
            'cafile' => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
        ]]);

        $client = @stream_socket_client("ssl://$host:443", $errno, $error, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);

        if (!is_resource($client)) {
            return null;
        }

        $certificate = stream_context_get_params($client)['options']['ssl']['peer_certificate'] ?? null;
        fclose($client);

        return $certificate instanceof \OpenSSLCertificate ? $certificate : null;
    }

    /** the subject's names (SAN, else CN) include the host – a wildcard covers one level */
    private function covers(array $info, string $host): bool
    {
        $names = [];

        foreach (explode(',', (string) ($info['extensions']['subjectAltName'] ?? '')) as $entry) {
            if (preg_match('/^\s*DNS:(.+)$/', $entry, $match)) {
                $names[] = strtolower(trim($match[1]));
            }
        }

        if (!$names && !empty($info['subject']['CN'])) {
            $names[] = strtolower((string) $info['subject']['CN']);
        }

        $host = strtolower($host);

        foreach ($names as $name) {
            if ($name === $host || (str_starts_with($name, '*.') && substr($host, strpos($host, '.') ?: 0) === substr($name, 1))) {
                return true;
            }
        }

        return false;
    }
}
