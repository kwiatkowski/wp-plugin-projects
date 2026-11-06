<?php

declare(strict_types=1);

namespace MKProjects\Domain;

/**
 * The projects' domains as their registries tell (RDAP): who the registrar is and until when the domain is paid – the
 * list warns 30 days before. Asked about the registered domain (en.meden.com.pl → meden.com.pl), once a day each, a
 * few per pull. The registry's address comes from IANA's bootstrap; .co isn't in it (its own address below); .eu has
 * no RDAP and doesn't publish the date at all – only the registrar, from its WHOIS.
 *
 * Kept in one option: { domain: { registrar, expires, checkedAt, error } }.
 */
final class DomainChecker
{
    private const OPTION = 'wp_plugin_projects_domains';
    private const BOOTSTRAP = 'mk_projects_rdap_bootstrap';
    /** the registries IANA's bootstrap lacks */
    private const EXTRA = ['co' => 'https://rdap.registry.co/co/'];
    /** the second-level suffixes a domain is registered under – meden.com.pl, not com.pl */
    private const SECOND_LEVEL = ['com.pl', 'net.pl', 'org.pl', 'info.pl', 'biz.pl', 'edu.pl', 'waw.pl', 'co.uk', 'org.uk', 'com.ua', 'com.de'];
    /** asked again after a day; after a failure – after six hours */
    private const FRESH = DAY_IN_SECONDS;
    private const RETRY = 6 * HOUR_IN_SECONDS;
    /** at most so many asked in one pull – the rest at the next ones */
    private const PER_RUN = 6;

    /** "en.meden.com.pl" → "meden.com.pl" */
    public static function registered(string $host): string
    {
        $parts = explode('.', strtolower(trim($host, '.')));
        $count = count($parts);

        if ($count <= 2) {
            return implode('.', $parts);
        }

        $last = implode('.', array_slice($parts, -2));

        return in_array($last, self::SECOND_LEVEL, true) ? implode('.', array_slice($parts, -3)) : $last;
    }

    /** @return array<string, array{registrar: string|null, expires: string|null, checkedAt: string, error: string|null}> */
    public static function known(): array
    {
        $known = get_option(self::OPTION, []);

        return is_array($known) ? $known : [];
    }

    /**
     * What the registries said about these hosts' domains – each registered domain once.
     *
     * @param string[] $hosts
     * @return array<int, array{domain: string, registrar: string|null, expires: string|null, checkedAt: string, error: string|null}>
     */
    public static function forHosts(array $hosts, ?array $known = null): array
    {
        $known ??= self::known();
        $domains = array_values(array_unique(array_map([self::class, 'registered'], array_filter($hosts))));

        return array_values(array_filter(array_map(
            static fn (string $domain): ?array => isset($known[$domain]) ? ['domain' => $domain] + $known[$domain] : null,
            $domains,
        )));
    }

    /**
     * Asks about the domains not asked today (a few per run).
     *
     * @param string[] $hosts every project's domains
     */
    public function refresh(array $hosts): void
    {
        $known = self::known();
        $domains = array_values(array_unique(array_map([self::class, 'registered'], array_filter($hosts))));
        $due = array_filter($domains, static function (string $domain) use ($known): bool {
            $last = $known[$domain] ?? null;

            return !$last || time() - (int) strtotime((string) $last['checkedAt']) > ($last['error'] ? self::RETRY : self::FRESH);
        });

        foreach (array_slice(array_values($due), 0, self::PER_RUN) as $domain) {
            $known[$domain] = $this->check($domain);
        }

        // the domains no project has any more go
        update_option(self::OPTION, array_intersect_key($known, array_flip($domains)), false);
    }

    /** @return array{registrar: string|null, expires: string|null, checkedAt: string, error: string|null} */
    public function check(string $domain): array
    {
        $result = ['registrar' => null, 'expires' => null, 'checkedAt' => gmdate(DATE_ATOM), 'error' => null];
        $tld = substr((string) strrchr($domain, '.'), 1);

        if ($tld === 'eu') {
            return ['registrar' => $this->euRegistrar($domain), 'error' => 'rejestr .eu nie podaje daty wygaśnięcia'] + $result;
        }

        $base = $this->registry($tld);

        if ($base === null) {
            return ['error' => "brak RDAP dla .$tld"] + $result;
        }

        $response = wp_remote_get(trailingslashit($base) . 'domain/' . rawurlencode($domain), ['timeout' => 10, 'headers' => ['Accept' => 'application/rdap+json']]);
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);

        if (is_wp_error($response) || $code !== 200 || !is_array($body)) {
            return ['error' => $code === 404 ? 'rejestr nie zna domeny' : 'rejestr nie odpowiada'] + $result;
        }

        foreach ((array) ($body['events'] ?? []) as $event) {
            if (($event['eventAction'] ?? '') === 'expiration' && is_string($event['eventDate'] ?? null)) {
                $result['expires'] = gmdate(DATE_ATOM, (int) strtotime($event['eventDate']));
            }
        }

        foreach ((array) ($body['entities'] ?? []) as $entity) {
            if (in_array('registrar', (array) ($entity['roles'] ?? []), true)) {
                foreach ((array) ($entity['vcardArray'][1] ?? []) as $field) {
                    if (($field[0] ?? '') === 'fn' && is_string($field[3] ?? null)) {
                        $result['registrar'] = $field[3];
                    }
                }
            }
        }

        return $result;
    }

    /** the registry's RDAP address for a TLD – IANA's bootstrap, kept a week */
    private function registry(string $tld): ?string
    {
        if (isset(self::EXTRA[$tld])) {
            return self::EXTRA[$tld];
        }

        $map = get_transient(self::BOOTSTRAP);

        if (!is_array($map)) {
            $response = wp_remote_get('https://data.iana.org/rdap/dns.json', ['timeout' => 10]);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            $map = [];

            foreach ((array) ($body['services'] ?? []) as [$tlds, $urls]) {
                foreach ((array) $tlds as $name) {
                    $map[$name] = (string) ($urls[0] ?? '');
                }
            }

            if ($map) {
                set_transient(self::BOOTSTRAP, $map, WEEK_IN_SECONDS);
            }
        }

        return ($map[$tld] ?? '') ?: null;
    }

    /** .eu: the registrar's name from EURid's WHOIS (the date it doesn't give) */
    private function euRegistrar(string $domain): ?string
    {
        $socket = @fsockopen('whois.eu', 43, $errno, $errstr, 8);

        if (!$socket) {
            return null;
        }

        stream_set_timeout($socket, 8);
        fwrite($socket, $domain . "\r\n");
        $answer = (string) stream_get_contents($socket);
        fclose($socket);

        return preg_match('/^Registrar:\s*\R\s*Name:\s*(.+)$/mi', $answer, $match) ? trim($match[1]) : null;
    }
}
